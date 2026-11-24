<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordGenerated;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\SpanishInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\DatabaseChainStore;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\Verifactu\AltaOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\AnulacionOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainStore;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\MappingRefused;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\Fingerprint;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordMapper;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\SafeXml;
use IntrAgile\EInvoicing\Validation\SchemaValidator;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

final class VerifactuManagerTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-24T09:30:00Z';

    private const ISSUER = SpanishInvoices::SELLER_NIF;

    private const QR_PREFIX = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B64829179&numserie=PROBE-ES-UBL-0002&fecha=23-11-2026&importe=121.00';

    private const THROTTLE = '383|test';

    private string $schemasDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        $this->schemasDir = VerifactuFiles::schemasDir();
        config()->set('einvoicing.verifactu.schemas_dir', $this->schemasDir);
        config()->set('einvoicing.verifactu.system', [
            'name' => 'Test Producer S.L.',
            'nif' => 'A39200019',
            'foreign_id' => ['type' => null, 'id' => null, 'country' => null],
            'system_name' => 'TestSIF',
            'system_id' => '77',
            'version' => '1.0.03',
            'installation_number' => '383',
            'verifactu_only' => true,
            'multiple_taxpayers_possible' => false,
            'multiple_taxpayers_indicator' => false,
        ]);
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => SpanishInvoices::SELLER_NAME]]);
        // Never read: nothing is sent in these tests.
        config()->set('einvoicing.verifactu.aeat.certificate', '/nonexistent/'.self::SECRET_MARK.'.p12');
        config()->set('einvoicing.verifactu.aeat.password', 'pw-'.self::SECRET_MARK);
        Queue::fake();
    }

    #[Group('verifactu-official')]
    public function test_register_maps_chains_stores_and_returns_qr(): void
    {
        Event::fake([VerifactuRecordGenerated::class]);

        $row = $this->manager()->register(SpanishInvoices::domestic(), 'order-42');

        $this->assertTrue($row->exists);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(VerifactuRecordKind::Registration, $row->kind);
        $this->assertSame(IssuerDriver::Aeat, $row->driver);
        $this->assertSame(VerifactuEnvironment::Test, $row->environment);
        $this->assertSame('order-42', $row->reference);
        $this->assertSame(1, $row->sequence);
        $this->assertNull($row->previous_hash);
        $this->assertSame(0, $row->attempts);
        $this->assertSame(self::ISSUER, $row->issuer_nif);
        $this->assertSame(SpanishInvoices::NUMBER, $row->number);
        $this->assertSame('2026-11-24T10:30:00+01:00', $row->generated_at);
        $this->assertNotNull($row->qr_url);
        $this->assertStringStartsWith(self::QR_PREFIX, $row->qr_url);

        // The stored XML is schema-valid and hashes to the stored fingerprint.
        $this->assertNotNull($row->xml);
        $document = SafeXml::loadPreservingWhitespace($row->xml);
        $schemas = new VerifactuSchemas($this->schemasDir);
        $errors = (new SchemaValidator)->validate($document, $schemas->informationXsd(), $schemas->localPrefixes());
        $this->assertSame([], array_map(static fn ($error): string => $error->message, $errors));
        $this->assertNotNull($document->documentElement);
        $this->assertSame($row->hash, Fingerprint::recompute($document->documentElement));

        // The row, the chain head and the throttle row are stored.
        $stored = VerifactuRecord::query()->sole();
        $this->assertSame($row->id, $stored->id);
        $this->assertSame($row->xml, $stored->xml);
        $chain = VerifactuChain::query()->sole();
        $this->assertSame($row->hash, $chain->head_hash);
        $this->assertSame(self::THROTTLE, $chain->throttle_key);
        $this->assertSame([self::THROTTLE], VerifactuThrottle::query()->pluck('key')->all());

        Event::assertDispatched(VerifactuRecordGenerated::class, static fn (VerifactuRecordGenerated $event): bool => $event->record->id === $row->id);
        Queue::assertPushed(SubmitVerifactuRecords::class, static fn (SubmitVerifactuRecords $job): bool => $job->throttleKey === self::THROTTLE);
    }

    public function test_register_inside_rolled_back_transaction_dispatches_nothing(): void
    {
        // A real queue: the fake records a job at once, whatever the transaction does.
        $this->app->forgetInstance('queue');
        Queue::clearResolvedInstances();
        config()->set('queue.default', 'database');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $fired = 0;
        Event::listen(VerifactuRecordGenerated::class, static function () use (&$fired): void {
            $fired++;
        });

        try {
            DB::transaction(function (): void {
                $this->manager()->register(SpanishInvoices::domestic());

                throw new LogicException('roll back');
            });
        } catch (LogicException) {
        }

        $this->assertSame(0, VerifactuRecord::query()->count());
        $this->assertNull(VerifactuChain::query()->first()?->head_hash);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, $fired);

        // Committed, the same call queues the job and fires the event once.
        $this->manager()->register(SpanishInvoices::domestic());

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, $fired);
    }

    public function test_mapping_refused_stores_nothing(): void
    {
        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::usd()));

        $this->assertInstanceOf(MappingRefused::class, $exception);
        $this->assertNotSame([], $exception->problems);
        $this->assertStringStartsWith('BT-5:', $exception->problems[0]);
        $this->assertNothingStored();
    }

    public function test_unknown_issuer_stores_nothing(): void
    {
        config()->set('einvoicing.verifactu.issuers', ['89890001K' => ['name' => 'Another Issuer S.L.']]);

        $this->assertInstanceOf(UnknownIssuer::class, $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic())));
        $this->assertInstanceOf(UnknownIssuer::class, $this->catch(fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord)));
        $this->assertNothingStored();
    }

    public function test_schemas_missing_refuses_before_any_write(): void
    {
        config()->set('einvoicing.verifactu.schemas_dir', $this->emptyDir());

        foreach ([
            fn () => $this->manager()->register(SpanishInvoices::domestic()),
            fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction)),
            fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord),
            // The schemas are checked before the invoice is mapped.
            fn () => $this->manager()->register(SpanishInvoices::usd()),
        ] as $index => $call) {
            $exception = $this->catch($call);
            $this->assertInstanceOf(ConfigurationException::class, $exception, "call {$index}");
            $this->assertStringContainsString('einvoicing:verifactu-install-schemas', $exception->getMessage());
        }
        $this->assertNothingStored();
    }

    public function test_register_refuses_non_initial_operation_and_duplicate_id(): void
    {
        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domesticAs(AltaOperation::Correction)));
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('correct()', $exception->getMessage());
        $this->assertNothingStored();

        $first = $this->manager()->register(SpanishInvoices::domestic());
        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('already', $exception->getMessage());
        $this->assertStringContainsString('correct()', $exception->getMessage());
        $this->assertSame([$first->id], VerifactuRecord::query()->pluck('id')->all());
        $this->assertSame(1, VerifactuChain::query()->sole()->sequence);
    }

    public function test_cancel_appends_anulacion_linked_to_head(): void
    {
        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $this->travel(5)->seconds();
        Queue::fake();

        $row = $this->manager()->cancel($this->invoiceId(), 'cancel-1');

        $this->assertSame(VerifactuRecordKind::Cancellation, $row->kind);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(2, $row->sequence);
        $this->assertSame($alta->hash, $row->previous_hash);
        $this->assertSame('cancel-1', $row->reference);
        $this->assertNull($row->qr_url);
        $this->assertSame($row->hash, VerifactuChain::query()->sole()->head_hash);
        $this->assertNotNull($row->xml);
        $this->assertSame([], $this->texts($row->xml, 'SinRegistroPrevio'));
        Queue::assertPushed(SubmitVerifactuRecords::class, static fn (SubmitVerifactuRecords $job): bool => $job->throttleKey === self::THROTTLE);
    }

    public function test_cancel_without_prior_record_needs_the_operation(): void
    {
        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId()));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('AnulacionOperation::WithoutPriorRecord', $exception->getMessage());
        $this->assertSame(0, VerifactuRecord::query()->count());

        $row = $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord);

        $this->assertSame(VerifactuRecordKind::Cancellation, $row->kind);
        $this->assertSame(1, $row->sequence);
        $this->assertNotNull($row->xml);
        $this->assertSame(['S'], $this->texts($row->xml, 'SinRegistroPrevio'));
    }

    public function test_correct_after_rejection_appends_s_and_x(): void
    {
        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $alta->forceFill(['state' => VerifactuRecordState::Rejected, 'error_code' => '1100'])->save();
        $this->travel(1)->minutes();
        Queue::fake();

        $row = $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::AfterRejection), 'fix-1');

        $this->assertSame(VerifactuRecordKind::Registration, $row->kind);
        $this->assertSame(VerifactuRecordState::Generated, $row->state);
        $this->assertSame(2, $row->sequence);
        $this->assertSame($alta->hash, $row->previous_hash);
        $this->assertSame('fix-1', $row->reference);
        $this->assertNotNull($row->xml);
        $this->assertSame(['S'], $this->texts($row->xml, 'Subsanacion'));
        $this->assertSame(['X'], $this->texts($row->xml, 'RechazoPrevio'));
        $this->assertNotNull($row->qr_url);
        $this->assertStringStartsWith(self::QR_PREFIX, $row->qr_url);
        // The rejected record stays in the chain as it was.
        $this->assertSame(VerifactuRecordState::Rejected, $alta->fresh()?->state);
        Queue::assertPushed(SubmitVerifactuRecords::class);
    }

    public function test_cancel_after_rejection_without_prior_record_is_refused(): void
    {
        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::AfterRejection));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('AnulacionOperation::WithoutPriorRecord', $exception->getMessage());
        $this->assertSame(0, VerifactuRecord::query()->count());
    }

    public function test_cancel_of_a_previous_systems_invoice_on_a_seeded_chain_is_allowed_when_asked(): void
    {
        $this->seedChain();

        // Without the option the missing local registration record is refused, naming the option.
        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId()));
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('registeredByPreviousSystem', $exception->getMessage());

        $row = $this->manager()->cancel($this->invoiceId(), registeredByPreviousSystem: true);
        $this->assertSame(VerifactuRecordKind::Cancellation, $row->kind);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(str_repeat('B', 64), $row->previous_hash);
        $this->assertNotNull($row->xml);
        $this->assertSame([], $this->texts($row->xml, 'SinRegistroPrevio'));

        $this->travel(1)->seconds();
        $row->forceFill(['state' => VerifactuRecordState::Rejected])->save();
        $this->assertSame(2, $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::AfterRejection, registeredByPreviousSystem: true)->sequence);
    }

    public function test_previous_system_option_is_refused_on_an_unseeded_chain(): void
    {
        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId(), registeredByPreviousSystem: true));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('registeredByPreviousSystem', $exception->getMessage());
        $this->assertStringContainsString('not seeded', $exception->getMessage());
        $this->assertSame(0, VerifactuRecord::query()->count());
    }

    public function test_normal_cancel_whose_only_registration_was_rejected_is_refused(): void
    {
        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $alta->forceFill(['state' => VerifactuRecordState::Rejected])->save();
        $this->travel(1)->seconds();

        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId()));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('rejected', $exception->getMessage());
        $this->assertStringContainsString('AnulacionOperation::WithoutPriorRecord', $exception->getMessage());
        $this->assertSame(2, $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord)->sequence);
    }

    public function test_cancel_without_prior_record_is_refused_while_an_accepted_registration_exists(): void
    {
        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $this->travel(1)->seconds();
        // Not settled yet: AEAT may still reject it, so the flag is the caller's call.
        $this->assertSame(2, $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord)->sequence);
        VerifactuRecord::query()->where('sequence', 2)->update(['state' => VerifactuRecordState::Rejected->value]);
        $alta->forceFill(['state' => VerifactuRecordState::AcceptedWithErrors])->save();
        $this->travel(1)->seconds();

        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecordAfterRejection));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('accepted', $exception->getMessage());
        $this->assertSame(2, VerifactuRecord::query()->count());
    }

    public function test_second_cancel_after_an_accepted_cancellation_is_refused(): void
    {
        $this->manager()->register(SpanishInvoices::domestic())->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $this->travel(1)->seconds();
        $cancellation = $this->manager()->cancel($this->invoiceId());
        $this->travel(1)->seconds();
        // A pending cancellation does not settle anything yet.
        $this->assertSame(3, $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::AfterRejection)->sequence);
        $cancellation->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $this->travel(1)->seconds();

        $exception = $this->catch(fn () => $this->manager()->cancel($this->invoiceId()));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('already cancelled', $exception->getMessage());
        $this->assertSame(3, VerifactuRecord::query()->count());
    }

    public function test_correct_after_rejection_is_refused_while_the_latest_registration_is_accepted(): void
    {
        $this->manager()->register(SpanishInvoices::domestic())->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $this->travel(1)->seconds();

        $exception = $this->catch(fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::AfterRejection)));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('accepted', $exception->getMessage());
        $this->assertStringContainsString('AltaOperation::Correction', $exception->getMessage());
        $this->assertSame(1, VerifactuRecord::query()->count());
    }

    public function test_correction_is_refused_while_the_latest_registration_is_rejected(): void
    {
        $this->manager()->register(SpanishInvoices::domestic())->forceFill(['state' => VerifactuRecordState::Rejected])->save();
        $this->travel(1)->seconds();

        $exception = $this->catch(fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction)));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('rejected', $exception->getMessage());
        $this->assertStringContainsString('AltaOperation::AfterRejection', $exception->getMessage());
        $this->assertSame(1, VerifactuRecord::query()->count());
    }

    public function test_correction_of_an_accepted_registration_writes_s_without_rechazo_previo(): void
    {
        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $alta->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $this->travel(1)->seconds();

        $row = $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction));

        $this->assertSame(2, $row->sequence);
        $this->assertSame($alta->hash, $row->previous_hash);
        $this->assertNotNull($row->xml);
        $this->assertSame(['S'], $this->texts($row->xml, 'Subsanacion'));
        $this->assertSame([], $this->texts($row->xml, 'RechazoPrevio'));
    }

    public function test_correct_without_prior_alta_is_refused(): void
    {
        $exception = $this->catch(fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction)));
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('register()', $exception->getMessage());

        // A cancellation is not an earlier registration.
        $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord);
        $this->assertInstanceOf(InvalidArgumentException::class, $this->catch(fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction))));

        // An initial operation is register()'s.
        $this->manager()->register(SpanishInvoices::domestic());
        $exception = $this->catch(fn () => $this->manager()->correct(SpanishInvoices::domestic()));
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('AltaOperation::Initial', $exception->getMessage());

        $this->assertSame(2, VerifactuRecord::query()->count());
    }

    public function test_record_returns_latest(): void
    {
        $this->assertNull($this->manager()->record($this->invoiceId()));

        $alta = $this->manager()->register(SpanishInvoices::domestic());
        $this->assertSame($alta->id, $this->manager()->record($this->invoiceId())?->id);

        $this->travel(1)->seconds();
        $cancellation = $this->manager()->cancel($this->invoiceId());
        $this->assertSame($cancellation->id, $this->manager()->record($this->invoiceId())?->id);

        // Another environment has its own records.
        config()->set('einvoicing.verifactu.environment', 'production');
        $this->assertNull($this->manager()->record($this->invoiceId()));
        // Another invoice of the same issuer.
        $this->assertNull($this->manager()->record(new InvoiceId(Nif::parse(self::ISSUER), 'OTHER-1', new DateTimeImmutable(SpanishInvoices::ISSUE_DATE))));
    }

    public function test_driver_switch_is_refused_naming_close_chain(): void
    {
        $chain = $this->b2brouterChain(sequence: 1, headHash: str_repeat('A', 64));

        foreach ([
            fn () => $this->manager()->register(SpanishInvoices::domestic()),
            fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord),
        ] as $call) {
            $exception = $this->catch($call);
            $this->assertInstanceOf(ConfigurationException::class, $exception);
            $this->assertStringContainsString('einvoicing:verifactu-close-chain', $exception->getMessage());
        }
        $this->assertSame(0, VerifactuRecord::query()->count());

        // A closed chain of the other driver is the documented cut-over.
        $chain->forceFill(['closed_at' => Carbon::now()])->save();
        $this->assertSame(1, $this->manager()->register(SpanishInvoices::domestic())->sequence);
    }

    public function test_unused_chain_row_of_the_other_driver_is_no_chain(): void
    {
        // A chain row whose first write failed (sequence 0, no head) holds nothing.
        $this->b2brouterChain(sequence: 0, headHash: null);

        $this->assertSame(1, $this->manager()->register(SpanishInvoices::domestic())->sequence);
    }

    public function test_timestamp_in_configured_zone(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01T10:00:00Z'));

        $row = $this->manager()->register(SpanishInvoices::domestic());

        $this->assertSame('2026-07-01T12:00:00+02:00', $row->generated_at);
        $this->assertSame('2026-07-01T12:00:00+02:00', VerifactuChain::query()->sole()->head_generated_at);

        config()->set('einvoicing.verifactu.timezone', 'Atlantic/Canary');
        $chainer = $this->manager()->chainer();
        [$record] = $chainer->append(
            (new RecordMapper)->map(SpanishInvoices::domestic()),
            null,
            Carbon::now()->toDateTimeImmutable(),
        );
        $this->assertSame('2026-07-01T11:00:00+01:00', $record->generatedAt()->text);
    }

    public function test_production_chain_is_separate_from_test(): void
    {
        $test = $this->manager()->register(SpanishInvoices::domestic());
        config()->set('einvoicing.verifactu.environment', 'production');
        $this->travel(1)->seconds();
        Queue::fake();

        $production = $this->manager()->register(SpanishInvoices::domestic());

        $this->assertSame(1, $test->sequence);
        $this->assertSame(1, $production->sequence);
        $this->assertNotSame($test->chain_id, $production->chain_id);
        $this->assertSame(VerifactuEnvironment::Production, $production->environment);
        $this->assertNotNull($production->qr_url);
        $this->assertStringStartsWith('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B64829179', $production->qr_url);
        $this->assertSame(['test', 'production'], VerifactuChain::query()->orderBy('id')->get()->map(static fn (VerifactuChain $chain): string => $chain->environment->value)->all());
        Queue::assertPushed(SubmitVerifactuRecords::class, static fn (SubmitVerifactuRecords $job): bool => $job->throttleKey === '383|production');
    }

    public function test_issuer_of_another_environment_is_refused(): void
    {
        $this->app->instance(VerifactuIssuerResolver::class, new class implements VerifactuIssuerResolver
        {
            public function resolve(Nif $issuer): IssuerContext
            {
                return new IssuerContext($issuer, 'Issuer', IssuerDriver::Aeat, VerifactuEnvironment::Production, new AeatCredentials('/nonexistent.p12', 'pw', null, CertificateKind::Personal));
            }

            public function issuers(): array
            {
                return [];
            }
        });

        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));

        $this->assertInstanceOf(ConfigurationException::class, $exception);
        $this->assertStringContainsString('production', $exception->getMessage());
        $this->assertNothingStored();
    }

    public function test_b2brouter_issuer_cannot_cancel_or_correct(): void
    {
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => SpanishInvoices::SELLER_NAME, 'driver' => 'b2brouter']]);

        foreach ([
            fn () => $this->manager()->cancel($this->invoiceId(), operation: AnulacionOperation::WithoutPriorRecord),
            fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction)),
        ] as $call) {
            $exception = $this->catch($call);
            $this->assertInstanceOf(LogicException::class, $exception);
            $this->assertStringContainsString('B2Brouter', $exception->getMessage());
        }
        $this->assertNothingStored();
    }

    public function test_container_store_is_the_managers_database_store(): void
    {
        $this->assertInstanceOf(DatabaseChainStore::class, $this->manager()->store());
        $this->assertInstanceOf(DatabaseChainStore::class, $this->app->make(ChainStore::class));
    }

    private function manager(): VerifactuManager
    {
        return $this->app->make(VerifactuManager::class);
    }

    private function invoiceId(): InvoiceId
    {
        return new InvoiceId(Nif::parse(self::ISSUER), SpanishInvoices::NUMBER, new DateTimeImmutable(SpanishInvoices::ISSUE_DATE));
    }

    private function seedChain(): void
    {
        $settings = $this->manager()->settings();
        $key = ChainKey::for(Nif::parse(self::ISSUER), $settings->system(), VerifactuEnvironment::Test);
        $this->manager()->store()->seed($key, new InvoiceId(Nif::parse(self::ISSUER), 'OLD-9', new DateTimeImmutable('2026-11-20')), str_repeat('B', 64));
    }

    private function b2brouterChain(int $sequence, ?string $headHash): VerifactuChain
    {
        $chain = new VerifactuChain;
        $chain->forceFill([
            'issuer_nif' => self::ISSUER,
            'installation_number' => 'B2B-INSTALLATION',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'throttle_key' => null,
            'sequence' => $sequence,
            'head_number' => $headHash === null ? null : 'B2B-1',
            'head_issue_date' => $headHash === null ? null : '2026-11-20',
            'head_hash' => $headHash,
            'head_generated_at' => $headHash === null ? null : '2026-11-20T10:00:00+01:00',
            'seeded' => false,
        ])->save();

        return $chain;
    }

    /**
     * The texts of the elements with the given local name in a stored record.
     *
     * @return list<string>
     */
    private function texts(string $xml, string $localName): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));
        $texts = [];
        foreach ((new DOMXPath($document))->query(sprintf('//*[local-name()="%s"]', $localName)) ?: [] as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, VerifactuRecord::query()->count());
        $this->assertSame(0, VerifactuChain::query()->count());
        $this->assertSame(0, VerifactuThrottle::query()->count());
        Queue::assertNothingPushed();
    }

    private function catch(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
