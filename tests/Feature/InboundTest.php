<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\InboundSource;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\DocumentTypeId;
use IntrAgile\EInvoicing\Transport\Peppol\ParticipantId;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;
use IntrAgile\EInvoicing\Transport\Peppol\ProcessId;
use IntrAgile\EInvoicing\Transport\Sbdh\Envelope;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

final class InboundTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER = '0208:0123456789';

    private const RECEIVER = '9930:DE123456789';

    /**
     * Outcomes of the stub source's fetch() calls, in order; a document or an exception.
     *
     * @var list<InboundDocument|Throwable>
     */
    private array $fetches = [];

    /**
     * Outcomes of the stub source's acknowledge() calls, in order; null succeeds.
     *
     * @var list<Throwable|null>
     */
    private array $acknowledgements = [];

    /**
     * What the stub source lists as pending.
     *
     * @var list<InboundReference>
     */
    public array $pendingReferences = [];

    /** @var list<string> */
    private array $fetched = [];

    /** @var list<string> */
    private array $acknowledgedIds = [];

    /** The transaction level of the test itself (RefreshDatabase), where nothing is pending. */
    private int $baselineLevel = 0;

    /** Fetch jobs already run by runPushedFetches(), the first one never. */
    private int $ranJobs = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baselineLevel = DB::transactionLevel();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    private function document(string $id, bool $envelope = false): InboundDocument
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $reference = new InboundReference(
            $id,
            ParticipantId::parse(self::SENDER),
            ParticipantId::parse(self::RECEIVER),
            PeppolBis3::INVOICE,
            new DateTimeImmutable('2026-11-12 10:00:00'),
        );

        return new InboundDocument($reference, $xml, $envelope ? new Envelope(
            ParticipantId::parse(self::SENDER),
            ParticipantId::parse(self::RECEIVER),
            new DocumentTypeId(PeppolBis3::INVOICE),
            new ProcessId(PeppolBis3::PROCESS),
            'BE',
            'env-'.$id,
            new DateTimeImmutable('2026-11-12 09:59:00'),
        ) : null);
    }

    /**
     * Replaces the default transport with one whose inbound side answers from $this->fetches and
     * $this->acknowledgements.
     */
    private function stubSource(): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand', ['driver' => 'stub']);
        $this->app->forgetInstance(EInvoicingManager::class);
        EInvoicing::clearResolvedInstances();

        $test = $this;
        $this->app->make(EInvoicingManager::class)->extend('stub', fn (): Transport => new class($test) implements InboundSource, Transport
        {
            public function __construct(private readonly InboundTest $test) {}

            public function send(OutboundDocument $document): SendResult
            {
                throw new LogicException('Sending is not used here.');
            }

            public function status(string $documentId): DeliveryStatus
            {
                throw new LogicException('Status is not used here.');
            }

            public function pending(): array
            {
                return $this->test->pendingReferences;
            }

            public function fetch(string $documentId): InboundDocument
            {
                return $this->test->nextFetch($documentId);
            }

            public function acknowledge(string $documentId): void
            {
                $this->test->nextAcknowledge($documentId);
            }
        });
    }

    /**
     * @internal called by the stub source
     */
    public function nextFetch(string $documentId): InboundDocument
    {
        $this->fetched[] = $documentId;
        $next = array_shift($this->fetches) ?? throw new LogicException('No fetch scripted.');
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @internal called by the stub source
     */
    public function nextAcknowledge(string $documentId): void
    {
        // The transport is told only once the stored document is committed.
        $this->assertSame($this->baselineLevel, DB::transactionLevel(), 'Acknowledged inside an open transaction.');
        $next = array_shift($this->acknowledgements);
        if ($next instanceof Throwable) {
            throw $next;
        }
        $this->acknowledgedIds[] = $documentId;
    }

    /**
     * A notified row, created with the queue faked so no fetch job runs.
     */
    private function notified(string $id = 'in-1'): InboundMessage
    {
        config()->set('queue.default', 'database');
        Queue::fake();

        return $this->app->make(InboundRecorder::class)->notify('recommand', $id);
    }

    private function runFetch(InboundMessage $message, int $attempt = 0): void
    {
        $this->app->call([new FetchInboundDocument($message->id, $attempt), 'handle']);
    }

    private function stored(InboundMessage $message): InboundMessage
    {
        return InboundMessage::query()->findOrFail($message->id);
    }

    /**
     * @return list<int|null>
     */
    private function pushedFetchDelays(): array
    {
        return Queue::pushed(FetchInboundDocument::class)
            ->map(fn (FetchInboundDocument $job): mixed => $job->delay)
            ->values()
            ->all();
    }

    public function test_poll_stores_and_acknowledges(): void
    {
        Event::fake([DocumentReceived::class]);
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1', envelope: true));

        $this->artisan('einvoicing:poll-inbound')
            ->expectsOutputToContain('1 new, 0 retried, 0 already known')
            ->assertSuccessful();

        $row = InboundMessage::query()->sole();
        $this->assertSame('recommand', $row->transport);
        $this->assertSame('in-1', $row->provider_document_id);
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertSame($this->document('in-1')->xml, $row->xml);
        $this->assertSame(self::SENDER, $row->sender);
        $this->assertSame(self::RECEIVER, $row->receiver);
        $this->assertSame(PeppolBis3::INVOICE, $row->document_type);
        $this->assertSame('2026-11-12 10:00:00', $row->received_at?->format('Y-m-d H:i:s'));
        $this->assertSame('env-in-1', $row->envelope_id);
        $this->assertNotNull($row->fetched_at);
        $this->assertNotNull($row->acknowledged_at);
        $this->assertNull($row->last_error);
        $this->assertSame(['in-1'], $fake->acknowledged());
        Event::assertDispatched(DocumentReceived::class, fn (DocumentReceived $e): bool => $e->message->id === $row->id);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
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
    public function test_repeated_poll_fires_one_event(mixed $acknowledge): void
    {
        config()->set('einvoicing.inbound.acknowledge', $acknowledge);
        Event::fake([DocumentReceived::class]);
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));

        $this->artisan('einvoicing:poll-inbound')->expectsOutputToContain('1 new, 0 retried, 0 already known')->assertSuccessful();
        $this->artisan('einvoicing:poll-inbound')->expectsOutputToContain('0 new, 0 retried, 1 already known')->assertSuccessful();

        // A duplicate job for the stored row neither stores again nor fires again.
        $row = InboundMessage::query()->sole();
        $this->runFetch($row);

        $this->assertSame(InboundState::Fetched, $this->stored($row)->state);
        $this->assertSame(1, InboundMessage::query()->count());
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
        $this->assertSame([], $fake->acknowledged());
    }

    public function test_notify_is_insert_or_get(): void
    {
        $first = $this->notified();
        $again = DB::transaction(fn (): InboundMessage => $this->app->make(InboundRecorder::class)->notify('recommand', 'in-1'));
        $other = $this->app->make(InboundRecorder::class)->notify('other', 'in-1', $this->document('in-1')->reference);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(InboundState::Notified, $again->state);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame(self::SENDER, $other->sender);
        $this->assertSame(PeppolBis3::INVOICE, $other->document_type);
        $this->assertSame(2, InboundMessage::query()->count());
        Queue::assertPushed(FetchInboundDocument::class, 2);
        Queue::assertPushed(FetchInboundDocument::class, fn (FetchInboundDocument $job): bool => $job->messageId === $first->id && $job->afterCommit === true);
    }

    public function test_acknowledges_only_after_store(): void
    {
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));
        $seen = [];
        Event::listen(DocumentReceived::class, function (DocumentReceived $event) use ($fake, &$seen): void {
            $row = InboundMessage::query()->findOrFail($event->message->id);
            $this->assertSame(InboundState::Fetched, $row->state);
            $this->assertSame($this->document('in-1')->xml, $row->xml);
            $this->assertSame([], $fake->acknowledged());
            $this->assertSame($this->baselineLevel, DB::transactionLevel(), 'Announced inside an open transaction.');
            $seen[] = $row->id;
        });

        $this->artisan('einvoicing:poll-inbound')->assertSuccessful();

        $this->assertCount(1, $seen);
        $this->assertSame(['in-1'], $fake->acknowledged());
        $this->assertSame(InboundState::Acknowledged, InboundMessage::query()->sole()->state);
    }

    public function test_failed_store_is_not_acknowledged(): void
    {
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);

        // The storing update runs, then the database fails before the transaction commits.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "einvoicing_inbound_documents"') && str_contains($query->sql, '"xml"')) {
                throw new RuntimeException('Database went away.');
            }
        });

        try {
            $this->runFetch($message);
            $this->fail('The database failure was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Database went away.', $e->getMessage());
        }

        $row = $this->stored($message);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertNull($row->xml);
        $this->assertSame([], $fake->acknowledged());
        Event::assertNotDispatched(DocumentReceived::class);
    }

    public function test_ack_failure_keeps_document_and_retries_ack(): void
    {
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];
        $this->acknowledgements = [new TransportUnavailable('Recommand is down.')];

        $this->runFetch($message);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Fetched, $row->state);
        $this->assertSame($this->document('in-1')->xml, $row->xml);
        $this->assertSame(TransportUnavailable::class.': Recommand is down.', $row->last_error);
        $this->assertNull($row->acknowledged_at);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
        $this->assertSame([null, 30], $this->pushedFetchDelays());
        Queue::assertPushed(FetchInboundDocument::class, fn (FetchInboundDocument $job): bool => $job->attempt === 1 && $job->afterCommit === true);

        // The retry acknowledges without fetching or announcing the document again.
        $this->runFetch($message, 1);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertNotNull($row->acknowledged_at);
        $this->assertNull($row->last_error);
        $this->assertSame(['in-1'], $this->fetched);
        $this->assertSame(['in-1'], $this->acknowledgedIds);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
    }

    public function test_ack_retries_follow_the_backoff_and_an_unknown_outcome_is_retried(): void
    {
        config()->set('einvoicing.sending.backoff', [5, 50]);
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];
        $this->acknowledgements = [
            new TransportUnavailable('down'),
            new OutcomeUnknown('no answer'),
            new TransportUnavailable('down'),
        ];

        $this->runFetch($message);
        $this->runFetch($message, 1);
        $this->runFetch($message, 2);

        $this->assertSame([null, 5, 50, 50], $this->pushedFetchDelays());
        $this->assertSame(InboundState::Fetched, $this->stored($message)->state);
    }

    public function test_ack_failure_on_a_synchronous_connection_is_not_rescheduled(): void
    {
        $this->stubSource();
        $message = $this->notified();
        config()->set('einvoicing.queue.connection', 'sync');
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];
        $this->acknowledgements = [new TransportUnavailable('down')];

        $this->runFetch($message);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Fetched, $row->state);
        $this->assertSame(TransportUnavailable::class.': down', $row->last_error);
        $this->assertSame([null], $this->pushedFetchDelays());
    }

    public function test_unsafe_document_is_not_acknowledged(): void
    {
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [new UnsafeXml('The document carries a DOCTYPE declaration.')];

        $this->runFetch($message);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertNull($row->xml);
        $this->assertSame(1, $row->fetch_attempts);
        $this->assertSame(UnsafeXml::class.': The document carries a DOCTYPE declaration.', $row->last_error);
        $this->assertSame([], $this->acknowledgedIds);
        $this->assertSame([null], $this->pushedFetchDelays());
        Event::assertNotDispatched(DocumentReceived::class);
    }

    public function test_unexpected_fetch_error_is_recorded_and_rethrown(): void
    {
        $this->stubSource();
        $message = $this->notified();
        $this->fetches = [new RuntimeException('Defect.')];

        try {
            $this->runFetch($message);
            $this->fail('The error was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Defect.', $e->getMessage());
        }

        $row = $this->stored($message);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertSame(RuntimeException::class.': Defect.', $row->last_error);
        $this->assertSame([null], $this->pushedFetchDelays());
    }

    public function test_retryable_fetch_failure_reschedules_with_backoff(): void
    {
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [new TransportUnavailable('down'), new TransportUnavailable('down'), $this->document('in-1')];

        $this->runFetch($message);
        $this->assertSame(1, $this->stored($message)->fetch_attempts);
        $this->runFetch($message);
        $this->assertSame(2, $this->stored($message)->fetch_attempts);
        $this->assertSame([null, 30, 120], $this->pushedFetchDelays());

        $this->runFetch($message);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertNull($row->last_error);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
    }

    public function test_retryable_fetch_failure_on_a_synchronous_connection_is_not_rescheduled(): void
    {
        $this->stubSource();
        $message = $this->notified();
        config()->set('einvoicing.queue.connection', 'sync');
        $this->fetches = [new TransportUnavailable('down')];

        $this->runFetch($message);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertSame(TransportUnavailable::class.': down', $row->last_error);
        $this->assertSame([null], $this->pushedFetchDelays());
    }

    public function test_acknowledge_disabled(): void
    {
        config()->set('einvoicing.inbound.acknowledge', false);
        Event::fake([DocumentReceived::class]);
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));

        $this->artisan('einvoicing:poll-inbound')->assertSuccessful();

        $row = InboundMessage::query()->sole();
        $this->assertSame(InboundState::Fetched, $row->state);
        $this->assertNull($row->acknowledged_at);
        $this->assertSame([], $fake->acknowledged());
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
    }

    public function test_poll_command_reports_counts(): void
    {
        config()->set('einvoicing.inbound.acknowledge', false);
        Event::fake([DocumentReceived::class]);
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));
        $fake->receive($this->document('in-2'));
        $fake->receive($this->document('in-3'));
        InboundMessage::query()->create(['transport' => 'recommand', 'provider_document_id' => 'in-2', 'state' => InboundState::Notified]);

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'recommand'])
            ->expectsOutputToContain('2 new, 1 retried, 0 already known')
            ->assertSuccessful();

        $this->assertSame(3, InboundMessage::query()->count());
    }

    public function test_poll_command_fails_on_a_transport_error(): void
    {
        $this->app->make(EInvoicingManager::class)->extend('down', fn (): Transport => new class implements InboundSource, Transport
        {
            public function send(OutboundDocument $document): SendResult
            {
                throw new LogicException('unused');
            }

            public function status(string $documentId): DeliveryStatus
            {
                throw new LogicException('unused');
            }

            public function pending(): array
            {
                throw new TransportUnavailable('Recommand is down.');
            }

            public function fetch(string $documentId): InboundDocument
            {
                throw new LogicException('unused');
            }

            public function acknowledge(string $documentId): void
            {
                throw new LogicException('unused');
            }
        });
        config()->set('einvoicing.transports.down', ['driver' => 'down']);

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'down'])
            ->expectsOutputToContain('Recommand is down.')
            ->assertFailed();

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'missing'])->assertFailed();
        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_inbound_transition_wins_once_and_hides_xml(): void
    {
        $created = InboundMessage::query()->create([
            'transport' => 'recommand',
            'provider_document_id' => 'in-1',
            'state' => InboundState::Notified,
        ]);
        $first = InboundMessage::query()->findOrFail($created->id);
        $second = InboundMessage::query()->findOrFail($created->id);

        $this->assertTrue($first->transition(InboundState::Notified, InboundState::Fetched, ['xml' => '<Invoice/>']));
        $this->assertFalse($second->transition(InboundState::Notified, InboundState::Fetched, ['xml' => '<Other/>']));

        // The losing instance is refreshed to the winner's row.
        $this->assertSame(InboundState::Fetched, $second->state);
        $this->assertSame('<Invoice/>', $second->xml);
        $this->assertSame('<Invoice/>', $this->stored($created)->xml);

        $array = $first->toArray();
        $this->assertArrayNotHasKey('xml', $array);
        $this->assertStringNotContainsString('<Invoice/>', (string) json_encode($first));
        $this->assertSame('fetched', $array['state']);
    }

    public function test_fake_transport_is_used_by_the_fetch_job(): void
    {
        $fake = EInvoicing::fake();
        $this->assertInstanceOf(FakeTransport::class, $this->app->make(EInvoicingManager::class)->inbound());
        $fake->receive($this->document('in-9'));
        Event::fake([DocumentReceived::class]);

        $this->app->make(InboundRecorder::class)->notify('recommand', 'in-9');

        $this->assertSame(InboundState::Acknowledged, InboundMessage::query()->sole()->state);
    }

    public function test_outer_transaction_rollback_stores_and_acknowledges_nothing(): void
    {
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];

        DB::beginTransaction();
        $this->runFetch($message);
        $this->assertSame([], $this->acknowledgedIds);
        Event::assertNotDispatched(DocumentReceived::class);
        DB::rollBack();

        $row = $this->stored($message);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertNull($row->xml);
        $this->assertSame([], $this->acknowledgedIds);
        Event::assertNotDispatched(DocumentReceived::class);
    }

    public function test_fetch_retries_stop_at_max_attempts(): void
    {
        config()->set('einvoicing.sending.max_attempts', 2);
        $this->stubSource();
        $message = $this->notified();
        $this->fetches = [new TransportUnavailable('down'), new TransportUnavailable('down')];

        $this->runFetch($message);
        $this->runFetch($message, 1);

        $row = $this->stored($message);
        $this->assertSame(2, $row->fetch_attempts);
        $this->assertSame(InboundState::Notified, $row->state);
        $this->assertSame(TransportUnavailable::class.': down', $row->last_error);
        $this->assertSame([null, 30], $this->pushedFetchDelays());
    }

    public function test_ack_retries_stop_at_max_attempts_and_count_as_attempts(): void
    {
        config()->set('einvoicing.sending.max_attempts', 2);
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];
        $this->acknowledgements = [new TransportUnavailable('down'), new OutcomeUnknown('no answer')];

        $this->runFetch($message);
        $this->runFetch($message, 1);

        $row = $this->stored($message);
        $this->assertSame(2, $row->fetch_attempts);
        $this->assertSame(InboundState::Fetched, $row->state);
        $this->assertSame(OutcomeUnknown::class.': no answer', $row->last_error);
        $this->assertSame([null, 30], $this->pushedFetchDelays());
    }

    public function test_ack_failure_after_another_job_acknowledged_is_not_rescheduled(): void
    {
        $this->stubSource();
        $message = $this->notified();
        Event::fake([DocumentReceived::class]);
        $this->fetches = [$this->document('in-1')];
        $this->runFetch($message);
        $this->assertSame(InboundState::Acknowledged, $this->stored($message)->state);

        // A stale job that read the row as fetched loses the guarded update after its failure.
        $stale = InboundMessage::queryWithoutXml()->findOrFail($message->id);
        $stale->state = InboundState::Fetched;
        $stale->syncOriginal();
        $this->acknowledgements = [new TransportUnavailable('down')];
        $job = new FetchInboundDocument($message->id);
        $acknowledge = new \ReflectionMethod($job, 'acknowledge');
        $acknowledge->invoke($job, $this->app->make(EInvoicingManager::class)->inbound(), $stale);

        $row = $this->stored($message);
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertNull($row->last_error);
        $this->assertSame([null], $this->pushedFetchDelays());
    }

    public function test_polls_during_an_outage_push_one_job_each(): void
    {
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference];
        $message = $this->notified();
        $message->transition(InboundState::Notified, InboundState::Notified, ['fetch_attempts' => 5]);
        $this->fetches = [new TransportUnavailable('down'), new TransportUnavailable('down')];

        $this->pollWith();
        $this->runPushedFetches();
        $this->pollWith();
        $this->runPushedFetches();

        // The first notification, then exactly one job per poll; the failing jobs add none.
        $this->assertSame([null, null, null], $this->pushedFetchDelays());
        $this->assertSame(7, $this->stored($message)->fetch_attempts);
        $this->assertSame(['in-1', 'in-1'], $this->fetched);
    }

    public function test_lookups_leave_out_the_xml(): void
    {
        $this->stubSource();
        $message = $this->notified();
        $message->transition(InboundState::Notified, InboundState::Fetched, ['xml' => '<Invoice/>']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });

        $this->app->make(InboundRecorder::class)->notify('recommand', 'in-1');
        config()->set('einvoicing.inbound.acknowledge', false);
        $this->runFetch($message);

        $this->assertCount(2, $queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('*', $sql);
            $this->assertStringNotContainsString('"xml"', $sql);
        }
    }

    public function test_poll_retries_a_notified_row_left_by_a_failed_fetch(): void
    {
        Event::fake([DocumentReceived::class]);
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference];
        $message = $this->notified();
        $this->fetches = [new UnsafeXml('refused'), $this->document('in-1')];
        $this->runFetch($message);
        $this->assertSame(InboundState::Notified, $this->stored($message)->state);
        Event::assertNotDispatched(DocumentReceived::class);

        $this->pollWith();
        $this->runPushedFetches();

        $row = $this->stored($message);
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertNull($row->last_error);
        $this->assertSame(['in-1', 'in-1'], $this->fetched);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
    }

    public function test_poll_retries_the_acknowledgement_of_a_fetched_row(): void
    {
        Event::fake([DocumentReceived::class]);
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference];
        $message = $this->notified();
        config()->set('einvoicing.queue.connection', 'sync');
        $this->fetches = [$this->document('in-1')];
        $this->acknowledgements = [new TransportUnavailable('down')];
        $this->runFetch($message);
        $this->assertSame(InboundState::Fetched, $this->stored($message)->state);

        $this->pollWith();
        $this->runPushedFetches();

        $this->assertSame(InboundState::Acknowledged, $this->stored($message)->state);
        $this->assertSame(['in-1'], $this->fetched);
        $this->assertSame(['in-1'], $this->acknowledgedIds);
        Event::assertDispatchedTimes(DocumentReceived::class, 1);
    }

    public function test_poll_does_not_redispatch_an_acknowledged_row(): void
    {
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference];
        $message = $this->notified();
        $message->transition(InboundState::Notified, InboundState::Acknowledged, ['xml' => '<Invoice/>']);

        $this->pollWith('0 new, 0 retried, 1 already known');

        $this->assertSame([null], $this->pushedFetchDelays());
    }

    public function test_notify_survives_a_concurrent_insert(): void
    {
        $this->notified('other');
        $inserted = false;
        // The competing insert lands between the lookup and this process's insert.
        DB::listen(function (QueryExecuted $query) use (&$inserted): void {
            if (! $inserted && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'einvoicing_inbound_documents')) {
                $inserted = true;
                DB::table('einvoicing_inbound_documents')->insert([
                    'transport' => 'recommand',
                    'provider_document_id' => 'in-1',
                    'state' => InboundState::Notified->value,
                    'fetch_attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $message = DB::transaction(fn (): InboundMessage => $this->app->make(InboundRecorder::class)->notify('recommand', 'in-1'));

        $this->assertTrue($inserted);
        $this->assertFalse($message->wasRecentlyCreated);
        $this->assertSame('in-1', $message->provider_document_id);
        $this->assertSame(1, InboundMessage::query()->where('provider_document_id', 'in-1')->count());
        // Only the first notification queued a fetch; the race loser queued none.
        Queue::assertPushed(FetchInboundDocument::class, 1);
    }

    public function test_fetch_attempts_stop_at_the_cap(): void
    {
        config()->set('einvoicing.sending.max_attempts', 5);
        config()->set('einvoicing.inbound.max_fetch_attempts', 3);
        $this->stubSource();
        $message = $this->notified();
        $this->fetches = array_fill(0, 3, new TransportUnavailable('down'));

        $this->runFetch($message);
        $this->runFetch($message, 1);
        $this->runFetch($message, 2);

        // The cap is the whole budget: the third failure reaches it and schedules nothing.
        $this->assertSame(3, $this->stored($message)->fetch_attempts);
        $this->assertSame([null, 30, 120], $this->pushedFetchDelays());

        // A job for a capped row neither fetches nor counts.
        $this->runFetch($message, 3);
        $this->assertSame(['in-1', 'in-1', 'in-1'], $this->fetched);
        $this->assertSame(3, $this->stored($message)->fetch_attempts);
    }

    public function test_cap_outside_its_range_is_refused(): void
    {
        $this->stubSource();
        $message = $this->notified();

        foreach ([0, 65536, 'ten', 2.5] as $cap) {
            config()->set('einvoicing.inbound.max_fetch_attempts', $cap);
            try {
                $this->runFetch($message);
                $this->fail('A cap of '.var_export($cap, true).' was accepted.');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('einvoicing.inbound.max_fetch_attempts', $e->getMessage());
            }
        }
        $this->assertSame([], $this->fetched);
    }

    public function test_invalid_job_setting_on_notify_stores_nothing(): void
    {
        config()->set('einvoicing.sending.max_attempts', 'many');

        try {
            $this->app->make(InboundRecorder::class)->notify('recommand', 'in-1');
            $this->fail('A non-numeric sending.max_attempts was accepted.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('einvoicing.sending.max_attempts', $e->getMessage());
        }

        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_fetch_job_validates_settings_before_fetching(): void
    {
        $this->stubSource();
        $message = $this->notified();
        $this->fetches = [new TransportUnavailable('down')];
        config()->set('einvoicing.sending.max_attempts', 'many');

        try {
            $this->runFetch($message);
            $this->fail('A non-numeric sending.max_attempts was accepted.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('einvoicing.sending.max_attempts', $e->getMessage());
        }

        $this->assertSame([], $this->fetched);
        $this->assertSame(0, $this->stored($message)->fetch_attempts);
    }

    public function test_poll_skips_capped_rows(): void
    {
        config()->set('einvoicing.inbound.max_fetch_attempts', 3);
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference, $this->document('in-2')->reference];
        $capped = $this->notified('in-1');
        $capped->transition(InboundState::Notified, InboundState::Notified, ['fetch_attempts' => 3]);
        $open = InboundMessage::query()->create(['transport' => 'recommand', 'provider_document_id' => 'in-2', 'state' => InboundState::Notified, 'fetch_attempts' => 2]);

        $this->pollWith('0 new, 1 retried, 0 already known, 1 capped');

        $pushed = Queue::pushed(FetchInboundDocument::class)->map(fn (FetchInboundDocument $job): int => $job->messageId)->values()->all();
        // The notification of the capped row, then one retry of the open row only.
        $this->assertSame([$capped->id, $open->id], $pushed);
        $this->assertSame(3, $this->stored($capped)->fetch_attempts);
    }

    public function test_include_capped_retries_once(): void
    {
        Event::fake([DocumentReceived::class]);
        config()->set('einvoicing.inbound.max_fetch_attempts', 3);
        $this->stubSource();
        $this->pendingReferences = [$this->document('in-1')->reference];
        $message = $this->notified();
        $message->transition(InboundState::Notified, InboundState::Notified, ['fetch_attempts' => 3]);
        $this->fetches = [new TransportUnavailable('down')];

        $this->artisan('einvoicing:poll-inbound', ['--include-capped' => true])
            ->expectsOutputToContain('0 new, 1 retried, 0 already known, 0 capped')
            ->assertSuccessful()
            ->run();
        $this->assertSame(2, $this->stored($message)->fetch_attempts);
        $this->runPushedFetches();

        // One more attempt, which fails and brings the row back to the cap without a reschedule.
        $this->assertSame(['in-1'], $this->fetched);
        $this->assertSame(3, $this->stored($message)->fetch_attempts);
        $this->assertSame([null, null], $this->pushedFetchDelays());
    }

    public function test_transition_refresh_does_not_load_xml(): void
    {
        $created = InboundMessage::query()->create([
            'transport' => 'recommand',
            'provider_document_id' => 'in-1',
            'state' => InboundState::Notified,
        ]);
        $created->transition(InboundState::Notified, InboundState::Fetched, ['xml' => '<Invoice/>']);
        $stale = InboundMessage::queryWithoutXml()->findOrFail($created->id);
        $stale->state = InboundState::Notified;
        $stale->syncOriginal();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });

        $this->assertFalse($stale->transition(InboundState::Notified, InboundState::Fetched, ['fetch_attempts' => 1]));

        // The lost race refreshes the columns the model was loaded with, the XML not among them.
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('*', $queries[0]);
        $this->assertStringNotContainsString('"xml"', $queries[0]);
        $this->assertSame(InboundState::Fetched, $stale->state);
        $this->assertSame(0, $stale->fetch_attempts);
        $this->assertArrayNotHasKey('xml', $stale->getAttributes());
        $this->assertFalse($stale->isDirty());
    }

    private function pollWith(string $expected = '0 new, 1 retried, 0 already known'): void
    {
        $this->artisan('einvoicing:poll-inbound')->expectsOutputToContain($expected)->assertSuccessful()->run();
    }

    /**
     * Runs the fetch jobs queued after the first one and not run yet, against the stub source.
     */
    private function runPushedFetches(): void
    {
        $jobs = Queue::pushed(FetchInboundDocument::class)->values();
        while ($this->ranJobs < $jobs->count()) {
            $this->app->call([$jobs[$this->ranJobs++], 'handle']);
        }
    }
}
