<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\ParticipantId;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/einvoicing/webhooks/recommand';

    private FakeTransport $fake;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.transports.recommand', [
            'driver' => 'recommand',
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c_test',
            'webhook_secret' => 'wh-'.self::SECRET_MARK,
        ]);
        $app['config']->set('einvoicing.transports.unsigned', [
            'driver' => 'recommand',
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c_test',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Transports are faked; the webhook secret is still read from the configuration.
        $this->fake = EInvoicing::fake();
    }

    private static function sign(string $raw, string $secret = 'wh-'.self::SECRET_MARK): string
    {
        return 'sha256='.hash_hmac('sha256', $raw, $secret);
    }

    /**
     * Posts the raw body through the full HTTP kernel, global middleware included.
     *
     * @param  array<string, string>  $headers  header name => value
     */
    private function postRaw(string $raw, array $headers, string $uri = self::URI): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $raw);
    }

    private function signed(string $raw, string $uri = self::URI): TestResponse
    {
        return $this->postRaw($raw, ['X-Signature' => self::sign($raw)], $uri);
    }

    private static function assertEmptyResponse(TestResponse $response, int $status): void
    {
        $response->assertStatus($status);
        self::assertSame('', $response->getContent());
        self::assertStringNotContainsString(self::SECRET_MARK, (string) json_encode($response->headers->all()));
    }

    private function receive(string $id): void
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $this->fake->receive(new InboundDocument(new InboundReference(
            $id,
            ParticipantId::parse('0208:0123456789'),
            ParticipantId::parse('9930:DE123456789'),
            PeppolBis3::INVOICE,
            new DateTimeImmutable('2026-11-12 10:00:00'),
        ), $xml));
    }

    private function pending(string $providerId): OutboundMessage
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($xml), 'recommand', 'INV-1');
        $message->state = OutboundState::Pending;
        $message->attempts = 1;
        $message->provider_document_id = $providerId;
        $message->save();

        return $message;
    }

    public function test_signature_is_checked_on_the_raw_body(): void
    {
        $this->receive('d1');
        Event::fake([WebhookReceived::class]);
        // Spaces, an escape and a trailing blank that TrimStrings would remove from parsed input.
        $raw = '{"eventType":"document.received", "documentId":"d1","note":"caf\u00e9 é \/ "}';
        $kernel = $this->app->make(Kernel::class);
        $this->assertTrue($kernel->hasMiddleware(TrimStrings::class) && $kernel->hasMiddleware(ConvertEmptyStringsToNull::class));

        self::assertEmptyResponse($this->signed($raw), 204);

        $row = InboundMessage::query()->where('transport', 'recommand')->where('provider_document_id', 'd1')->sole();
        $this->assertSame(InboundState::Acknowledged, $row->state);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->transport === 'recommand'
            && $event->event->type === 'document.received'
            && $event->event->documentId === 'd1');

        // The same content, re-encoded, no longer matches the signature of the original bytes.
        $reencoded = (string) json_encode(json_decode($raw, true));
        self::assertEmptyResponse($this->postRaw($reencoded, ['X-Signature' => self::sign($raw)]), 401);
    }

    public function test_forged_and_unsigned_are_401(): void
    {
        Event::fake([WebhookReceived::class]);
        $raw = '{"eventType":"document.received","documentId":"d1"}';

        self::assertEmptyResponse($this->postRaw($raw, []), 401);
        self::assertEmptyResponse($this->postRaw($raw, ['X-Signature' => self::sign($raw, 'wh-other')]), 401);
        self::assertEmptyResponse($this->postRaw($raw, ['X-Signature' => 'sha256=nothex']), 401);
        self::assertEmptyResponse($this->postRaw('', ['X-Signature' => self::sign('')]), 401);

        $this->assertSame(0, InboundMessage::query()->count());
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_no_csrf_token_needed(): void
    {
        $route = Route::getRoutes()->getByName('einvoicing.webhook');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        // Only the configured middleware, none by default: no "web" group, session or CSRF check.
        $this->assertSame([], Route::gatherRouteMiddleware($route));

        // No token, no session cookie: accepted, and no cookie is set.
        $this->receive('d1');
        $response = $this->signed('{"eventType":"document.received","documentId":"d1"}');
        self::assertEmptyResponse($response, 204);
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_route_absent_without_secret(): void
    {
        $raw = '{"eventType":"document.received","documentId":"d1"}';

        self::assertEmptyResponse($this->signed($raw, '/einvoicing/webhooks/unsigned'), 404);
        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_unknown_transport_404(): void
    {
        $raw = '{"eventType":"document.received","documentId":"d1"}';

        self::assertEmptyResponse($this->signed($raw, '/einvoicing/webhooks/nowhere'), 404);
        // A dotted name is not resolved as a nested configuration key.
        self::assertEmptyResponse($this->signed($raw, '/einvoicing/webhooks/recommand.webhook_secret'), 404);
        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_event_without_document_id_is_204(): void
    {
        Event::fake([WebhookReceived::class]);

        self::assertEmptyResponse($this->signed('{"eventType":"company.verification","companyId":"c_test"}'), 204);
        self::assertEmptyResponse($this->signed('not json'), 204);

        Event::assertNotDispatched(WebhookReceived::class);
        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_status_webhook_delivers_outbound(): void
    {
        Event::fake([DocumentDelivered::class, DocumentFailed::class]);
        $delivered = $this->pending('doc-1');
        $failed = $this->pending('doc-2');

        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-1","companyId":"c_test","deliveryStatus":"delivered"}'), 204);
        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-2","companyId":"c_test","channel":"peppol","status":"failed","failure":{"category":"recipient_rejected","message":"Refused"}}'), 204);

        $row = OutboundMessage::query()->findOrFail($delivered->id);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $row = OutboundMessage::query()->findOrFail($failed->id);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::RecipientRejected, $row->failure_category);
        $this->assertSame('Refused', $row->failure_message);
        Event::assertDispatchedTimes(DocumentDelivered::class, 1);
        Event::assertDispatchedTimes(DocumentFailed::class, 1);

        // A repeated or late report changes nothing.
        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-1","companyId":"c_test","deliveryStatus":"failed"}'), 204);
        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($delivered->id)->state);
        Event::assertDispatchedTimes(DocumentDelivered::class, 1);
        Event::assertDispatchedTimes(DocumentFailed::class, 1);
        $this->assertSame([], $this->fake->sent());
    }

    public function test_status_for_unknown_document_is_ignored(): void
    {
        Event::fake([DocumentDelivered::class, DocumentFailed::class, WebhookReceived::class]);
        $message = $this->pending('doc-1');
        // The same provider id on another transport is not this transport's document.
        config()->set('einvoicing.transports.other', config('einvoicing.transports.recommand'));

        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-404","companyId":"c_test","deliveryStatus":"delivered"}'), 204);
        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-1","companyId":"c_test","deliveryStatus":"delivered"}', '/einvoicing/webhooks/other'), 204);

        $this->assertSame(OutboundState::Pending, OutboundMessage::query()->findOrFail($message->id)->state);
        Event::assertNotDispatched(DocumentDelivered::class);
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
    }

    public function test_duplicate_received_webhook_fetches_once(): void
    {
        $this->receive('d1');
        $received = 0;
        Event::listen(DocumentReceived::class, function () use (&$received): void {
            $received++;
        });
        $raw = '{"eventType":"document.received","documentId":"d1","companyId":"c_test"}';

        self::assertEmptyResponse($this->signed($raw), 204);
        self::assertEmptyResponse($this->signed($raw), 204);

        $this->assertSame(1, InboundMessage::query()->count());
        $this->assertSame(1, $received);
        $this->assertSame(['d1'], $this->fake->acknowledged());
    }

    public function test_foreign_company_is_ignored(): void
    {
        Event::fake([WebhookReceived::class, DocumentDelivered::class]);
        $message = $this->pending('doc-1');

        self::assertEmptyResponse($this->signed('{"eventType":"document.received","documentId":"d1","companyId":"c_other"}'), 204);
        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-1","companyId":"c_other","deliveryStatus":"delivered"}'), 204);

        $this->assertSame(0, InboundMessage::query()->count());
        $this->assertSame(OutboundState::Pending, OutboundMessage::query()->findOrFail($message->id)->state);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_status_event_keeps_its_delivery_state(): void
    {
        Event::fake([WebhookReceived::class]);

        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-9","deliveryStatus":"delivered"}'), 204);

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->event->deliveryState === DeliveryState::Delivered
            && $event->event->companyId === null);
    }

    #[DefineEnvironment('disableWebhooks')]
    public function test_webhooks_disabled_registers_no_route(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('einvoicing.webhook'));
        $this->receive('d1');

        $this->signed('{"eventType":"document.received","documentId":"d1"}')->assertNotFound();
        $this->assertSame(0, InboundMessage::query()->count());
    }

    /**
     * @param  Application  $app
     */
    protected function disableWebhooks($app): void
    {
        $app['config']->set('einvoicing.webhooks.enabled', false);
    }
}
