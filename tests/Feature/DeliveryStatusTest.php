<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\DeliveryRecorder;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<DeliveryStatus|TransportException> */
    private array $statuses = [];

    /** @var list<string> */
    private array $queried = [];

    /** Runs inside status(), to change the row while the request is in flight. */
    private ?Closure $duringStatus = null;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class]);
    }

    /**
     * Replaces the default transport with one that answers status queries from $this->statuses.
     */
    private function scriptedTransport(): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand', ['driver' => 'scripted']);
        $this->app->forgetInstance(EInvoicingManager::class);
        EInvoicing::clearResolvedInstances();

        $test = $this;
        $this->app->make(EInvoicingManager::class)->extend('scripted', fn (): Transport => new class($test) implements Transport
        {
            public function __construct(private readonly DeliveryStatusTest $test) {}

            public function send(OutboundDocument $document): SendResult
            {
                throw new LogicException('Sending is not used here.');
            }

            public function status(string $documentId): DeliveryStatus
            {
                return $this->test->nextStatus($documentId);
            }
        });
    }

    /**
     * @internal called by the scripted transport
     */
    public function nextStatus(string $documentId): DeliveryStatus
    {
        $this->queried[] = $documentId;
        if ($this->duringStatus !== null) {
            ($this->duringStatus)();
        }
        $next = array_shift($this->statuses) ?? throw new LogicException('No status scripted.');
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    private function message(OutboundState $state, ?string $providerId = 'doc-1', int $checks = 0): OutboundMessage
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($xml), 'recommand', 'INV-1');
        $message->state = $state;
        $message->attempts = 1;
        $message->status_checks = $checks;
        $message->provider_document_id = $providerId;
        $message->save();

        return $message;
    }

    private function stored(OutboundMessage $message): OutboundMessage
    {
        return OutboundMessage::query()->findOrFail($message->id);
    }

    private function poll(OutboundMessage $message): void
    {
        $this->app->call([new PollDeliveryStatus($message->id, $message->attempts), 'handle']);
    }

    /**
     * @return list<int|null>
     */
    private function pushedPollDelays(): array
    {
        return Queue::pushed(PollDeliveryStatus::class)
            ->map(fn (PollDeliveryStatus $job): mixed => $job->delay)
            ->values()
            ->all();
    }

    public function test_poll_delivers_and_stops(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Delivered)];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame(1, $row->status_checks);
        $this->assertNotNull($row->finished_at);
        $this->assertSame(['doc-1'], $this->queried);
        Queue::assertNotPushed(PollDeliveryStatus::class);
        Event::assertDispatched(DocumentDelivered::class, fn (DocumentDelivered $e): bool => $e->message->id === $message->id);
        Event::assertNotDispatched(DocumentFailed::class);

        // A later poll for the delivered row does not query again.
        $this->poll($message);
        $this->assertSame(['doc-1'], $this->queried);
    }

    public function test_poll_records_failure(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Failed, new DeliveryFailure(FailureCategory::RecipientNotFound, 'No such participant.', 'E404'))];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::RecipientNotFound, $row->failure_category);
        $this->assertSame('No such participant.', $row->failure_message);
        $this->assertSame('E404', $row->provider_code);
        $this->assertNotNull($row->finished_at);
        Queue::assertNotPushed(PollDeliveryStatus::class);
        Event::assertDispatched(DocumentFailed::class, 1);
    }

    public function test_poll_reschedules_with_delays(): void
    {
        $this->scriptedTransport();
        $this->statuses = [
            new DeliveryStatus('doc-1', DeliveryState::Unknown),
            new DeliveryStatus('doc-1', DeliveryState::Pending),
        ];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(1, $row->status_checks);
        $this->assertSame([300], $this->pushedPollDelays());
        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->messageId === $message->id && $job->afterCommit === true);

        $this->poll($message);

        $this->assertSame(2, $this->stored($message)->status_checks);
        $this->assertSame([300, 900], $this->pushedPollDelays());
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_last_poll_delay_repeats(): void
    {
        config()->set('einvoicing.status.delays', [5, 10]);
        $this->scriptedTransport();
        $this->statuses = array_fill(0, 3, new DeliveryStatus('doc-1', DeliveryState::Pending));
        $message = $this->message(OutboundState::Pending);

        for ($i = 0; $i < 3; $i++) {
            $this->poll($message);
        }

        $this->assertSame([10, 10, 10], $this->pushedPollDelays());
    }

    public function test_poll_stops_at_max_checks(): void
    {
        config()->set('einvoicing.status.max_attempts', 2);
        $this->scriptedTransport();
        $this->statuses = array_fill(0, 3, new DeliveryStatus('doc-1', DeliveryState::Unknown));
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);
        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(2, $row->status_checks);
        $this->assertSame([300], $this->pushedPollDelays());

        // An exhausted row is not queried again.
        $this->poll($message);
        $this->assertCount(2, $this->queried);
        $this->assertSame(2, $this->stored($message)->status_checks);
    }

    public function test_poll_failure_counts_and_reschedules(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new TransportUnavailable('Connection refused.')];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(1, $row->status_checks);
        $this->assertSame([300], $this->pushedPollDelays());
    }

    public function test_poll_ignores_rows_without_provider_id_or_not_pending(): void
    {
        $this->scriptedTransport();
        $withoutId = $this->message(OutboundState::Pending, null);
        $unconfirmed = $this->message(OutboundState::Unconfirmed, 'doc-2');

        $this->poll($withoutId);
        $this->poll($unconfirmed);
        (new PollDeliveryStatus(999_999))->handle($this->app->make(EInvoicingManager::class), new DeliveryRecorder);

        $this->assertSame([], $this->queried);
        $this->assertSame(0, $this->stored($withoutId)->status_checks);
        $this->assertSame(0, $this->stored($unconfirmed)->status_checks);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_poll_does_not_reschedule_on_a_synchronous_connection(): void
    {
        config()->set('einvoicing.queue.connection', 'sync');
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Pending)];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $this->assertSame(1, $this->stored($message)->status_checks);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_terminal_state_never_regresses(): void
    {
        $recorder = new DeliveryRecorder;
        $delivered = $this->message(OutboundState::Delivered, 'doc-1');
        $failed = $this->message(OutboundState::Failed, 'doc-2');

        $this->assertFalse($recorder->record($delivered, DeliveryState::Failed, new DeliveryFailure(FailureCategory::Other)));
        $this->assertFalse($recorder->record($delivered, DeliveryState::Pending));
        $this->assertFalse($recorder->record($failed, DeliveryState::Delivered));
        $this->assertFalse($recorder->record($failed, DeliveryState::Unknown));

        $this->assertSame(OutboundState::Delivered, $this->stored($delivered)->state);
        $this->assertNull($this->stored($delivered)->failure_category);
        $this->assertSame(OutboundState::Failed, $this->stored($failed)->state);
        Event::assertNotDispatched(DocumentDelivered::class);
        Event::assertNotDispatched(DocumentFailed::class);

        // A late poll answer for a terminal row is not even asked for.
        $this->scriptedTransport();
        $this->poll($delivered);
        $this->assertSame([], $this->queried);
    }

    public function test_status_is_not_applied_to_a_row_resent_meanwhile(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Delivered)];
        $message = $this->message(OutboundState::Pending);
        // While doc-1 is queried, the row is resent and accepted again as doc-2.
        $this->duringStatus = fn () => OutboundMessage::query()->whereKey($message->id)->update(['provider_document_id' => 'doc-2']);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame('doc-2', $row->provider_document_id);
        $this->assertNull($row->finished_at);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_recorder_only_changes_the_document_it_was_told_about(): void
    {
        $recorder = new DeliveryRecorder;
        $message = $this->message(OutboundState::Pending, 'doc-1');
        OutboundMessage::query()->whereKey($message->id)->update(['provider_document_id' => 'doc-2']);

        // The in-memory model still carries doc-1, the id the status was fetched for.
        $this->assertFalse($recorder->record($message, DeliveryState::Failed, new DeliveryFailure(FailureCategory::Other)));
        $this->assertFalse($recorder->record($this->stored($message), DeliveryState::Delivered, null, 'doc-1'));

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertNull($row->failure_category);
        Event::assertNotDispatched(DocumentDelivered::class);
        Event::assertNotDispatched(DocumentFailed::class);

        $this->assertTrue($recorder->record($this->stored($message), DeliveryState::Delivered, null, 'doc-2'));
        Event::assertDispatched(DocumentDelivered::class, 1);
    }

    public function test_status_for_another_document_is_ignored(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-other', DeliveryState::Delivered)];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame(1, $row->status_checks);
        $this->assertSame([300], $this->pushedPollDelays());
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    public function test_poll_from_an_earlier_send_does_nothing(): void
    {
        $this->scriptedTransport();
        $message = $this->message(OutboundState::Pending);
        $message->attempts = 2;
        $message->save();

        // Scheduled for the first send (attempt 1); the row has been sent again since.
        $this->app->call([new PollDeliveryStatus($message->id, 1), 'handle']);

        $this->assertSame([], $this->queried);
        $this->assertSame(0, $this->stored($message)->status_checks);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_rescheduled_poll_keeps_its_send(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Pending)];
        $message = $this->message(OutboundState::Pending);

        $this->poll($message);

        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->attempt === 1);
    }

    public function test_duplicate_poll_for_the_same_check_does_nothing(): void
    {
        $this->scriptedTransport();
        $this->statuses = [new DeliveryStatus('doc-1', DeliveryState::Pending)];
        $message = $this->message(OutboundState::Pending);

        // Another job claims the same check between this job's read and its claim.
        $claimed = false;
        OutboundMessage::retrieved(function (OutboundMessage $row) use (&$claimed): void {
            if (! $claimed) {
                $claimed = true;
                OutboundMessage::query()->whereKey($row->id)->update(['status_checks' => 1]);
            }
        });

        $this->poll($message);

        $this->assertSame([], $this->queried);
        $this->assertSame(1, $this->stored($message)->status_checks);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_recorder_keeps_open_states_open(): void
    {
        $recorder = new DeliveryRecorder;
        $message = $this->message(OutboundState::Pending);

        $this->assertFalse($recorder->record($message, DeliveryState::Pending));
        $this->assertFalse($recorder->record($message, DeliveryState::Unknown));
        $this->assertSame(OutboundState::Pending, $this->stored($message)->state);

        $this->assertTrue($recorder->record($message, DeliveryState::Failed));
        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Other, $row->failure_category);
        Event::assertDispatched(DocumentFailed::class, 1);
    }

    public function test_recorder_ignores_rows_that_are_not_pending(): void
    {
        $recorder = new DeliveryRecorder;

        foreach ([OutboundState::Queued, OutboundState::Sending, OutboundState::Unconfirmed] as $state) {
            $message = $this->message($state, null);
            $this->assertFalse($recorder->record($message, DeliveryState::Delivered));
            $this->assertSame($state, $this->stored($message)->state);
        }
    }

    public function test_pending_send_schedules_the_first_poll(): void
    {
        $fake = EInvoicing::fake();
        $fake->willReturn(new SendResult('doc-1', DeliveryState::Pending));
        $message = $this->message(OutboundState::Queued, null);

        (new SendDocument($message->id))->handle($this->app->make(EInvoicingManager::class));

        $this->assertSame(OutboundState::Pending, $this->stored($message)->state);
        $this->assertSame([60], $this->pushedPollDelays());
        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->messageId === $message->id && $job->attempt === 2);
    }

    public function test_delivered_send_schedules_no_poll(): void
    {
        EInvoicing::fake();
        $message = $this->message(OutboundState::Queued, null);

        (new SendDocument($message->id))->handle($this->app->make(EInvoicingManager::class));

        $this->assertSame(OutboundState::Delivered, $this->stored($message)->state);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function offValues(): iterable
    {
        yield 'false' => [false];
        yield 'string false' => ['false'];
        yield 'string 0' => ['0'];
        yield 'string off' => [' OFF '];
    }

    #[DataProvider('offValues')]
    public function test_no_poll_when_disabled(mixed $poll): void
    {
        config()->set('einvoicing.status.poll', $poll);
        $fake = EInvoicing::fake();
        $fake->willReturn(new SendResult('doc-1', DeliveryState::Pending));
        $message = $this->message(OutboundState::Queued, null);

        (new SendDocument($message->id))->handle($this->app->make(EInvoicingManager::class));

        $this->assertSame(OutboundState::Pending, $this->stored($message)->state);
        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_no_poll_on_synchronous_connections(): void
    {
        foreach (['sync', 'deferred', 'background'] as $driver) {
            config()->set('queue.connections.einvoicing-'.$driver, ['driver' => $driver]);
            config()->set('einvoicing.queue.connection', 'einvoicing-'.$driver);
            $fake = EInvoicing::fake();
            $fake->willReturn(new SendResult('doc-'.$driver, DeliveryState::Pending));
            $message = $this->message(OutboundState::Queued, null);

            (new SendDocument($message->id))->handle($this->app->make(EInvoicingManager::class));

            $this->assertSame(OutboundState::Pending, $this->stored($message)->state, $driver);
        }

        Queue::assertNotPushed(PollDeliveryStatus::class);
    }

    public function test_poll_job_payload_holds_only_the_id(): void
    {
        $this->configureRecommand();
        $payload = serialize(new PollDeliveryStatus(42));

        $this->assertStringContainsString('messageId', $payload);
        $this->assertStringNotContainsString(self::SECRET_MARK, $payload);
        $this->assertSame(1, (new PollDeliveryStatus(42))->tries);
    }
}
