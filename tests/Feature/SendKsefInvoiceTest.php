<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\ExpireSendingClaim;
use IntrAgile\EInvoicing\Laravel\Jobs\PollKsefStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Jobs\SendKsefInvoice;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use RuntimeException;

final class SendKsefInvoiceTest extends TestCase
{
    use KsefInvoices;
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class, DocumentUnconfirmed::class]);
    }

    private function runSend(OutboundMessage $message): OutboundMessage
    {
        (new SendKsefInvoice($message->id))->handle($this->app->make(EInvoicingManager::class));

        return OutboundMessage::query()->findOrFail($message->id);
    }

    private function runPoll(OutboundMessage $message): OutboundMessage
    {
        (new PollKsefStatus($message->id, $message->attempts))->handle($this->app->make(EInvoicingManager::class));

        return OutboundMessage::query()->findOrFail($message->id);
    }

    /**
     * @return array<mixed>
     */
    private function sentBody(int $index = 0): array
    {
        $sends = array_values(array_filter(
            $this->api->requests(),
            static fn ($request): bool => $request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/invoices'),
        ));

        return json_decode((string) $sends[$index]->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_queue_stores_row_and_dispatches_after_commit(): void
    {
        Queue::fake();
        $this->bindKsef();
        $xml = self::fa3();

        $message = DB::transaction(fn (): OutboundMessage => EInvoicing::ksef()->queue($xml, reference: 'FV/TEST/1/2026'));

        $row = OutboundMessage::query()->findOrFail($message->id);
        $this->assertSame('ksef', $row->transport);
        $this->assertSame('FV/TEST/1/2026', $row->reference);
        $this->assertSame(FakeKsefApi::NIP, $row->sender);
        $this->assertSame(self::BUYER_NIP, $row->receiver);
        $this->assertSame('FA (3)', $row->document_type);
        $this->assertSame('ksef', $row->process);
        $this->assertSame($xml, $row->xml);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame('Online', $row->invoicing_mode);
        $this->assertNull($row->offline_deadline);
        Queue::assertPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->messageId === $message->id && $job->afterCommit === true);

        // An offline invoice carries its offline24 deadline at once: the working day after P_1
        // (Friday 2026-11-20), so the end of Monday 2026-11-23 in Warsaw.
        $offline = EInvoicing::ksef()->queue($xml, true);
        $this->assertSame('Offline', $offline->invoicing_mode);
        $this->assertNotNull($offline->offline_deadline);
        $this->assertSame('2026-11-23 23:59', $offline->offline_deadline->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'));

        // Queueing makes no KSeF request.
        $this->assertSame([], $this->api->paths());
    }

    public function test_queue_refuses_a_document_that_is_not_fa3_and_stores_nothing(): void
    {
        Queue::fake();
        $this->bindKsef();

        try {
            EInvoicing::ksef()->queue('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
            $this->fail('A document that is not FA(3) was queued.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('FA(3)', $exception->getMessage());
        }

        $this->assertSame(0, OutboundMessage::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_send_marks_sent_and_schedules_status_poll(): void
    {
        Queue::fake();
        $this->bindKsef()->withSession()->onSend(FakeKsefApi::accepted());
        $message = EInvoicing::ksef()->queue(self::fa3());

        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(self::DOCUMENT_ID, $row->provider_document_id);
        $this->assertNotNull($row->sent_at);
        $this->assertNull($row->finished_at);
        $this->assertFalse($this->sentBody()['offlineMode']);
        $this->assertSame(base64_encode(hash('sha256', self::fa3(), true)), $this->sentBody()['invoiceHash']);
        Event::assertDispatched(DocumentSent::class, fn (DocumentSent $event): bool => $event->message->id === $message->id);
        Queue::assertPushed(ExpireSendingClaim::class, fn (ExpireSendingClaim $job): bool => $job->messageId === $message->id && $job->attempt === 1);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id
            && $job->attempt === 1
            && $job->delay === 60
            && $job->afterCommit === true);

        // A second job for the same row sends nothing.
        $this->runSend($message);
        $this->assertCount(1, array_filter($this->api->paths(), static fn (string $path): bool => str_ends_with($path, '/invoices')));
    }

    public function test_offline_row_is_sent_with_the_offline_flag(): void
    {
        Queue::fake();
        $this->bindKsef()->withSession()->onSend(FakeKsefApi::accepted());
        $message = EInvoicing::ksef()->queue(self::fa3(), true);

        $this->runSend($message);

        $this->assertTrue($this->sentBody()['offlineMode']);
    }

    public function test_outcome_unknown_is_resent_and_duplicate_440_becomes_delivered(): void
    {
        Queue::fake();
        $this->bindKsef()->withSession()
            ->onSend(FakeKsefApi::connectionLost(), FakeKsefApi::accepted(FakeKsefApi::SECOND_INVOICE_REF))
            ->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER))
            // The original's receipt proves it is this file.
            ->onUpo(FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, self::fa3()), FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, self::fa3()));
        $message = EInvoicing::ksef()->queue(self::fa3());

        // The first send is lost on the way back: the row is queued again, never unconfirmed.
        $row = $this->runSend($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->failure_message);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
        Queue::assertPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->messageId === $message->id && $job->delay === 30);

        // The resend carries the same bytes.
        $row = $this->runSend($row);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(2, $row->attempts);
        $this->assertSame(FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::SECOND_INVOICE_REF, $row->provider_document_id);
        $this->assertSame($this->sentBody(0)['invoiceHash'], $this->sentBody(1)['invoiceHash']);

        // KSeF answers the copy as a duplicate of the invoice the lost send delivered, and the
        // original's receipt carries this file's digest.
        $row = $this->runPoll($row);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(FakeKsefApi::KSEF_NUMBER, $row->ksef_number);
        $this->assertNull($row->failure_category);
        $this->assertNotNull($row->finished_at);
        Event::assertDispatched(DocumentDelivered::class, fn (DocumentDelivered $event): bool => $event->message->id === $message->id);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_validation_failure_marks_failed_and_fires_event(): void
    {
        Queue::fake();
        $this->bindKsef();
        $invalid = str_replace('<P_15>123.00</P_15>', '', self::fa3());
        $message = EInvoicing::ksef()->queue($invalid);

        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Validation, $row->failure_category);
        $this->assertNotNull($row->failure_message);
        $this->assertNotNull($row->finished_at);
        Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $event): bool => $event->message->id === $message->id);
        Event::assertNotDispatched(DocumentSent::class);
        // Nothing was sent, not even an authentication.
        $this->assertSame([], $this->api->paths());
    }

    public function test_rate_limited_uses_retry_after(): void
    {
        Queue::fake();
        $this->bindKsef()->withSession()->onSend(
            new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '120'], '{"status":{"code":429,"description":"Too many requests"}}'),
        );
        $message = EInvoicing::ksef()->queue(self::fa3());

        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(1, $row->attempts);
        Queue::assertPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->messageId === $message->id && $job->delay === 120);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_send_after_the_last_attempt_with_unknown_outcome_is_unconfirmed(): void
    {
        Queue::fake();
        config()->set('einvoicing.sending.max_attempts', 1);
        $this->bindKsef()->withSession()->onSend(FakeKsefApi::connectionLost());
        $message = EInvoicing::ksef()->queue(self::fa3());

        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        Event::assertDispatched(DocumentUnconfirmed::class);
        Queue::assertNotPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->delay !== null);
    }

    public function test_resend_of_an_unconfirmed_row_queues_the_ksef_job_marked_as_possibly_accepted(): void
    {
        Queue::fake();
        $this->bindKsef();
        $message = $this->ksefRow(OutboundState::Unconfirmed, ['provider_document_id' => null, 'failure_message' => 'The worker stopped while sending.']);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true, '--force' => true])->assertSuccessful();

        $row = OutboundMessage::query()->findOrFail($message->id);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(SendKsefInvoice::OUTCOME_UNKNOWN, $row->provider_code);
        Queue::assertPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->messageId === $message->id);
        Queue::assertNotPushed(SendDocument::class);
    }

    public function test_refusal_after_an_unknown_outcome_is_unconfirmed_not_failed(): void
    {
        Queue::fake();
        $this->bindKsef()->withSession()->onSend(FakeKsefApi::connectionLost(), FakeKsefApi::problem(403, 21115, 'Brak uprawnień'));
        $message = EInvoicing::ksef()->queue(self::fa3());

        $this->runSend($message);
        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_sync_connection_sends_and_waits_for_the_final_status(): void
    {
        config()->set('queue.default', 'sync');
        // Processing at first, then accepted; the receipt is tried once.
        $this->bindKsef()->withSession()->onSend(FakeKsefApi::accepted())
            ->onStatus(FakeKsefApi::status(150, 'Trwa przetwarzanie'), FakeKsefApi::delivered(), FakeKsefApi::delivered())
            ->onUpo(FakeKsefApi::upo());

        $message = EInvoicing::ksef()->queue(self::fa3());

        $row = OutboundMessage::query()->findOrFail($message->id);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(FakeKsefApi::KSEF_NUMBER, $row->ksef_number);
        $this->assertNotNull($row->upo);
        $this->assertSame(1, $row->status_checks);
        Event::assertDispatched(DocumentDelivered::class);
    }

    public function test_expired_claim_of_a_ksef_row_does_not_mention_an_access_point(): void
    {
        $this->bindKsef();
        $message = $this->ksefRow(OutboundState::Sending, ['provider_document_id' => null]);

        (new ExpireSendingClaim($message->id, 1))->handle();

        $row = OutboundMessage::query()->findOrFail($message->id);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(ExpireSendingClaim::KSEF_MESSAGE, $row->failure_message);
        $this->assertStringNotContainsString('access point', (string) $row->failure_message);
        $this->assertSame(SendKsefInvoice::OUTCOME_UNKNOWN, $row->provider_code);
    }

    public function test_resolve_sent_refuses_an_id_that_is_not_a_ksef_document_id(): void
    {
        Queue::fake();
        $this->bindKsef();
        $message = $this->ksefRow(OutboundState::Unconfirmed, ['provider_document_id' => null]);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => 'abc'])
            ->expectsOutputToContain('<session reference>/<invoice reference>')
            ->assertExitCode(2);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => self::DOCUMENT_ID])->assertSuccessful();
        $this->assertSame(OutboundState::Pending, OutboundMessage::query()->findOrFail($message->id)->state);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id);
    }

    public function test_answer_arriving_after_the_claim_expired_is_reported(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = null;
        $this->bindKsef()->withSession()->on('POST', '#\A/sessions/online/[A-Za-z0-9-]+/invoices\z#', function () use (&$message): never {
            // The claim expiry ends the claim while the request is in flight, then the connection drops.
            (new ExpireSendingClaim($message->id, 1))->handle();

            throw FakeKsefApi::connectionLost();
        });
        $message = EInvoicing::ksef()->queue(self::fa3());

        $row = $this->runSend($message);

        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(SendKsefInvoice::OUTCOME_UNKNOWN, $row->provider_code);
        Queue::assertNotPushed(SendKsefInvoice::class, fn (SendKsefInvoice $job): bool => $job->delay !== null);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'after the row had left that sending claim'));
    }

    public function test_job_payload_holds_only_the_id(): void
    {
        $payload = serialize(new SendKsefInvoice(7));

        $this->assertStringNotContainsString('Faktura', $payload);
        $this->assertStringNotContainsString(FakeKsefApi::TOKEN, $payload);
    }
}
