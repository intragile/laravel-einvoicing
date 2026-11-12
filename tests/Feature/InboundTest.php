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

    /** @var list<string> */
    private array $fetched = [];

    /** @var list<string> */
    private array $acknowledgedIds = [];

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
                return [];
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
            ->expectsOutputToContain('1 new, 0 already known')
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

    public function test_repeated_poll_fires_one_event(): void
    {
        config()->set('einvoicing.inbound.acknowledge', false);
        Event::fake([DocumentReceived::class]);
        $fake = EInvoicing::fake();
        $fake->receive($this->document('in-1'));

        $this->artisan('einvoicing:poll-inbound')->expectsOutputToContain('1 new, 0 already known')->assertSuccessful();
        $this->artisan('einvoicing:poll-inbound')->expectsOutputToContain('0 new, 1 already known')->assertSuccessful();

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
            ->expectsOutputToContain('2 new, 1 already known')
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
}
