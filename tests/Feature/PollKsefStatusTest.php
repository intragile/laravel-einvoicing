<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Jobs\PollKsefStatus;
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
        Event::fake([DocumentDelivered::class, DocumentFailed::class]);
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

    public function test_duplicate_of_another_invoice_fails(): void
    {
        // Another stored invoice with other bytes already holds the KSeF number.
        $this->ksefRow(OutboundState::Delivered, [
            'xml' => self::fa3(number: 'FV/TEST/OTHER/2026'),
            'provider_document_id' => FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::SECOND_INVOICE_REF,
            'ksef_number' => FakeKsefApi::KSEF_NUMBER,
        ]);
        $this->bindKsef()->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Duplicate, $row->failure_category);
        $this->assertNull($row->ksef_number);
        $this->assertStringContainsString(FakeKsefApi::KSEF_NUMBER, (string) $row->failure_message);
        Event::assertDispatched(DocumentFailed::class);
    }

    public function test_duplicate_of_an_identical_stored_invoice_is_delivered(): void
    {
        $this->ksefRow(OutboundState::Delivered, [
            'provider_document_id' => FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::SECOND_INVOICE_REF,
            'ksef_number' => FakeKsefApi::KSEF_NUMBER,
        ]);
        $this->bindKsef()->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(FakeKsefApi::KSEF_NUMBER, $row->ksef_number);
    }

    public function test_duplicate_of_an_invoice_sent_elsewhere_fails(): void
    {
        // No stored row holds the number and no earlier send of this row may have been accepted,
        // so the original's bytes are unknown.
        $this->bindKsef()->onStatus(FakeKsefApi::duplicate(FakeKsefApi::KSEF_NUMBER));
        $message = $this->ksefRow();

        $row = $this->runPoll($message);

        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Duplicate, $row->failure_category);
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
