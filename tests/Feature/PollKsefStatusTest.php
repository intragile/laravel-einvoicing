<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeZone;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Jobs\PollKsefStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendKsefInvoice;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\FailureCategory;

final class PollKsefStatusTest extends TestCase
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

        Queue::fake();
        Event::fake([DocumentDelivered::class, DocumentFailed::class, DocumentUnconfirmed::class]);
    }

    private function runPoll(OutboundMessage $message, ?int $attempt = null): OutboundMessage
    {
        (new PollKsefStatus($message->id, $attempt ?? $message->attempts))->handle($this->app->make(EInvoicingManager::class));

        return OutboundMessage::query()->findOrFail($message->id);
    }

    public function test_status_200_stores_ksef_number_mode_and_upo(): void
    {
        // The receipt request reads the status again to find the KSeF number.
        $this->bindKsef()->onStatus(FakeKsefApi::delivered(), FakeKsefApi::delivered())->onUpo(FakeKsefApi::upo());
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(FakeKsefApi::KSEF_NUMBER, $row->ksef_number);
        $this->assertSame('Online', $row->invoicing_mode);
        $this->assertNull($row->offline_deadline);
        $this->assertNotNull($row->upo);
        $this->assertStringContainsString('<NumerKSeFDokumentu>'.FakeKsefApi::KSEF_NUMBER.'</NumerKSeFDokumentu>', $row->upo);
        $this->assertNotNull($row->finished_at);
        $this->assertSame(1, $row->status_checks);
        Event::assertDispatched(DocumentDelivered::class, fn (DocumentDelivered $event): bool => $event->message->id === $message->id);
        Queue::assertNotPushed(PollKsefStatus::class);
    }

    public function test_pending_status_schedules_the_next_poll(): void
    {
        $this->bindKsef()->onStatus(FakeKsefApi::status(150, 'Trwa przetwarzanie'));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(1, $row->status_checks);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id && $job->attempt === 1 && $job->delay === 300);
    }

    public function test_receipt_not_issued_yet_is_fetched_by_a_later_poll(): void
    {
        $this->bindKsef()
            ->onStatus(FakeKsefApi::delivered(), FakeKsefApi::delivered(), FakeKsefApi::delivered())
            ->onUpo(FakeKsefApi::problem(404, 21178, 'Nie znaleziono UPO'), FakeKsefApi::upo());
        $message = $this->ksefRow();

        $row = $this->runPoll($message);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertNull($row->upo);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id && $job->attempt === 1);

        $row = $this->runPoll($row);
        $this->assertNotNull($row->upo);
        $this->assertSame(2, $row->status_checks);
        Event::assertDispatched(DocumentDelivered::class, 1);
    }

    public function test_offline_row_gets_deadline(): void
    {
        // Sent online on a later day, so KSeF classifies it as offline by its issue date.
        $this->bindKsef()
            ->onStatus(FakeKsefApi::delivered(mode: 'Offline'), FakeKsefApi::delivered(mode: 'Offline'))
            ->onUpo(FakeKsefApi::upo());
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame('Offline', $row->invoicing_mode);
        $this->assertNotNull($row->offline_deadline);
        $this->assertSame('2026-11-23 23:59', $row->offline_deadline->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'));
    }

    public function test_rejected_invoice_fails_and_fires_event(): void
    {
        $this->bindKsef()->onStatus(FakeKsefApi::status(450, 'Błąd weryfikacji semantyki dokumentu faktury', [], ['details' => ['P_15 is wrong']]));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Validation, $row->failure_category);
        $this->assertSame('450', $row->provider_code);
        $this->assertStringContainsString('P_15 is wrong', (string) $row->failure_message);
        Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $event): bool => $event->message->id === $message->id);
    }

    public function test_resend_after_unknown_outcome_ending_on_a_processing_failure_is_unconfirmed(): void
    {
        foreach ([405, 435, 500, 550] as $code) {
            $this->bindKsef()->onStatus(FakeKsefApi::status($code, 'Przetwarzanie przerwane'));
            $message = $this->ksefRow(OutboundState::Pending, ['provider_code' => SendKsefInvoice::OUTCOME_UNKNOWN]);

            $row = $this->runPoll($message);

            $this->assertSame(OutboundState::Unconfirmed, $row->state, (string) $code);
            $this->assertSame(SendKsefInvoice::OUTCOME_UNKNOWN, $row->provider_code, (string) $code);
            $this->assertStringContainsString((string) $code, (string) $row->failure_message);
            $this->assertStringContainsString('An earlier send of this invoice may have been accepted', (string) $row->failure_message);
            $this->assertNull($row->finished_at);
            Event::assertDispatched(DocumentUnconfirmed::class, fn (DocumentUnconfirmed $event): bool => $event->message->id === $message->id);
            // The next row reuses the same KSeF document id.
            $row->delete();
        }
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_resend_after_unknown_outcome_ending_on_a_validation_failure_fails(): void
    {
        $this->bindKsef()->onStatus(FakeKsefApi::status(450, 'Błąd weryfikacji semantyki dokumentu faktury'));
        $message = $this->ksefRow(OutboundState::Pending, ['provider_code' => SendKsefInvoice::OUTCOME_UNKNOWN]);

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Validation, $row->failure_category);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_duplicate_proven_by_the_original_receipt_is_delivered_with_the_original_number(): void
    {
        // One receipt read for the comparison, one stored on the row.
        $this->bindKsef()
            ->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER))
            ->onUpo(FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, self::fa3()), FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, self::fa3()));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(FakeKsefApi::KSEF_NUMBER, $row->ksef_number);
        $this->assertSame(PollKsefStatus::DUPLICATE, $row->provider_code);
        $this->assertStringContainsString(FakeKsefApi::KSEF_NUMBER, (string) $row->upo);
        $this->assertContains('GET /sessions/'.FakeKsefApi::ORIGINAL_SESSION_REF.'/invoices/ksef/'.FakeKsefApi::KSEF_NUMBER.'/upo', $this->api->paths());
        Event::assertDispatched(DocumentDelivered::class);
        Queue::assertNotPushed(PollKsefStatus::class);
    }

    public function test_duplicate_of_a_different_file_fails_naming_the_original(): void
    {
        $this->bindKsef()
            ->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER))
            ->onUpo(FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, self::fa3(number: 'FV/TEST/OTHER/2026')));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Duplicate, $row->failure_category);
        $this->assertNull($row->ksef_number);
        $this->assertStringContainsString(FakeKsefApi::KSEF_NUMBER, (string) $row->failure_message);
        $this->assertStringContainsString('different file', (string) $row->failure_message);
        Event::assertDispatched(DocumentFailed::class);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_undecidable_duplicate_is_retried_then_unconfirmed(): void
    {
        config()->set('einvoicing.status.max_attempts', 2);
        $this->bindKsef()
            ->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER), FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER))
            ->onUpo(FakeKsefApi::problem(404, 21178, 'Nie znaleziono UPO'), FakeKsefApi::problem(404, 21178, 'Nie znaleziono UPO'));
        $message = $this->ksefRow();

        // The original's receipt is not available yet: asked again on the next run.
        $row = $this->runPoll($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id && $job->delay === 300);

        // The last run still cannot decide: an operator confirms it.
        $row = $this->runPoll($row);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertNull($row->ksef_number);
        $this->assertStringContainsString(FakeKsefApi::KSEF_NUMBER, (string) $row->failure_message);
        $this->assertStringContainsString('einvoicing:resolve', (string) $row->failure_message);
        Event::assertDispatched(DocumentUnconfirmed::class);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_duplicate_of_another_accepted_but_unpolled_row_is_not_delivered(): void
    {
        // Row A, with the same seller, type and number but other content, was accepted by KSeF and
        // is not polled yet; row B's send is answered 440 naming A's KSeF number.
        $rowAXml = self::fa3();
        $this->ksefRow(OutboundState::Pending, [
            'provider_document_id' => FakeKsefApi::ORIGINAL_SESSION_REF.'/'.FakeKsefApi::SECOND_INVOICE_REF,
        ]);
        $rowBXml = str_replace('<P_7>Usługa testowa</P_7>', '<P_7>Inna usługa</P_7>', $rowAXml);
        $this->bindKsef()
            ->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER))
            ->onUpo(FakeKsefApi::upo(FakeKsefApi::KSEF_NUMBER, $rowAXml));
        $rowB = $this->ksefRow(OutboundState::Pending, ['xml' => $rowBXml, 'provider_code' => SendKsefInvoice::OUTCOME_UNKNOWN]);

        $row = $this->runPoll($rowB);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertNull($row->ksef_number);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_row_with_a_document_id_that_is_not_a_ksef_one_fails(): void
    {
        $this->bindKsef();
        $message = $this->ksefRow(OutboundState::Pending, ['provider_document_id' => 'not-a-ksef-id']);

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertStringContainsString('<session reference>/<invoice reference>', (string) $row->failure_message);
        Event::assertDispatched(DocumentFailed::class);
        $this->assertSame([], $this->api->paths());
    }

    public function test_rate_limited_status_read_waits_for_retry_after(): void
    {
        $this->bindKsef()->onStatus(new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '1200'], '{}'));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Pending, $row->state);
        Queue::assertPushed(PollKsefStatus::class, fn (PollKsefStatus $job): bool => $job->messageId === $message->id && $job->delay === 1200);
    }

    public function test_poll_for_an_earlier_send_does_nothing(): void
    {
        $this->bindKsef();
        $message = $this->ksefRow(OutboundState::Pending, ['attempts' => 2]);

        $row = $this->runPoll($message, 1);

        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(0, $row->status_checks);
        $this->assertSame([], $this->api->paths());
    }
}
