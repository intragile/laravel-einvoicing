<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\ExpireSendingClaim;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Syntax\Syntax;
use IntrAgile\EInvoicing\Transport\DeliveryFailure;
use IntrAgile\EInvoicing\Transport\DeliveryState;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\InvalidDocument;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\Exception\RateLimited;
use IntrAgile\EInvoicing\Transport\Exception\RecipientUnreachable;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use IntrAgile\EInvoicing\Transport\Exception\UnexpectedResponse;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\Finding;
use IntrAgile\EInvoicing\Validation\Severity;
use IntrAgile\EInvoicing\Validation\ValidationReport;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class SendFailurePolicyTest extends TestCase
{
    use RefreshDatabase;

    /** SendDocument timeout with the default HTTP settings (60 + 10 + 30), plus 30 seconds. */
    private const EXPIRY_DELAY = 130;

    private FakeTransport $fake;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRecommand();
        $this->fake = EInvoicing::fake();
        Event::fake([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class, DocumentUnconfirmed::class]);
    }

    private function fixture(): string
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        return $xml;
    }

    private function queued(): OutboundMessage
    {
        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($this->fixture()), 'recommand', 'INV-1');
        $message->state = OutboundState::Queued;
        $message->save();

        return $message;
    }

    private function stored(OutboundMessage $message): OutboundMessage
    {
        return OutboundMessage::query()->findOrFail($message->id);
    }

    private function sendJob(OutboundMessage $message, ?EInvoicingManager $manager = null): void
    {
        (new SendDocument($message->id))->handle($manager ?? $this->app->make(EInvoicingManager::class));
    }

    /**
     * A fresh manager whose default transport runs the closure on send, without validation.
     *
     * @param  Closure(OutboundDocument): SendResult  $send
     */
    private function transportThat(Closure $send): EInvoicingManager
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand', ['driver' => 'callback']);
        $this->app->forgetInstance(EInvoicingManager::class);
        EInvoicing::clearResolvedInstances();

        $manager = $this->app->make(EInvoicingManager::class);
        $manager->extend('callback', fn (): Transport => new class($send) implements Transport
        {
            /** @param Closure(OutboundDocument): SendResult $send */
            public function __construct(private readonly Closure $send) {}

            public function send(OutboundDocument $document): SendResult
            {
                return ($this->send)($document);
            }

            public function status(string $documentId): DeliveryStatus
            {
                throw new LogicException('Status is not used here.');
            }
        });

        return $manager;
    }

    /**
     * @return list<int|null>
     */
    private function pushedSendDelays(): array
    {
        return Queue::pushed(SendDocument::class)
            ->map(fn (SendDocument $job): mixed => $job->delay)
            ->values()
            ->all();
    }

    public function test_claim_schedules_its_expiry(): void
    {
        Queue::fake();
        $message = $this->queued();

        $this->sendJob($message);

        $this->assertSame(OutboundState::Delivered, $this->stored($message)->state);
        Queue::assertPushed(ExpireSendingClaim::class, 1);
        Queue::assertPushed(
            ExpireSendingClaim::class,
            fn (ExpireSendingClaim $job): bool => $job->messageId === $message->id
                && $job->attempt === 1
                && $job->delay === self::EXPIRY_DELAY
                && $job->afterCommit === true,
        );
    }

    public function test_outcome_unknown_is_unconfirmed_and_not_rescheduled(): void
    {
        Queue::fake();
        $this->fake->willReturn(new OutcomeUnknown('The request timed out after it was sent.'));
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('The request timed out after it was sent.', $row->failure_message);
        $this->assertNull($row->failure_category);
        $this->assertNull($row->finished_at);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentUnconfirmed::class, fn (DocumentUnconfirmed $e): bool => $e->message->id === $message->id);
        Event::assertNotDispatched(DocumentFailed::class);
        Event::assertNotDispatched(DocumentSent::class);

        // A later job for the same row never sends it again.
        $this->sendJob($message);
        $this->assertCount(1, $this->fake->sent());
    }

    public function test_unexpected_response_is_unconfirmed(): void
    {
        Queue::fake();
        $this->fake->willReturn(new UnexpectedResponse('The response body could not be read (HTTP 200).', 200));
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame('The response body could not be read (HTTP 200).', $row->failure_message);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentUnconfirmed::class);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_runtime_error_is_unconfirmed_and_reported(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = $this->queued();
        $manager = $this->transportThat(fn (): SendResult => throw new RuntimeException('Connection reset by peer'));

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('RuntimeException', (string) $row->failure_message);
        $this->assertStringNotContainsString('Connection reset by peer', (string) $row->failure_message);
        Queue::assertNotPushed(SendDocument::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Connection reset by peer');
        Event::assertDispatched(DocumentUnconfirmed::class);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    /**
     * @return array<string, array{TransportException, FailureCategory}>
     */
    public static function rejections(): array
    {
        $report = new ValidationReport(
            ['peppol 3.0'],
            Syntax::Ubl,
            [new Finding('PEPPOL-EN16931-R001', Severity::Fatal, '/Invoice', 'Business process MUST be provided.', 'cbc:ProfileID')],
            [],
        );

        return [
            'invalid document' => [new InvalidDocument($report), FailureCategory::Validation],
            'document rejected' => [new DocumentRejected('The access point rejected the document.', ['BR-01']), FailureCategory::Validation],
            'recipient unreachable' => [new RecipientUnreachable('Receiver is not registered.', FailureCategory::RecipientNotFound), FailureCategory::RecipientNotFound],
            'authentication failed' => [new AuthenticationFailed('The API key was refused.'), FailureCategory::Other],
        ];
    }

    #[DataProvider('rejections')]
    public function test_rejections_fail_with_category(TransportException $exception, FailureCategory $category): void
    {
        Queue::fake();
        $this->fake->willReturn($exception);
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame($category, $row->failure_category);
        $this->assertSame($exception->getMessage(), $row->failure_message);
        $this->assertNull($row->provider_code);
        $this->assertNull($row->sent_at);
        $this->assertNotNull($row->finished_at);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentFailed::class, fn (DocumentFailed $e): bool => $e->message->id === $message->id);
        Event::assertNotDispatched(DocumentSent::class);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_invalid_argument_from_send_fails(): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(fn (): SendResult => throw new InvalidArgumentException('The document is not valid UTF-8.'));

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Other, $row->failure_category);
        $this->assertSame('The document is not valid UTF-8.', $row->failure_message);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentFailed::class);
    }

    public function test_transport_unavailable_requeues_with_backoff(): void
    {
        Queue::fake();
        $this->fake->willReturn(new TransportUnavailable('Connection refused.'), new TransportUnavailable('Service unavailable (HTTP 503).'));
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('Connection refused.', $row->failure_message);
        $this->assertNull($row->finished_at);
        $this->assertSame([30], $this->pushedSendDelays());
        Queue::assertPushed(SendDocument::class, fn (SendDocument $job): bool => $job->messageId === $message->id && $job->afterCommit === true);

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(2, $row->attempts);
        $this->assertSame('Service unavailable (HTTP 503).', $row->failure_message);
        $this->assertSame([30, 120], $this->pushedSendDelays());
        $this->assertCount(2, $this->fake->sent());
        Event::assertNotDispatched(DocumentFailed::class);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_rate_limited_uses_retry_after(): void
    {
        Queue::fake();
        $this->fake->willReturn(new RateLimited('Too many requests.', 7), new RateLimited('Too many requests.'));
        $message = $this->queued();

        $this->sendJob($message);
        $this->assertSame([7], $this->pushedSendDelays());

        // Without Retry-After the backoff for the second attempt applies.
        $this->sendJob($message);
        $this->assertSame([7, 120], $this->pushedSendDelays());
        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);
    }

    public function test_retries_stop_at_max_attempts(): void
    {
        Queue::fake();
        $this->fake->willReturn(...array_map(
            fn (int $i): TransportUnavailable => new TransportUnavailable("Connection refused ({$i})."),
            range(1, 6),
        ));
        $message = $this->queued();

        for ($i = 1; $i <= 5; $i++) {
            $this->sendJob($message);
        }

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(5, $row->attempts);
        $this->assertSame(FailureCategory::Transport, $row->failure_category);
        $this->assertSame('Connection refused (5).', $row->failure_message);
        $this->assertNotNull($row->finished_at);
        $this->assertSame([30, 120, 600, 1800], $this->pushedSendDelays());
        $this->assertCount(5, $this->fake->sent());
        Event::assertDispatched(DocumentFailed::class, 1);

        $this->sendJob($message);
        $this->assertCount(5, $this->fake->sent());
    }

    public function test_last_backoff_repeats(): void
    {
        Queue::fake();
        config()->set('einvoicing.sending.max_attempts', 4);
        config()->set('einvoicing.sending.backoff', [10, 20]);
        $this->fake->willReturn(
            new TransportUnavailable('down'),
            new TransportUnavailable('down'),
            new TransportUnavailable('down'),
        );
        $message = $this->queued();

        for ($i = 1; $i <= 3; $i++) {
            $this->sendJob($message);
        }

        $this->assertSame([10, 20, 20], $this->pushedSendDelays());
        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);
    }

    public function test_sync_connection_does_not_retry(): void
    {
        Queue::fake();
        config()->set('einvoicing.queue.connection', 'sync');
        $this->fake->willReturn(new TransportUnavailable('Connection refused.'));
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(FailureCategory::Transport, $row->failure_category);
        $this->assertStringContainsString('Connection refused.', (string) $row->failure_message);
        $this->assertStringContainsString('retries need an asynchronous queue connection', (string) $row->failure_message);
        Queue::assertNothingPushed();
        Event::assertDispatched(DocumentFailed::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function synchronousDrivers(): array
    {
        return [
            'deferred' => ['deferred'],
            'background' => ['background'],
        ];
    }

    #[DataProvider('synchronousDrivers')]
    public function test_drivers_that_ignore_delays_do_not_retry(string $driver): void
    {
        Queue::fake();
        config()->set('queue.connections.einvoicing-'.$driver, ['driver' => $driver]);
        config()->set('einvoicing.queue.connection', 'einvoicing-'.$driver);
        $this->fake->willReturn(new TransportUnavailable('Connection refused.'));
        $message = $this->queued();

        $this->sendJob($message);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertStringContainsString('retries need an asynchronous queue connection', (string) $row->failure_message);
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, array{RuntimeException}>
     */
    public static function documentErrors(): array
    {
        return [
            'unsupported document' => [new UnsupportedDocument('The document is not well-formed XML.')],
            'unsafe xml' => [new UnsafeXml('The document declares a DOCTYPE.')],
        ];
    }

    #[DataProvider('documentErrors')]
    public function test_document_errors_before_the_request_fail_as_validation(RuntimeException $error): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(fn (): SendResult => throw $error);

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Validation, $row->failure_category);
        $this->assertSame($error->getMessage(), $row->failure_message);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentFailed::class);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_expired_claim_becomes_unconfirmed(): void
    {
        $message = $this->queued();
        OutboundMessage::query()->whereKey($message->id)->update(['state' => OutboundState::Sending->value, 'attempts' => 1]);

        (new ExpireSendingClaim($message->id, 1))->handle();

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('The worker stopped while sending; check the access point before resending.', $row->failure_message);
        Event::assertDispatched(DocumentUnconfirmed::class, fn (DocumentUnconfirmed $e): bool => $e->message->id === $message->id);
    }

    public function test_expire_ignores_a_newer_claim(): void
    {
        $message = $this->queued();
        OutboundMessage::query()->whereKey($message->id)->update(['state' => OutboundState::Sending->value, 'attempts' => 2]);

        (new ExpireSendingClaim($message->id, 1))->handle();

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Sending, $row->state);
        $this->assertNull($row->failure_message);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_expire_ignores_a_finished_send(): void
    {
        Queue::fake();
        $message = $this->queued();
        $this->sendJob($message);

        (new ExpireSendingClaim($message->id, 1))->handle();
        (new ExpireSendingClaim(999, 1))->handle();

        $this->assertSame(OutboundState::Delivered, $this->stored($message)->state);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_send_job_is_never_retried_by_the_queue(): void
    {
        $this->assertSame(1, (new SendDocument(1))->tries);
        $this->assertSame(1, (new ExpireSendingClaim(1, 1))->tries);
    }

    public function test_missing_rules_keeps_message_queued(): void
    {
        Queue::fake();
        $message = $this->queued();
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());
        $this->app->forgetInstance(EInvoicingManager::class);
        EInvoicing::clearResolvedInstances();

        try {
            $this->sendJob($message);
            $this->fail('A transport without its rules was used.');
        } catch (RulesNotInstalled) {
        }

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(0, $row->attempts);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_stale_claim_does_not_send(): void
    {
        Queue::fake();
        $message = $this->queued();

        // Between the job's read and its claim, another job claims the row and requeues it.
        $raced = false;
        OutboundMessage::retrieved(function (OutboundMessage $read) use (&$raced): void {
            if (! $raced) {
                $raced = true;
                OutboundMessage::query()->whereKey($read->id)->update(['attempts' => 1]);
            }
        });

        try {
            $this->sendJob($message);
        } finally {
            OutboundMessage::flushEventListeners();
        }

        $this->assertTrue($raced);
        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame([], $this->fake->sent());
        Queue::assertNotPushed(SendDocument::class);

        // The expiry is scheduled before the claim; the stray one never matches a row in sending.
        Queue::assertPushed(ExpireSendingClaim::class, 1);
        (new ExpireSendingClaim($message->id, 1))->handle();
        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);
        Event::assertNotDispatched(DocumentUnconfirmed::class);
    }

    public function test_failed_expiry_dispatch_leaves_the_row_unclaimed_and_unsent(): void
    {
        config()->set('queue.connections.broken', ['driver' => 'no-such-driver']);
        config()->set('einvoicing.queue.connection', 'broken');
        $message = $this->queued();

        try {
            $this->sendJob($message);
            $this->fail('The expiry was not scheduled, yet the send went ahead.');
        } catch (InvalidArgumentException) {
        }

        // The expiry is scheduled before the claim, so a failure leaves the row as it was.
        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(0, $row->attempts);
        $this->assertSame([], $this->fake->sent());
    }

    public function test_late_answer_resolves_unconfirmed_row(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            // The claim expires while the request is in flight.
            (new ExpireSendingClaim($message->id, 1))->handle();

            return new SendResult('doc-9', DeliveryState::Delivered, 'msg-9', 'env-9');
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Delivered, $row->state);
        $this->assertSame('doc-9', $row->provider_document_id);
        $this->assertSame('msg-9', $row->peppol_message_id);
        $this->assertSame('env-9', $row->envelope_id);
        $this->assertNull($row->failure_message);
        $this->assertNotNull($row->sent_at);
        $this->assertNotNull($row->finished_at);
        Event::assertDispatched(DocumentUnconfirmed::class, 1);
        Event::assertDispatched(DocumentSent::class, 1);
        Event::assertDispatched(DocumentDelivered::class, 1);
        Exceptions::assertNothingReported();
    }

    public function test_late_failed_result_resolves_unconfirmed_row(): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            return new SendResult('doc-10', DeliveryState::Failed, failure: new DeliveryFailure(FailureCategory::RecipientRejected, 'Refused', 'R1'));
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::RecipientRejected, $row->failure_category);
        $this->assertSame('Refused', $row->failure_message);
        $this->assertSame('R1', $row->provider_code);
        Event::assertDispatched(DocumentFailed::class, 1);
    }

    public function test_late_rejection_resolves_unconfirmed_row(): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            throw new DocumentRejected('The access point rejected the document.');
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(FailureCategory::Validation, $row->failure_category);
        Event::assertDispatched(DocumentFailed::class, 1);
    }

    public function test_late_retryable_failure_keeps_unconfirmed_row(): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            throw new TransportUnavailable('Connection refused.');
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        Queue::assertNotPushed(SendDocument::class);
        Event::assertDispatched(DocumentUnconfirmed::class, 1);
        Event::assertNotDispatched(DocumentFailed::class);
    }

    public function test_losing_requeue_reports_the_answer(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            throw new TransportUnavailable('Connection refused.');
        });

        $this->sendJob($message, $manager);

        // The row stays with the operator, who learns that the document was not accepted.
        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);
        Queue::assertNotPushed(SendDocument::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), (string) $message->id)
            && str_contains($e->getMessage(), 'not accepted')
            && str_contains($e->getMessage(), 'Connection refused.'));
    }

    public function test_losing_definite_failure_reports_the_answer(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            // A newer claim owns the row by the time the rejection arrives.
            OutboundMessage::query()->whereKey($message->id)->update(['state' => OutboundState::Sending->value, 'attempts' => 2]);

            throw new DocumentRejected('The access point rejected the document.');
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Sending, $row->state);
        $this->assertSame(2, $row->attempts);
        Event::assertNotDispatched(DocumentFailed::class);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'not accepted')
            && str_contains($e->getMessage(), 'rejected the document'));
    }

    public function test_revert_failure_keeps_the_original_exception(): void
    {
        $message = $this->queued();
        $original = new RuntimeException('The queue is unreachable.');
        Bus::shouldReceive('dispatch')->andThrow($original);

        try {
            $this->sendJob($message);
            $this->fail('The send went ahead without its expiry.');
        } catch (RuntimeException $caught) {
            // Nothing was claimed, so there is nothing to revert that could replace the error.
            $this->assertSame($original, $caught);
        }

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(0, $row->attempts);
        $this->assertSame([], $this->fake->sent());
    }

    public function test_mid_flow_setting_is_validated_before_the_claim(): void
    {
        Queue::fake();
        $message = $this->queued();
        $sent = 0;
        $manager = $this->transportThat(function () use (&$sent): SendResult {
            $sent++;

            throw new TransportUnavailable('Connection refused.');
        });
        // Read only after a refused send, yet checked before anything is claimed or sent.
        config()->set('einvoicing.sending.max_attempts', 'many');

        try {
            $this->sendJob($message, $manager);
            $this->fail('A non-numeric sending.max_attempts was accepted.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('einvoicing.sending.max_attempts', $e->getMessage());
        }

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(0, $row->attempts);
        $this->assertSame(0, $sent);
        Queue::assertNothingPushed();
    }

    public function test_late_unknown_outcome_fires_unconfirmed_once(): void
    {
        Queue::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            throw new OutcomeUnknown('The request timed out.');
        });

        $this->sendJob($message, $manager);

        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);
        Event::assertDispatched(DocumentUnconfirmed::class, 1);
    }

    public function test_answer_for_a_superseded_claim_is_reported(): void
    {
        Queue::fake();
        Exceptions::fake();
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            // The claim expired and an operator already resent the document under a new claim.
            OutboundMessage::query()->whereKey($message->id)->update(['state' => OutboundState::Unconfirmed->value, 'attempts' => 2]);

            return new SendResult('doc-11', DeliveryState::Delivered);
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertNull($row->provider_document_id);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'doc-11'));
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_accepted_id_already_recorded_elsewhere_settles_unconfirmed_and_is_reported(): void
    {
        Queue::fake();
        Exceptions::fake();
        $other = $this->queued();
        OutboundMessage::query()->whereKey($other->id)->update(['state' => OutboundState::Delivered->value, 'provider_document_id' => 'doc-dup']);
        $message = $this->queued();
        $manager = $this->transportThat(fn (): SendResult => new SendResult('doc-dup', DeliveryState::Pending));

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->provider_document_id);
        $this->assertStringContainsString('doc-dup', (string) $row->failure_message);
        $this->assertNull($row->finished_at);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'doc-dup')
            && ! str_contains($e->getMessage(), self::SECRET_MARK));
        Event::assertDispatched(DocumentUnconfirmed::class, 1);
        Event::assertNotDispatched(DocumentSent::class);
        Queue::assertNotPushed(SendDocument::class);
        $this->assertSame('doc-dup', $this->stored($other)->provider_document_id);
    }

    public function test_accepted_id_already_recorded_after_expired_claim_stays_unconfirmed(): void
    {
        Queue::fake();
        Exceptions::fake();
        $other = $this->queued();
        OutboundMessage::query()->whereKey($other->id)->update(['state' => OutboundState::Delivered->value, 'provider_document_id' => 'doc-dup']);
        $message = $this->queued();
        $manager = $this->transportThat(function () use ($message): SendResult {
            (new ExpireSendingClaim($message->id, 1))->handle();

            return new SendResult('doc-dup', DeliveryState::Delivered);
        });

        $this->sendJob($message, $manager);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertNull($row->provider_document_id);
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'doc-dup'));
        Event::assertNotDispatched(DocumentSent::class);
        Event::assertNotDispatched(DocumentDelivered::class);
    }

    private function useSqs(string $queue = 'einvoicing'): void
    {
        config()->set('queue.connections.sqs', ['driver' => 'sqs', 'queue' => $queue, 'region' => 'eu-west-1']);
        config()->set('einvoicing.queue.connection', 'sqs');
    }

    public function test_delays_are_capped_at_the_sqs_maximum(): void
    {
        Queue::fake();
        $this->useSqs();
        config()->set('einvoicing.sending.backoff', [1800]);
        $this->fake->willReturn(new TransportUnavailable('Connection refused.'), new RateLimited('Too many requests.', 3600));
        $message = $this->queued();

        $this->sendJob($message);
        $this->sendJob($message);

        $this->assertSame([900, 900], $this->pushedSendDelays());
        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);

        PollDeliveryStatus::schedule($message->id, 21600, 2);
        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->delay === 900);

        // Shorter delays are left alone.
        ExpireSendingClaim::schedule($message->id, 130, 2);
        Queue::assertPushed(ExpireSendingClaim::class, fn (ExpireSendingClaim $job): bool => $job->delay === 130 && $job->attempt === 2);
    }

    public function test_delays_are_not_capped_on_other_drivers(): void
    {
        Queue::fake();
        config()->set('einvoicing.sending.backoff', [1800]);
        $this->fake->willReturn(new TransportUnavailable('Connection refused.'));
        $message = $this->queued();

        $this->sendJob($message);
        PollDeliveryStatus::schedule($message->id, 21600, 1);

        $this->assertSame([1800], $this->pushedSendDelays());
        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->delay === 21600);
    }
}
