<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordAccepted;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRejected;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuSubmissionBlocked;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\AeatCertificates;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeAeatApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\DatabaseChainStore;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\Verifactu\CancellationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\Chainer;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\Fingerprint;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\Submission;
use IntrAgile\EInvoicing\Spain\Verifactu\SubmissionHeader;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\SafeXml;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Syntax\Verifactu\VerifactuFormat;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\Aeat\AeatFormat;
use IntrAgile\EInvoicing\Transport\Aeat\DuplicateState;
use IntrAgile\EInvoicing\Transport\Aeat\FlowControl;
use IntrAgile\EInvoicing\Transport\Aeat\RecordOutcome;
use IntrAgile\EInvoicing\Transport\Aeat\SoapEnvelope;
use IntrAgile\EInvoicing\Transport\Aeat\SubmissionResponse;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleState;
use IntrAgile\EInvoicing\Validation\SchemaValidator;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SubmitVerifactuRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-24T10:00:00Z';

    private const OTHER_ISSUER = VerifactuSamples::SANDBOX_ISSUER_NIF;

    private FakeAeatApi $aeat;

    private VerifactuSchemas $schemas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        $dir = VerifactuFiles::schemasDir();
        $this->schemas = new VerifactuSchemas($dir);
        config()->set('einvoicing.verifactu.schemas_dir', $dir);
        config()->set('einvoicing.verifactu.issuers', [
            VerifactuSamples::ISSUER_NIF => ['name' => 'Test Issuer S.L.'],
            self::OTHER_ISSUER => ['name' => 'Other Issuer S.L.'],
        ]);
        // Never read: the AEAT client is bound below.
        config()->set('einvoicing.verifactu.aeat.certificate', '/nonexistent/'.self::SECRET_MARK.'.p12');
        config()->set('einvoicing.verifactu.aeat.password', 'pw-'.self::SECRET_MARK);
        config()->set('queue.default', 'database');

        $this->aeat = new FakeAeatApi;
        $this->app->instance(AeatClient::class, $this->aeat->client($this->schemas));
        Queue::fake();
    }

    public function test_takes_records_in_sequence_order_up_to_max_batch(): void
    {
        config()->set('einvoicing.verifactu.submission.max_batch', 3);
        $rows = array_map(fn (int $n): VerifactuRecord => $this->generate(sprintf('F-%04d', $n)), range(1, 5));
        $this->aeat->queue(FakeAeatApi::answer($this->lines(array_slice($rows, 0, 3))));

        $this->submit();

        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(['F-0001', 'F-0002', 'F-0003'], $this->aeat->sentNumbers(0));
        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Accepted, VerifactuRecordState::Accepted, VerifactuRecordState::Generated, VerifactuRecordState::Generated], $rows);
        $this->assertScheduled(60);
    }

    public function test_one_request_per_issuer_per_run(): void
    {
        $a1 = $this->generate('A-1');
        $this->travel(1)->seconds();
        $b1 = $this->generate('B-1', self::OTHER_ISSUER);
        $a2 = $this->generate('A-2');
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$a1, $a2])));

        $this->submit();

        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(VerifactuSamples::ISSUER_NIF, $this->aeat->sentIssuer(0));
        $this->assertSame(['A-1', 'A-2'], $this->aeat->sentNumbers(0));
        $this->assertSame(VerifactuRecordState::Generated, $b1->fresh()?->state);

        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$b1])));
        $this->submit();

        $this->assertSame(2, $this->aeat->requestCount());
        $this->assertSame(self::OTHER_ISSUER, $this->aeat->sentIssuer(1));
        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Accepted, VerifactuRecordState::Accepted], [$a1, $a2, $b1]);
    }

    public function test_waits_for_next_send_at_unless_1000_pending(): void
    {
        $row = $this->generate('W-1');
        $this->throttle()->store(new ThrottleState(Carbon::now()->subSeconds(10)->toDateTimeImmutable(), 60));

        $this->submit();

        $this->assertSame(0, $this->aeat->requestCount());
        $this->assertSame(VerifactuRecordState::Generated, $row->fresh()?->state);
        $this->assertScheduled(50);

        // 1,000 records pending, but max_batch cuts the request to 2: the wait is kept.
        config()->set('einvoicing.verifactu.submission.max_batch', 2);
        $store = $this->store(new RecordWriter);
        for ($n = 2; $n <= 1000; $n++) {
            $this->generate(sprintf('W-%d', $n), store: $store);
        }
        Queue::fake();

        $this->submit();

        $this->assertSame(0, $this->aeat->requestCount());
        $this->assertScheduled(50);

        // A request that carries 1,000 records does not wait.
        config()->set('einvoicing.verifactu.submission.max_batch', 1000);
        $this->aeat->queue(fn (RequestInterface $request): ResponseInterface => FakeAeatApi::answer(array_map(
            static fn (string $number): array => FakeAeatApi::line(VerifactuSamples::invoiceId($number, '01-01-2024')),
            $this->aeat->sentNumbers(0),
        )));

        $this->submit();

        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertCount(1000, $this->aeat->sentNumbers(0));
        $this->assertSame(0, VerifactuRecord::query()->where('state', VerifactuRecordState::Generated)->count());
    }

    public function test_more_than_max_batch_pending_waits_between_batches(): void
    {
        config()->set('einvoicing.verifactu.submission.max_batch', 2);
        $rows = array_map(fn (int $n): VerifactuRecord => $this->generate(sprintf('B-%d', $n)), [1, 2, 3]);
        $this->aeat->queue(FakeAeatApi::answer($this->lines(array_slice($rows, 0, 2))));

        $this->submit();

        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(['B-1', 'B-2'], $this->aeat->sentNumbers(0));
        // The rest follows after AEAT's wait, not at once.
        $this->assertScheduled(60);
        $this->submit();
        $this->assertSame(1, $this->aeat->requestCount());
    }

    public function test_issuer_scope_throttles_separately(): void
    {
        config()->set('einvoicing.verifactu.submission.throttle_scope', 'issuer');
        $a = $this->generate('A-1', scope: ThrottleScope::Issuer);
        $b = $this->generate('B-1', self::OTHER_ISSUER, scope: ThrottleScope::Issuer);
        $keyA = $this->key(VerifactuSamples::ISSUER_NIF, ThrottleScope::Issuer);
        $keyB = $this->key(self::OTHER_ISSUER, ThrottleScope::Issuer);
        $this->assertNotSame($keyA, $keyB);
        $this->throttle($keyA)->store(new ThrottleState(Carbon::now()->subSeconds(10)->toDateTimeImmutable(), 60));
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$b])));

        $this->submit($keyA);
        $this->assertSame(0, $this->aeat->requestCount());

        $this->submit($keyB);
        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(self::OTHER_ISSUER, $this->aeat->sentIssuer(0));
        $this->assertSame(VerifactuRecordState::Generated, $a->fresh()?->state);
        $this->assertSame(VerifactuRecordState::Accepted, $b->fresh()?->state);
        $this->assertSame(Carbon::now()->getTimestamp(), $this->throttle($keyB)->last_sent_at?->getTimestamp());
    }

    public function test_correcto_stores_csv_and_states_together(): void
    {
        Event::fake([VerifactuRecordAccepted::class]);
        $rows = [$this->generate('C-1'), $this->generate('C-2')];
        $this->aeat->queue(FakeAeatApi::answer($this->lines($rows), csv: 'CSV0000000000001'));

        $this->submit();

        foreach ($rows as $row) {
            $row = $row->fresh();
            $this->assertNotNull($row);
            $this->assertSame(VerifactuRecordState::Accepted, $row->state);
            $this->assertSame('CSV0000000000001', $row->csv);
            $this->assertSame(1, $row->attempts);
            $this->assertSame(Carbon::now()->getTimestamp(), $row->submitted_at?->getTimestamp());
            $this->assertNull($row->error_code);
            $this->assertNull($row->error_action);
        }
        Event::assertDispatchedTimes(VerifactuRecordAccepted::class, 2);
        Event::assertDispatched(VerifactuRecordAccepted::class, fn (VerifactuRecordAccepted $event): bool => ! $event->withErrors && $event->errorCode === null);
    }

    public function test_aceptado_con_errores_is_accepted_with_code(): void
    {
        Event::fake([VerifactuRecordAccepted::class]);
        $row = $this->generate('E-1');
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'aceptado_con_errores')]));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::AcceptedWithErrors, $row->state);
        $this->assertSame(FakeAeatApi::CSV, $row->csv);
        $this->assertSame('2005', $row->error_code);
        $this->assertSame('CorrectAcceptedRecord', $row->error_action);
        $this->assertNotNull($row->error_message);
        Event::assertDispatched(VerifactuRecordAccepted::class, fn (VerifactuRecordAccepted $event): bool => $event->withErrors && $event->errorCode === '2005' && $event->record->is($row));
    }

    public function test_incorrecto_is_rejected_and_stays_in_chain(): void
    {
        Event::fake([VerifactuRecordRejected::class]);
        $row = $this->generate('R-1');
        $head = VerifactuChain::query()->sole();
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'incorrecto', code: 1100)], csv: null));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Rejected, $row->state);
        $this->assertNull($row->csv);
        $this->assertSame('1100', $row->error_code);
        $this->assertSame(1, $row->sequence);
        $chain = VerifactuChain::query()->sole();
        $this->assertSame($head->head_hash, $chain->head_hash);
        $this->assertSame($row->hash, $chain->head_hash);
        $this->assertSame(1, $chain->sequence);
        Event::assertDispatched(VerifactuRecordRejected::class, fn (VerifactuRecordRejected $event): bool => $event->errorCode === '1100' && $event->errorMessage === $row->error_message);
    }

    public function test_rejected_record_stores_error_action(): void
    {
        $row = $this->generate('R-2');
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'incorrecto', code: 1142)], csv: null));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Rejected, $row->state);
        $this->assertSame('1142', $row->error_code);
        $this->assertSame('RegenerateAfterRejection', $row->error_action);
    }

    public function test_duplicate_correcta_is_accepted_without_new_csv(): void
    {
        $row = $this->generate('D-1');
        $this->aeat->queue(FakeAeatApi::noAnswer(...));
        $this->submit();
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->fresh()?->state);

        // The resend carries the same bytes, so AEAT's duplicate answer proves this record.
        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'duplicado_correcta')], csv: null));
        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Accepted, $row->state);
        $this->assertNull($row->csv);
        $this->assertSame(2, $row->attempts);
    }

    public function test_duplicate_aceptada_con_errores_on_resend_is_accepted_with_errors(): void
    {
        Event::fake([VerifactuRecordAccepted::class]);
        $row = $this->generate('D-4');
        $this->aeat->queue(FakeAeatApi::noAnswer(...));
        $this->submit();

        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'duplicado_aceptada_con_errores')], csv: null));
        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::AcceptedWithErrors, $row->state);
        Event::assertDispatched(VerifactuRecordAccepted::class, fn (VerifactuRecordAccepted $event): bool => $event->record->is($row) && $event->withErrors);
    }

    public function test_confirmed_resend_duplicate_clears_the_error(): void
    {
        $row = $this->generate('D-5');
        $this->aeat->queue(FakeAeatApi::serverFault());
        $this->submit();
        // A failure answered earlier left its code on the record.
        $row->forceFill(['error_code' => '4110', 'error_message' => 'earlier', 'error_action' => 'RetryLater'])->save();

        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'duplicado_correcta')], csv: null));
        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Accepted, $row->state);
        $this->assertNull($row->error_code);
        $this->assertNull($row->error_message);
        $this->assertNull($row->error_action);
    }

    public function test_duplicate_correcta_on_first_send_is_unconfirmed_for_lookup(): void
    {
        Event::fake([VerifactuRecordUnconfirmed::class, VerifactuRecordAccepted::class]);
        $row = $this->generate('D-2');
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'duplicado_correcta')], csv: null));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
        $this->assertSame('3000', $row->error_code);
        $this->assertSame('ResolveDuplicate', $row->error_action);
        Event::assertDispatched(VerifactuRecordUnconfirmed::class, fn (VerifactuRecordUnconfirmed $event): bool => $event->record->is($row));
        Event::assertNotDispatched(VerifactuRecordAccepted::class);

        // AEAT holds another record for this invoice; a resend would prove nothing, so none is made.
        $this->travel(3)->hours();
        $this->submit();
        $this->assertSame(1, $this->aeat->requestCount());
    }

    public function test_duplicate_anulada_is_rejected_already_cancelled(): void
    {
        Event::fake([VerifactuRecordRejected::class]);
        $row = $this->generate('D-3');
        $this->aeat->queue(FakeAeatApi::answer([FakeAeatApi::line($row->invoiceId(), 'duplicado_anulada')], csv: null));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Rejected, $row->state);
        $this->assertSame('3001', $row->error_code);
        Event::assertDispatched(VerifactuRecordRejected::class);
    }

    public function test_cancellation_answered_already_cancelled_is_accepted(): void
    {
        $alta = $this->generate('X-1');
        $anulacion = $this->generate('X-1', cancel: true);
        $this->aeat->queue(FakeAeatApi::answer([
            FakeAeatApi::line($alta->invoiceId()),
            FakeAeatApi::line($anulacion->invoiceId(), 'duplicado_anulada', 'Anulacion'),
        ]));

        $this->submit();

        $this->assertSame(['X-1', 'X-1'], $this->aeat->sentNumbers(0));
        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Accepted], [$alta, $anulacion]);
        $this->assertNull($anulacion->fresh()?->csv);
    }

    public function test_outcome_unknown_resends_the_same_bytes(): void
    {
        $rows = [$this->generate('U-1'), $this->generate('U-2')];
        $this->aeat->queue(FakeAeatApi::serverFault());

        $this->submit();

        foreach ($rows as $row) {
            $row = $row->fresh();
            $this->assertNotNull($row);
            $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
            $this->assertSame(1, $row->attempts);
            $this->assertSame(Carbon::now()->getTimestamp(), $row->submitted_at?->getTimestamp());
        }
        $throttle = $this->throttle();
        $this->assertEquals((new FlowControl)->afterFailure(new ThrottleState(null, 60), Carbon::now()->toDateTimeImmutable()), $throttle->state());

        // Not before the backoff (30 s) and the wait (60 s) have passed.
        $this->travel(30)->seconds();
        $this->submit();
        $this->assertSame(1, $this->aeat->requestCount());

        $this->travel(30)->seconds();
        $this->aeat->queue(FakeAeatApi::answer($this->lines($rows)));
        $this->submit();

        $this->assertSame(2, $this->aeat->requestCount());
        $first = $this->aeat->sentRecords(0);
        $second = $this->aeat->sentRecords(1);
        $this->assertCount(2, $second);
        foreach ($rows as $i => $row) {
            $row = $row->fresh();
            $this->assertNotNull($row);
            $this->assertSame(VerifactuRecordState::Accepted, $row->state);
            $this->assertSame($first[$i]->C14N(), $second[$i]->C14N());

            // The bytes sent are the bytes stored: the hashed fields and the Huella match the
            // stored XML, whose fingerprint is the stored hash.
            $stored = SafeXml::loadPreservingWhitespace((string) $row->xml)->documentElement;
            $this->assertInstanceOf(DOMElement::class, $stored);
            $this->assertSame(Fingerprint::inputOf($stored), Fingerprint::inputOf($second[$i]));
            $this->assertSame($row->hash, self::huella($second[$i]));
            $this->assertSame($row->hash, Fingerprint::recompute($second[$i]));
        }
    }

    public function test_transient_client_fault_is_unconfirmed_and_resent(): void
    {
        $row = $this->generate('T-1');
        $this->aeat->queue(FakeAeatApi::clientFault(4110));

        $this->submit();

        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->fresh()?->state);
        $this->assertNull($this->throttle()->blocked_at);

        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$row])));
        $this->submit();

        $this->assertSame(2, $this->aeat->requestCount());
        $this->assertSame($this->aeat->sentRecords(0)[0]->C14N(), $this->aeat->sentRecords(1)[0]->C14N());
        $this->assertSame(VerifactuRecordState::Accepted, $row->fresh()?->state);
    }

    public function test_transport_unavailable_returns_to_generated(): void
    {
        // The real clients: the certificate cannot be written, so nothing is sent.
        $this->app->forgetInstance(AeatClient::class);
        $this->useRealCertificate();
        $storage = $this->emptyDir();
        $this->app->useStoragePath($storage);
        mkdir($storage.'/einvoicing/verifactu', 0700, true);
        file_put_contents($storage.'/einvoicing/verifactu/tls', 'not a folder');
        $row = $this->generate('N-1');

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(0, $row->attempts);
        $throttle = $this->throttle();
        $this->assertNull($throttle->blocked_at);
        $this->assertSame(Carbon::now()->getTimestamp(), $throttle->last_sent_at?->getTimestamp());
        $this->assertScheduled(60);
    }

    public function test_client_fault_blocks_without_rejecting(): void
    {
        Event::fake([VerifactuSubmissionBlocked::class]);
        $row = $this->generate('B-1');
        $this->aeat->queue(FakeAeatApi::clientFault(4104));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(0, $row->attempts);
        $this->assertSame('4104', $row->error_code);
        $this->assertSame('FixConfiguration', $row->error_action);
        $throttle = $this->throttle();
        $this->assertNotNull($throttle->blocked_at);
        $this->assertSame('4104', $throttle->blocked_code);
        $this->assertStringContainsString('4104', (string) $throttle->blocked_message);
        Event::assertDispatched(VerifactuSubmissionBlocked::class, fn (VerifactuSubmissionBlocked $event): bool => $event->throttleKey === $this->key() && $event->code === '4104');
        Queue::assertNothingPushed();

        // Blocked until an operator clears it.
        $this->travel(1)->hours();
        $this->submit();
        $this->assertSame(1, $this->aeat->requestCount());
    }

    public function test_authentication_failed_blocks(): void
    {
        Event::fake([VerifactuSubmissionBlocked::class]);
        $row = $this->generate('B-2');
        $this->aeat->queue(FakeAeatApi::authenticationFault());

        $this->submit();

        $this->assertSame(VerifactuRecordState::Generated, $row->fresh()?->state);
        $this->assertSame('4112', $row->fresh()?->error_code);
        $this->assertSame('4112', $this->throttle()->blocked_code);
        Event::assertDispatched(VerifactuSubmissionBlocked::class, fn (VerifactuSubmissionBlocked $event): bool => $event->code === '4112');
    }

    public function test_unknown_issuer_at_send_time_blocks(): void
    {
        Event::fake([VerifactuSubmissionBlocked::class]);
        $row = $this->generate('K-1');
        $before = $row->fresh()?->getAttributes();
        config()->set('einvoicing.verifactu.issuers', [self::OTHER_ISSUER => ['name' => 'Other Issuer S.L.']]);

        $this->submit();

        $this->assertSame(0, $this->aeat->requestCount());
        $this->assertSame($before, $row->fresh()?->getAttributes());
        $throttle = $this->throttle();
        $this->assertSame('unknown_issuer', $throttle->blocked_code);
        $this->assertSame('No issuer data for '.VerifactuSamples::ISSUER_NIF.'.', $throttle->blocked_message);
        Event::assertDispatched(VerifactuSubmissionBlocked::class, fn (VerifactuSubmissionBlocked $event): bool => $event->code === 'unknown_issuer');
    }

    public function test_context_of_another_environment_blocks(): void
    {
        $row = $this->generate('K-2');
        $resolver = new class implements VerifactuIssuerResolver
        {
            public function resolve(Nif $issuer): IssuerContext
            {
                return new IssuerContext($issuer, 'Test Issuer S.L.', IssuerDriver::Aeat, VerifactuEnvironment::Production, new AeatCredentials('/nonexistent.p12', null, null, CertificateKind::Personal));
            }

            public function issuers(): array
            {
                return [];
            }
        };
        $this->app->instance(VerifactuIssuerResolver::class, $resolver);

        $this->submit();

        $this->assertSame(0, $this->aeat->requestCount());
        $this->assertSame(VerifactuRecordState::Generated, $row->fresh()?->state);
        $throttle = $this->throttle();
        $this->assertSame('configuration', $throttle->blocked_code);
        $this->assertStringContainsString('environment', (string) $throttle->blocked_message);
    }

    public function test_next_send_at_from_tiempo_espera_envio(): void
    {
        $this->assertSame('UTC', date_default_timezone_get());
        $row = $this->generate('S-1');
        $answer = FakeAeatApi::body($this->lines([$row]), 120);
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$row]), 120));

        $this->submit();

        $now = Carbon::now()->toDateTimeImmutable();
        $response = $this->readResponse($answer);
        $expected = (new FlowControl)->afterResponse(new ThrottleState(null, 60), $response, $now);
        $throttle = $this->throttle();
        $this->assertEquals($expected, $throttle->state());
        $this->assertSame(120, $throttle->wait_seconds);
        $this->assertSame($expected->nextSendAt()?->getTimestamp(), $throttle->next_send_at?->getTimestamp());
        $this->assertSame($now->getTimestamp() + 120, $throttle->next_send_at?->getTimestamp());
        Queue::assertNothingPushed();
    }

    public function test_initial_wait_applies_to_the_throttle_row(): void
    {
        config()->set('einvoicing.verifactu.submission.initial_wait', 90);
        $row = $this->generate('I-1');
        $this->aeat->queue(FakeAeatApi::serverFault());

        $this->submit();

        $throttle = $this->throttle();
        $this->assertSame(90, $throttle->wait_seconds);
        $this->assertSame(Carbon::now()->getTimestamp() + 90, $throttle->next_send_at?->getTimestamp());
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->fresh()?->state);
    }

    /**
     * @return iterable<string, array{RecordOutcome, bool, bool, VerifactuRecordState}>
     */
    public static function outcomes(): iterable
    {
        $table = [
            'Accepted' => [VerifactuRecordState::Accepted, VerifactuRecordState::Accepted],
            'AcceptedWithErrors' => [VerifactuRecordState::AcceptedWithErrors, VerifactuRecordState::AcceptedWithErrors],
            'Rejected' => [VerifactuRecordState::Rejected, VerifactuRecordState::Rejected],
            'AlreadyRegistered' => [VerifactuRecordState::Accepted, VerifactuRecordState::Accepted],
            'AlreadyCancelled' => [VerifactuRecordState::Rejected, VerifactuRecordState::Accepted],
            'Unconfirmed' => [VerifactuRecordState::Unconfirmed, VerifactuRecordState::Unconfirmed],
        ];
        foreach ($table as $case => [$alta, $anulacion]) {
            $outcome = constant(RecordOutcome::class.'::'.$case);
            yield "{$case} alta" => [$outcome, false, true, $alta];
            yield "{$case} anulacion" => [$outcome, true, true, $anulacion];
        }
        // A duplicate answer to a first send does not prove that AEAT holds this record.
        yield 'AlreadyRegistered alta, first send' => [RecordOutcome::AlreadyRegistered, false, false, VerifactuRecordState::Unconfirmed];
        yield 'AlreadyRegistered anulacion, first send' => [RecordOutcome::AlreadyRegistered, true, false, VerifactuRecordState::Unconfirmed];
        yield 'Accepted alta, first send' => [RecordOutcome::Accepted, false, false, VerifactuRecordState::Accepted];
        // A resend that AEAT already holds as accepted with errors keeps that state.
        yield 'AlreadyRegistered AceptadaConErrores, resend' => [RecordOutcome::AlreadyRegistered, false, true, VerifactuRecordState::AcceptedWithErrors, DuplicateState::AceptadaConErrores];
        yield 'AlreadyRegistered Correcta, resend' => [RecordOutcome::AlreadyRegistered, false, true, VerifactuRecordState::Accepted, DuplicateState::Correcta];
        yield 'AlreadyRegistered AceptadaConErrores, first send' => [RecordOutcome::AlreadyRegistered, false, false, VerifactuRecordState::Unconfirmed, DuplicateState::AceptadaConErrores];
    }

    #[DataProvider('outcomes')]
    public function test_state_follows_record_outcome(RecordOutcome $outcome, bool $cancellation, bool $resend, VerifactuRecordState $expected, ?DuplicateState $duplicate = null): void
    {
        $this->assertSame($expected, VerifactuRecordState::fromOutcome($outcome, $cancellation, $resend, $duplicate));
    }

    public function test_unmatched_line_keeps_csv_and_is_logged(): void
    {
        Log::spy();
        $row = $this->generate('M-1');
        $this->aeat->queue(FakeAeatApi::answer([
            FakeAeatApi::line($row->invoiceId()),
            FakeAeatApi::line(VerifactuSamples::invoiceId('NOT-SENT', '01-01-2024')),
        ]));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Accepted, $row->state);
        $this->assertSame(FakeAeatApi::CSV, $row->csv);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no record of the submission') && $context['number'] === 'NOT-SENT');
    }

    public function test_rate_limited_returns_to_generated_after_retry_after(): void
    {
        $row = $this->generate('L-1');
        $this->aeat->queue(FakeAeatApi::rateLimited(300));

        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(0, $row->attempts);
        $throttle = $this->throttle();
        $this->assertNull($throttle->blocked_at);
        $this->assertEquals(new ThrottleState(Carbon::now()->toDateTimeImmutable(), 300), $throttle->state());
        $this->assertSame(Carbon::now()->getTimestamp() + 300, $throttle->next_send_at?->getTimestamp());
        $this->assertScheduled(300);

        // Without Retry-After the last wait is kept.
        $this->travel(300)->seconds();
        $this->aeat->queue(FakeAeatApi::rateLimited(null));
        $this->submit();
        $this->assertSame(300, $this->throttle()->wait_seconds);
    }

    public function test_attempts_cap_leaves_unconfirmed_and_fires_event(): void
    {
        Event::fake([VerifactuRecordUnconfirmed::class]);
        config()->set('einvoicing.sending.max_attempts', 2);
        $row = $this->generate('P-1');
        $this->aeat->queue(FakeAeatApi::serverFault(), FakeAeatApi::noAnswer(...));

        $this->submit();
        Event::assertNotDispatched(VerifactuRecordUnconfirmed::class);

        $this->travel(60)->seconds();
        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
        $this->assertSame(2, $row->attempts);
        Event::assertDispatchedTimes(VerifactuRecordUnconfirmed::class, 1);

        $this->travel(1)->days();
        $this->submit();
        $this->assertSame(2, $this->aeat->requestCount());
    }

    public function test_db_failure_after_response_resends_and_duplicate_is_accepted(): void
    {
        $rows = [$this->generate('Z-1'), $this->generate('Z-2')];
        $count = VerifactuRecord::query()->count();
        $this->aeat->queue(FakeAeatApi::answer($this->lines($rows)));
        VerifactuRecord::updating(static function (): void {
            throw new RuntimeException('The database connection was lost.');
        });

        $thrown = null;
        try {
            $this->submit();
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertSame('The database connection was lost.', $thrown?->getMessage());
        foreach ($rows as $row) {
            $row = $row->fresh();
            $this->assertNotNull($row);
            $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
            $this->assertNull($row->csv);
            $this->assertSame(1, $row->attempts);
        }
        $this->assertSame(Carbon::now()->getTimestamp(), $this->throttle()->last_sent_at?->getTimestamp());
        $this->assertScheduled(60);

        VerifactuRecord::flushEventListeners();
        $this->travel(60)->seconds();
        $this->aeat->queue(FakeAeatApi::answer(array_map(fn (VerifactuRecord $row): array => FakeAeatApi::line($row->invoiceId(), 'duplicado_correcta'), $rows), csv: null));
        $this->submit();

        $this->assertSame($this->aeat->sentRecords(0)[1]->C14N(), $this->aeat->sentRecords(1)[1]->C14N());
        foreach ($rows as $row) {
            $row = $row->fresh();
            $this->assertNotNull($row);
            $this->assertSame(VerifactuRecordState::Accepted, $row->state);
            $this->assertNull($row->csv);
        }
        $this->assertSame($count, VerifactuRecord::query()->count());
        $this->assertSame(2, VerifactuChain::query()->sole()->sequence);
    }

    public function test_concurrent_runs_claim_disjoint_records(): void
    {
        config()->set('einvoicing.verifactu.submission.max_batch', 2);
        $rows = [$this->generate('Q-1'), $this->generate('Q-2'), $this->generate('Q-3')];
        $seen = null;
        $this->aeat->queue(function () use ($rows, &$seen): ResponseInterface {
            // A second submitter starts while the first request is in flight.
            $seen = [$rows[0]->fresh()?->state, $rows[1]->fresh()?->state, $rows[2]->fresh()?->state];
            $this->submit();

            return FakeAeatApi::answer($this->lines([$rows[0], $rows[1]]));
        });

        $this->submit();

        $this->assertSame([VerifactuRecordState::Sending, VerifactuRecordState::Sending, VerifactuRecordState::Generated], $seen);
        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(['Q-1', 'Q-2'], $this->aeat->sentNumbers(0));
        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Accepted, VerifactuRecordState::Generated], $rows);
    }

    public function test_a_run_that_read_records_before_another_claimed_them_sends_none(): void
    {
        $rows = [$this->generate('Q-4'), $this->generate('Q-5')];
        $this->aeat->queue(FakeAeatApi::answer($this->lines($rows)));
        $armed = true;
        // The outer run has read the rows (with their XML) as generated; before it claims them,
        // another run claims and sends them.
        VerifactuRecord::retrieved(function (VerifactuRecord $row) use (&$armed): void {
            if ($armed && array_key_exists('xml', $row->getAttributes())) {
                $armed = false;
                $this->submit();
            }
        });

        $this->submit();

        $this->assertFalse($armed);
        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(['Q-4', 'Q-5'], $this->aeat->sentNumbers(0));
        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Accepted], $rows);
        $this->assertSame(1, $rows[0]->fresh()?->attempts);
    }

    public function test_a_run_that_decided_before_another_sent_rechecks_the_throttle(): void
    {
        $rows = [$this->generate('Q-6'), $this->generate('Q-7')];
        $armed = true;
        // The outer run has decided to send and read the rows; before it claims them, another run
        // sends a submission and records it on the throttle.
        VerifactuRecord::retrieved(function (VerifactuRecord $row) use (&$armed): void {
            if ($armed && array_key_exists('xml', $row->getAttributes())) {
                $armed = false;
                $this->throttle()->store(new ThrottleState(Carbon::now()->toDateTimeImmutable(), 60));
            }
        });

        $this->submit();

        $this->assertFalse($armed);
        $this->assertSame(0, $this->aeat->requestCount());
        $this->assertStates([VerifactuRecordState::Generated, VerifactuRecordState::Generated], $rows);
        $this->assertSame(0, $rows[0]->fresh()?->attempts);
        $this->assertScheduled(60);
    }

    public function test_a_throwing_listener_does_not_cancel_the_next_run(): void
    {
        config()->set('einvoicing.verifactu.submission.max_batch', 1);
        $rows = [$this->generate('E-1'), $this->generate('E-2')];
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$rows[0]])));
        Event::listen(VerifactuRecordAccepted::class, static function (): void {
            throw new RuntimeException('listener failed');
        });

        try {
            $this->submit();
            $this->fail('The listener exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('listener failed', $exception->getMessage());
        }

        $this->assertStates([VerifactuRecordState::Accepted, VerifactuRecordState::Generated], $rows);
        $this->assertScheduled(60);
    }

    public function test_a_failure_before_the_request_keeps_state_and_attempts(): void
    {
        Event::fake([VerifactuSubmissionBlocked::class]);
        $row = $this->generate('H-1');
        $this->aeat->queue(FakeAeatApi::noAnswer(...));
        $this->submit();
        $this->assertSame(1, $row->fresh()?->attempts);

        // The submission header cannot be built: NombreRazon is over 120 characters.
        config()->set('einvoicing.verifactu.issuers.'.VerifactuSamples::ISSUER_NIF.'.name', str_repeat('N', 121));
        $this->app->forgetInstance(VerifactuIssuerResolver::class);
        $this->travel(60)->seconds();
        $this->submit();

        $row = $row->fresh();
        $this->assertNotNull($row);
        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('invalid_record', $row->error_code);
        Event::assertDispatched(VerifactuSubmissionBlocked::class, fn (VerifactuSubmissionBlocked $event): bool => $event->code === 'invalid_record');
    }

    public function test_job_payload_and_exceptions_carry_no_secret(): void
    {
        Event::fake([VerifactuSubmissionBlocked::class, VerifactuRecordAccepted::class]);
        $this->useRealCertificate('wrong-'.self::SECRET_MARK);
        $this->app->forgetInstance(AeatClient::class);
        $this->app->useStoragePath($this->emptyDir());
        $this->generate('Y-1');

        SubmitVerifactuRecords::schedule($this->key());
        $this->submit();

        Queue::assertPushed(SubmitVerifactuRecords::class, function (SubmitVerifactuRecords $job): bool {
            $this->assertStringNotContainsString(self::SECRET_MARK, serialize($job));
            $this->assertStringNotContainsString(self::SECRET_MARK, (string) json_encode($job));

            return true;
        });
        $throttle = $this->throttle();
        $this->assertSame('configuration', $throttle->blocked_code);
        $this->assertStringNotContainsString(self::SECRET_MARK, (string) $throttle->blocked_message);
        $this->assertStringNotContainsString(self::SECRET_MARK, (string) json_encode(VerifactuRecord::query()->get()->toArray()));
        Event::assertDispatched(VerifactuSubmissionBlocked::class, function (VerifactuSubmissionBlocked $event): bool {
            $this->assertStringNotContainsString(self::SECRET_MARK, serialize($event));

            return true;
        });
    }

    public function test_due_records_left_are_rescheduled_from_inside_handle(): void
    {
        // A real queue, so the unique lock behaves as on a worker.
        $this->app->forgetInstance('queue');
        Queue::clearResolvedInstances();
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config()->set('einvoicing.verifactu.submission.max_batch', 1);
        $rows = [$this->generate('J-1'), $this->generate('J-2')];
        $this->aeat->queue(FakeAeatApi::answer($this->lines([$rows[0]])), FakeAeatApi::answer($this->lines([$rows[1]])));

        SubmitVerifactuRecords::schedule($this->key());
        // While one job is queued, another schedule is dropped.
        SubmitVerifactuRecords::schedule($this->key());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame(VerifactuRecordState::Accepted, $rows[0]->fresh()?->state);
        $next = DB::table('jobs')->sole();
        $this->assertSame(Carbon::now()->getTimestamp() + 60, (int) $next->available_at);

        $this->travel(60)->seconds();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame(VerifactuRecordState::Accepted, $rows[1]->fresh()?->state);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(2, $this->aeat->requestCount());
    }

    public function test_job_takes_queue_and_timeout_from_settings(): void
    {
        config()->set('einvoicing.queue.connection', 'redis');
        config()->set('einvoicing.queue.name', 'einvoicing');
        $job = new SubmitVerifactuRecords($this->key());
        $this->assertSame('redis', $job->connection);
        $this->assertSame('einvoicing', $job->queue);

        config()->set('einvoicing.verifactu.submission.connection', 'database');
        config()->set('einvoicing.verifactu.submission.queue', 'verifactu');
        config()->set('einvoicing.verifactu.aeat.timeout', 45);
        $job = new SubmitVerifactuRecords($this->key());
        $this->assertSame('database', $job->connection);
        $this->assertSame('verifactu', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertGreaterThan(45, (int) $job->timeout);
        $this->assertSame(sha1($this->key()), $job->uniqueId());
        $this->assertGreaterThanOrEqual(1800, $job->uniqueFor());
    }

    #[Group('verifactu-official')]
    public function test_fake_answers_are_schema_valid(): void
    {
        $invoice = VerifactuSamples::invoiceId('V-1', '01-01-2024');
        $other = VerifactuSamples::invoiceId('V-2', '01-01-2024');
        $bodies = [
            FakeAeatApi::body([FakeAeatApi::line($invoice), FakeAeatApi::line($other)]),
            FakeAeatApi::body([FakeAeatApi::line($invoice, 'aceptado_con_errores'), FakeAeatApi::line($other, 'incorrecto', code: 1142)]),
            FakeAeatApi::body([FakeAeatApi::line($invoice, 'incorrecto', code: 1100)], csv: null),
            FakeAeatApi::body([FakeAeatApi::line($invoice, 'duplicado_correcta')], csv: null),
            FakeAeatApi::body([FakeAeatApi::line($invoice, 'duplicado_anulada', 'Anulacion')], csv: null),
            FakeAeatApi::body([FakeAeatApi::line($invoice)], 120, 'CSV0000000000001'),
        ];

        foreach ($bodies as $index => $body) {
            $payload = SoapEnvelope::open($body);
            $this->assertInstanceOf(DOMElement::class, $payload);
            $this->assertSame(AeatFormat::RESPONSE_NS, $payload->namespaceURI);
            $document = new DOMDocument;
            $document->appendChild($document->importNode($payload, true));
            $errors = (new SchemaValidator)->validate($document, $this->schemas->responseXsd(), $this->schemas->localPrefixes());
            $this->assertSame([], array_map(static fn ($error): string => $error->message, $errors), "answer {$index}");
        }
    }

    private function submit(?string $key = null): void
    {
        $job = new SubmitVerifactuRecords($key ?? $this->key());
        $this->app->call([$job, 'handle']);
        // The test runs the job by hand, so release the unique lock a worker releases.
        $this->app->make(Cache::class)->lock(UniqueLock::getKey($job))->forceRelease();
    }

    private function key(string $issuer = VerifactuSamples::ISSUER_NIF, ThrottleScope $scope = ThrottleScope::Installation): string
    {
        return ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test, $scope, $scope === ThrottleScope::Issuer ? Nif::parse($issuer) : null)->toString();
    }

    private function throttle(?string $key = null): VerifactuThrottle
    {
        return VerifactuThrottle::query()->where('key', $key ?? $this->key())->sole();
    }

    private function store(?RecordWriter $writer = null, ThrottleScope $scope = ThrottleScope::Installation): DatabaseChainStore
    {
        return new DatabaseChainStore(
            DB::connection(),
            $writer ?? new RecordWriter($this->schemas),
            static fn (ChainKey $key): ThrottleKey => ThrottleKey::for(VerifactuSamples::system(), $key->environment, $scope, $scope === ThrottleScope::Issuer ? $key->issuer : null),
        );
    }

    private function generate(string $number, string $issuer = VerifactuSamples::ISSUER_NIF, bool $cancel = false, ?DatabaseChainStore $store = null, ThrottleScope $scope = ThrottleScope::Installation): VerifactuRecord
    {
        $store ??= $this->store(scope: $scope);
        $key = ChainKey::for(Nif::parse($issuer), VerifactuSamples::system(), VerifactuEnvironment::Test);
        $chainer = new Chainer(VerifactuSamples::system());
        $draft = $cancel
            ? CancellationDraft::of(VerifactuSamples::invoiceId($number, '01-01-2024', $issuer))
            : VerifactuSamples::vectorDraft($number, '01-01-2024', $issuer);
        $throttle = ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test, $scope, $scope === ThrottleScope::Issuer ? Nif::parse($issuer) : null);

        return $store->appendRow($key, IssuerDriver::Aeat, $throttle, static fn (?ChainHead $head) => $chainer->append($draft, $head, Carbon::now()->toDateTimeImmutable())[0]);
    }

    /**
     * Correcto lines for the given rows.
     *
     * @param  list<VerifactuRecord>  $rows
     * @return list<array{InvoiceId, string, string, ?int}>
     */
    private function lines(array $rows): array
    {
        return array_map(static fn (VerifactuRecord $row): array => FakeAeatApi::line($row->invoiceId()), $rows);
    }

    /**
     * @param  list<VerifactuRecordState>  $expected
     * @param  list<VerifactuRecord>  $rows
     */
    private function assertStates(array $expected, array $rows): void
    {
        $this->assertSame($expected, array_map(static fn (VerifactuRecord $row): ?VerifactuRecordState => $row->fresh()?->state, $rows));
    }

    private function assertScheduled(int $delay): void
    {
        Queue::assertPushed(SubmitVerifactuRecords::class, fn (SubmitVerifactuRecords $job): bool => $job->throttleKey === $this->key() && $job->delay === $delay);
    }

    private function useRealCertificate(string $password = 'pw-'.self::SECRET_MARK): void
    {
        $issued = AeatCertificates::client(['commonName' => 'AEAT TEST CLIENT']);
        $path = $this->emptyDir().'/issuer.p12';
        file_put_contents($path, AeatCertificates::pkcs12($issued['certificate'], $issued['privateKey'], 'pw-'.self::SECRET_MARK));
        config()->set('einvoicing.verifactu.aeat.certificate', $path);
        config()->set('einvoicing.verifactu.aeat.password', $password);
    }

    private function readResponse(string $body): SubmissionResponse
    {
        $client = (new FakeAeatApi)->queue(new Response(200, [], $body))->client($this->schemas);
        $row = VerifactuRecord::query()->firstOrFail();

        return $client->submit(new Submission(
            new SubmissionHeader(Nif::parse($row->issuer_nif), 'Test Issuer S.L.'),
            [(new RecordReader)->read((string) $row->xml)],
        ));
    }

    private static function huella(DOMElement $record): string
    {
        $xpath = new DOMXPath($record->ownerDocument ?? new DOMDocument);
        $xpath->registerNamespace('sf', VerifactuFormat::SF_NS);

        return trim((string) $xpath->evaluate('string(sf:Huella)', $record));
    }
}
