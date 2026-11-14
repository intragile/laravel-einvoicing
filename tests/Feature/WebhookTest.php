<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
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
use IntrAgile\EInvoicing\Transport\Webhook\WebhookEvent;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookKind;
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
        $app['config']->set('einvoicing.transports.b2brouter', [
            'driver' => 'b2brouter',
            'api_key' => 'test_'.self::SECRET_MARK,
            'account_id' => '78234',
            'webhook_secret' => 'wh-'.self::SECRET_MARK,
        ]);
        $app['config']->set('einvoicing.transports.einvoicebe', [
            'driver' => 'einvoicebe',
            'api_key' => 'ak-'.self::SECRET_MARK,
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

    /**
     * Posts a B2Brouter notification signed as B2Brouter signs it: an HMAC-SHA256 of
     * "<unix time>.<raw body>" in the X-B2Brouter-Signature header.
     */
    private function signedB2Brouter(string $raw, ?string $deliveryId = null): TestResponse
    {
        $timestamp = (string) time();
        $headers = ['X-B2Brouter-Signature' => 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$raw, 'wh-'.self::SECRET_MARK)];
        if ($deliveryId !== null) {
            $headers['X-B2Brouter-Delivery-Id'] = $deliveryId;
        }

        return $this->postRaw($raw, $headers, '/einvoicing/webhooks/b2brouter');
    }

    /**
     * Posts an e-invoice.be notification. The bodies are written in canonical form (keys sorted,
     * ", " and ": " separators), which is what e-invoice.be signs, so the raw body is signed.
     */
    private function signedEInvoiceBe(string $canonical, string $uri = '/einvoicing/webhooks/einvoicebe'): TestResponse
    {
        return $this->postRaw($canonical, ['X-Signature' => self::sign($canonical)], $uri);
    }

    private static function einvoicebeEvent(string $type, string $documentId, ?string $tenantId = null): string
    {
        return $tenantId === null
            ? sprintf('{"data": {"document_id": "%s"}, "type": "%s"}', $documentId, $type)
            : sprintf('{"data": {"document_id": "%s"}, "tenant_id": "%s", "type": "%s"}', $documentId, $tenantId, $type);
    }

    private function pending(string $providerId, string $transport = 'recommand', OutboundState $state = OutboundState::Pending): OutboundMessage
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($xml), $transport, 'INV-1');
        $message->state = $state;
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
            && $event->event->accountId === null);
    }

    public function test_recommand_webhook_unchanged(): void
    {
        Event::fake([WebhookReceived::class, DocumentDelivered::class]);
        $message = $this->pending('doc-1');

        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-1","companyId":"c_test","deliveryStatus":"delivered"}'), 204);

        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($message->id)->state);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->event instanceof WebhookEvent
            && $event->event->kind === WebhookKind::DeliveryChanged
            && $event->event->accountId === 'c_test'
            && $event->event->type === 'document.sent');
        Event::assertDispatchedTimes(DocumentDelivered::class, 1);
    }

    public function test_b2brouter_signed_webhook_records_state(): void
    {
        Event::fake([WebhookReceived::class, DocumentDelivered::class, DocumentFailed::class]);
        $delivered = $this->pending('4711', 'b2brouter');
        $failed = $this->pending('4712', 'b2brouter');

        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":78234,"state":"sent"}}', 'del-1'), 204);
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":"4712","account_id":"78234","state":"error"}}'), 204);

        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($delivered->id)->state);
        $this->assertSame(OutboundState::Failed, OutboundMessage::query()->findOrFail($failed->id)->state);
        Event::assertDispatchedTimes(DocumentDelivered::class, 1);
        Event::assertDispatchedTimes(DocumentFailed::class, 1);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->transport === 'b2brouter'
            && $event->event->deduplicationKey === 'del-1');

        // A forged signature is refused before anything is read.
        $raw = '{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"state":"error"}}';
        self::assertEmptyResponse($this->postRaw($raw, ['X-B2Brouter-Signature' => 't=1,s='.str_repeat('0', 64)], '/einvoicing/webhooks/b2brouter'), 401);
        // The Recommand scheme is not accepted for a B2Brouter transport.
        self::assertEmptyResponse($this->postRaw($raw, ['X-Signature' => self::sign($raw)], '/einvoicing/webhooks/b2brouter'), 401);
    }

    public function test_webhook_state_never_regresses_a_final_row(): void
    {
        Event::fake([DocumentDelivered::class, DocumentFailed::class]);
        Queue::fake();
        config()->set('queue.default', 'database');
        $failed = $this->pending('4711', 'b2brouter', OutboundState::Failed);
        $delivered = $this->pending('4712', 'b2brouter', OutboundState::Delivered);
        $einvoicebe = $this->pending('doc-1', 'einvoicebe', OutboundState::Failed);

        // Replayed earlier notifications: "sent" after the failure, "error" and "new" after the delivery.
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"state":"sent"}}'), 204);
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4712,"state":"error"}}'), 204);
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4712,"state":"new"}}'), 204);
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4712}}'), 204);
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent', 'doc-1')), 204);
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent.failed', 'doc-1')), 204);

        $this->assertSame(OutboundState::Failed, OutboundMessage::query()->findOrFail($failed->id)->state);
        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($delivered->id)->state);
        $this->assertSame(OutboundState::Failed, OutboundMessage::query()->findOrFail($einvoicebe->id)->state);
        Event::assertNotDispatched(DocumentDelivered::class);
        Event::assertNotDispatched(DocumentFailed::class);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_einvoicebe_failed_event_dispatches_status_poll(): void
    {
        Event::fake([DocumentDelivered::class, DocumentFailed::class]);
        Bus::fake();
        config()->set('queue.default', 'database');
        $pending = $this->pending('doc-1', 'einvoicebe');
        $this->pending('doc-2', 'einvoicebe', OutboundState::Delivered);
        // The same provider id on another transport is not this transport's document.
        $this->pending('doc-1', 'recommand');

        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent.failed', 'doc-1')), 204);

        Bus::assertDispatchedTimes(PollDeliveryStatus::class, 1);
        Bus::assertDispatched(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->messageId === $pending->id && $job->attempt === 1);
        $this->assertSame(OutboundState::Pending, OutboundMessage::query()->findOrFail($pending->id)->state);

        // A row that is no longer pending is not polled.
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent.failed', 'doc-2')), 204);
        Bus::assertDispatchedTimes(PollDeliveryStatus::class, 1);

        // A state-less Recommand delivery event, such as an e-mail channel's, polls too.
        $recommand = $this->pending('doc-9');
        self::assertEmptyResponse($this->signed('{"eventType":"document.sent","documentId":"doc-9","companyId":"c_test","channel":"email"}'), 204);
        Bus::assertDispatchedTimes(PollDeliveryStatus::class, 2);
        Bus::assertDispatched(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->messageId === $recommand->id);

        // On a synchronous connection no poll can be delayed, so none is scheduled.
        config()->set('queue.default', 'sync');
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent.failed', 'doc-1')), 204);
        Bus::assertDispatchedTimes(PollDeliveryStatus::class, 2);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_einvoicebe_received_event_notifies_inbound(): void
    {
        $this->receive('in-1');
        Event::fake([WebhookReceived::class]);

        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.received', 'in-1', 'tenant-1')), 204);

        $row = InboundMessage::query()->where('transport', 'einvoicebe')->where('provider_document_id', 'in-1')->sole();
        $this->assertSame(InboundState::Acknowledged, $row->state);
        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->event->kind === WebhookKind::DocumentReceived
            && $event->event->accountId === 'tenant-1');

        // Other events are acknowledged and ignored.
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.received.failed', 'in-2')), 204);
        $this->assertSame(1, InboundMessage::query()->count());
    }

    public function test_foreign_account_is_ignored(): void
    {
        $this->receive('in-1');
        $this->receive('in-2');
        Event::fake([WebhookReceived::class, DocumentDelivered::class]);
        $message = $this->pending('4711', 'b2brouter');

        // B2Brouter: another account is dropped; the configured one, as an int, is not.
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":99999,"state":"sent"}}'), 204);
        $this->assertSame(OutboundState::Pending, OutboundMessage::query()->findOrFail($message->id)->state);
        Event::assertNotDispatched(WebhookReceived::class);
        self::assertEmptyResponse($this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":78234,"state":"sent"}}'), 204);
        $this->assertSame(OutboundState::Delivered, OutboundMessage::query()->findOrFail($message->id)->state);

        // e-invoice.be without a configured tenant id: every tenant is processed.
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.received', 'in-1', 'tenant-1')), 204);
        $this->assertSame(1, InboundMessage::query()->where('transport', 'einvoicebe')->count());

        // With a tenant id configured, another tenant is dropped and the configured one processed.
        config()->set('einvoicing.transports.einvoicebe.tenant_id', 'tenant-2');
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.received', 'in-2', 'tenant-1')), 204);
        $this->assertSame(1, InboundMessage::query()->where('transport', 'einvoicebe')->count());
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.received', 'in-2', 'tenant-2')), 204);
        $this->assertSame(2, InboundMessage::query()->where('transport', 'einvoicebe')->count());
        // An event that names no tenant is processed.
        self::assertEmptyResponse($this->signedEInvoiceBe(self::einvoicebeEvent('document.sent', '4711')), 204);
        Event::assertDispatchedTimes(WebhookReceived::class, 4);
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
