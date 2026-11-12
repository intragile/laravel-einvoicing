<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\Support\Invoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\SendResult;

final class SendDocumentTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $fake;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRecommand();
        $this->fake = EInvoicing::fake();
    }

    private function fixture(): string
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        return $xml;
    }

    private function stored(OutboundMessage $message): OutboundMessage
    {
        return OutboundMessage::query()->findOrFail($message->id);
    }

    public function test_send_queues_and_delivers(): void
    {
        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class]);

        $message = EInvoicing::send($this->fixture(), 'INV-1');

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame('fake-1', $row->provider_document_id);
        $this->assertSame('INV-1', $row->reference);
        $this->assertSame('recommand', $row->transport);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->sent_at);
        $this->assertNotNull($row->finished_at);
        $this->assertNull($row->failure_category);

        Event::assertDispatched(DocumentSent::class, fn (DocumentSent $e): bool => $e->message->id === $message->id);
        Event::assertDispatched(DocumentDelivered::class, fn (DocumentDelivered $e): bool => $e->message->id === $message->id);
        Event::assertNotDispatched(DocumentFailed::class);
        $this->fake->assertSent(fn (OutboundDocument $d): bool => $d->receiver->toString() === Invoices::RECEIVER);
    }

    public function test_send_accepts_invoice_object(): void
    {
        $message = EInvoicing::send(Invoices::bis());

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(Invoices::SENDER, $row->sender);
        $this->assertSame(Invoices::RECEIVER, $row->receiver);
        $this->assertSame('BE', $row->country_c1);
        $this->assertStringContainsString(Invoices::BIS_CUSTOMIZATION, $row->xml);
        $this->assertCount(1, $this->fake->sent());
    }

    public function test_send_accepts_outbound_document(): void
    {
        $message = EInvoicing::send(OutboundDocument::fromXml($this->fixture()));

        $this->assertSame(OutboundState::Delivered, $this->stored($message)->state);
    }

    public function test_pending_result_stays_pending(): void
    {
        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class]);
        $this->fake->willReturn(new SendResult('doc-7', DeliveryState::Pending, 'msg-7', 'env-7'));

        $row = $this->stored(EInvoicing::send($this->fixture()));

        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame('doc-7', $row->provider_document_id);
        $this->assertSame('msg-7', $row->peppol_message_id);
        $this->assertSame('env-7', $row->envelope_id);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->finished_at);
        Event::assertDispatched(DocumentSent::class);
        Event::assertNotDispatched(DocumentDelivered::class);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_unknown_result_is_pending(): void
    {
        $this->fake->willReturn(new SendResult('doc-8', DeliveryState::Unknown));

        $row = $this->stored(EInvoicing::send($this->fixture()));

        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertNull($row->finished_at);
    }

    public function test_failed_result_fires_failed(): void
    {
        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class]);
        $this->fake->willReturn(new SendResult(
            'x',
            DeliveryState::Failed,
            failure: new DeliveryFailure(FailureCategory::RecipientNotFound, 'Receiver is not registered', 'E404'),
        ));

        $row = $this->stored(EInvoicing::send($this->fixture()));

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame('x', $row->provider_document_id);
        $this->assertSame(FailureCategory::RecipientNotFound, $row->failure_category);
        $this->assertSame('Receiver is not registered', $row->failure_message);
        $this->assertSame('E404', $row->provider_code);
        $this->assertNotNull($row->finished_at);
        Event::assertDispatched(DocumentSent::class);
        Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $e): bool => $e->message->id === $row->id);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_unparsable_document_throws_and_stores_nothing(): void
    {
        try {
            EInvoicing::send('this is not XML');
            $this->fail('An unparsable document was accepted.');
        } catch (UnsupportedDocument) {
        }

        $this->assertSame(0, OutboundMessage::query()->count());
        $this->assertSame([], $this->fake->sent());
    }

    public function test_unknown_transport_throws_and_stores_nothing(): void
    {
        try {
            EInvoicing::send($this->fixture(), transport: 'missing');
            $this->fail('An unknown transport was accepted.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('einvoicing.transports.missing', $e->getMessage());
        }

        $this->assertSame(0, OutboundMessage::query()->count());
    }

    public function test_second_job_for_same_message_does_not_send(): void
    {
        Queue::fake();
        $message = EInvoicing::send($this->fixture());

        $manager = $this->app->make(EInvoicingManager::class);
        (new SendDocument($message->id))->handle($manager);
        (new SendDocument($message->id))->handle($manager);

        $this->assertCount(1, $this->fake->sent());
        $this->assertSame(1, $this->stored($message)->attempts);
    }

    public function test_job_for_missing_message_does_nothing(): void
    {
        (new SendDocument(999))->handle($this->app->make(EInvoicingManager::class));

        $this->assertSame([], $this->fake->sent());
    }

    public function test_malformed_stored_identifier_fails_without_sending(): void
    {
        Queue::fake();
        Event::fake([DocumentSent::class, DocumentFailed::class]);
        $message = EInvoicing::send($this->fixture());
        OutboundMessage::query()->whereKey($message->id)->update(['receiver' => 'not-an-identifier']);

        (new SendDocument($message->id))->handle($this->app->make(EInvoicingManager::class));

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Other, $row->failure_category);
        $this->assertStringContainsString('not-an-identifier', (string) $row->failure_message);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->sent_at);
        $this->assertNotNull($row->finished_at);
        $this->assertSame([], $this->fake->sent());
        Event::assertDispatched(DocumentFailed::class);
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_job_payload_holds_only_the_id(): void
    {
        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($this->fixture()), 'recommand', 'INV-1');
        $message->state = OutboundState::Queued;
        $message->save();

        $payload = serialize(new SendDocument($message->id));

        $this->assertStringNotContainsString(self::SECRET_MARK, $payload);
        $this->assertStringNotContainsString('<Invoice', $payload);
        $this->assertStringNotContainsString(Invoices::RECEIVER, $payload);
    }

    public function test_job_settings_come_from_config(): void
    {
        config()->set('einvoicing.queue.connection', 'redis');
        config()->set('einvoicing.queue.name', 'einvoicing');
        config()->set('einvoicing.http.timeout', 20);
        config()->set('einvoicing.http.connect_timeout', 5);

        $job = new SendDocument(1, 2);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('einvoicing', $job->queue);
        $this->assertSame(55, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame(2, $job->attempt);
    }

    public function test_dispatch_waits_for_commit(): void
    {
        $message = DB::transaction(function (): OutboundMessage {
            $message = EInvoicing::send($this->fixture());

            $this->assertSame([], $this->fake->sent());
            $this->assertSame(OutboundState::Queued, $this->stored($message)->state);

            return $message;
        });

        $this->assertCount(1, $this->fake->sent());
        $this->assertSame(OutboundState::Delivered, $this->stored($message)->state);
    }

    public function test_dispatched_job_is_marked_after_commit(): void
    {
        Queue::fake();

        $message = EInvoicing::send($this->fixture());

        Queue::assertPushed(SendDocument::class, fn (SendDocument $job): bool => $job->messageId === $message->id && $job->afterCommit === true);
    }
}
