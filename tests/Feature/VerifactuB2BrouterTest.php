<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use ArrayObject;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\CodeList\CountryCode;
use IntrAgile\EInvoicing\CodeList\VatCategory;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuCrossCheckFailed;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordGenerated;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRegistered;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRejected;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeB2BrouterVerifactuApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\SpanishInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\SourceInvoice;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Spain\BreakdownOverride;
use IntrAgile\EInvoicing\Spain\CorrectionType;
use IntrAgile\EInvoicing\Spain\EuroAmounts;
use IntrAgile\EInvoicing\Spain\ExemptOperation;
use IntrAgile\EInvoicing\Spain\ForeignIdType;
use IntrAgile\EInvoicing\Spain\InvoiceType;
use IntrAgile\EInvoicing\Spain\IssuedBy;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\OperationQualification;
use IntrAgile\EInvoicing\Spain\RegimeKey;
use IntrAgile\EInvoicing\Spain\SpanishInvoice;
use IntrAgile\EInvoicing\Spain\Verifactu\AltaOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\Amount;
use IntrAgile\EInvoicing\Spain\Verifactu\AnulacionOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\CorrectionAmounts;
use IntrAgile\EInvoicing\Spain\Verifactu\ForeignId;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordMapper;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxParty;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxRate;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\Ubl\UblReader;
use IntrAgile\EInvoicing\Syntax\Ubl\UblWriter;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

#[Group('verifactu-official')]
final class VerifactuB2BrouterTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-24T09:30:00Z';

    private const ISSUER = SpanishInvoices::SELLER_NIF;

    private const HUELLA_1064490 = '1E823150439412AB8A572FBFD66D676CA366074F933949010EC70FDDC1D5FC73';

    private const HUELLA_1064493 = 'D60F9FD2DDDD19313359612C52222CC74E0B7C6E3C0D64AFBC797F2CE54EF812';

    /** The production ValidarQR URL B2Brouter prints for record 1064493 (core api README). */
    private const QR_1064493 = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B64829179&numserie=PROBE-ES-UBL-0002&fecha=23-11-2026&importe=121.0';

    private FakeB2BrouterVerifactuApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
        config()->set('einvoicing.verifactu.schemas_dir', VerifactuFiles::schemasDir());
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
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => SpanishInvoices::SELLER_NAME, 'driver' => 'b2brouter']]);
        config()->set('einvoicing.verifactu.b2brouter.poll_attempts', 3);
        config()->set('einvoicing.verifactu.b2brouter.poll_delay', 30);

        $this->api = new FakeB2BrouterVerifactuApi;
        $this->app->instance(B2BrouterVerifactu::class, $this->api->client(apiKey: 'test_'.self::SECRET_MARK.'key'));
    }

    public function test_register_returns_pending_without_qr(): void
    {
        Queue::fake();
        Event::fake([VerifactuRecordGenerated::class]);
        $this->api->invoice('24433033', SpanishInvoices::NUMBER, '1064493')->onImport('24433033');

        $row = $this->manager()->register(SpanishInvoices::domestic(), 'order-7');

        $this->assertSame(VerifactuRecordState::Pending, $row->state);
        $this->assertSame(IssuerDriver::B2Brouter, $row->driver);
        $this->assertSame(VerifactuRecordKind::Registration, $row->kind);
        $this->assertSame(VerifactuEnvironment::Test, $row->environment);
        $this->assertSame(self::ISSUER, $row->issuer_nif);
        $this->assertSame(SpanishInvoices::NUMBER, $row->number);
        $this->assertSame('order-7', $row->reference);
        $this->assertNull($row->chain_id);
        $this->assertNull($row->sequence);
        $this->assertNull($row->qr_url);
        $this->assertNull($row->hash);
        $this->assertNull($row->xml);
        $this->assertSame('24433033', $row->b2brouter_invoice_id);
        $this->assertSame('1064493', $row->b2brouter_report_id);
        // The stored source is the UBL sent to B2Brouter, byte for byte.
        $this->assertSame([$row->source], $this->api->imported());
        $this->assertSame(SpanishInvoices::NUMBER, (new UblReader)->read((string) $row->source)->invoice->number);

        $this->assertSame(0, VerifactuChain::query()->count());
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $row->id && $job->attempt === 0);
        Queue::assertNotPushed(SubmitVerifactuRecords::class);
        Event::assertNotDispatched(VerifactuRecordGenerated::class);
    }

    public function test_register_refuses_a_second_import_of_the_same_invoice(): void
    {
        Queue::fake();
        $this->api->invoice('24433033', SpanishInvoices::NUMBER, '1064493')->onImport('24433033');
        $this->manager()->register(SpanishInvoices::domestic());

        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertStringContainsString('24433033', $exception->getMessage());
        $this->assertCount(1, $this->api->imported());
        $this->assertSame(1, VerifactuRecord::query()->count());
    }

    public function test_issue_outcome_unknown_keeps_row_unconfirmed_without_invoice_id(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));

        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic(), 'order-8'));

        $this->assertInstanceOf(OutcomeUnknown::class, $exception);
        $row = VerifactuRecord::query()->sole();
        $this->assertSame(VerifactuRecordState::Unconfirmed, $row->state);
        $this->assertSame(IssuerDriver::B2Brouter, $row->driver);
        $this->assertNull($row->b2brouter_invoice_id);
        $this->assertNull($row->b2brouter_report_id);
        $this->assertNull($row->chain_id);
        $this->assertSame('order-8', $row->reference);
        $this->assertSame($this->api->imported(), [$row->source]);
        $this->assertSame($exception->getMessage(), $row->error_message);
        Queue::assertNotPushed(PollVerifactuTaxReport::class);

        // A second register() would import again: it is refused until the row is settled.
        $again = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $this->assertInstanceOf(InvalidArgumentException::class, $again);
        $this->assertCount(1, $this->api->imported());
    }

    public function test_rejected_import_stores_nothing(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(422, ['Content-Type' => 'application/json'], json_encode([
            'error' => ['code' => 'parameter_taken', 'param' => 'number', 'message' => 'has already been taken'],
        ], JSON_THROW_ON_ERROR)));

        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));

        $this->assertInstanceOf(DocumentRejected::class, $exception);
        $this->assertSame(0, VerifactuRecord::query()->count());
        $this->assertSame(0, VerifactuChain::query()->count());
        Queue::assertNotPushed(PollVerifactuTaxReport::class);
    }

    public function test_retry_finds_the_imported_invoice_instead_of_reimporting(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));
        $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $row = VerifactuRecord::query()->sole();
        // B2Brouter had imported it after all; its tax report names this invoice.
        $this->api->invoice('24433033', SpanishInvoices::NUMBER, '1064493')
            ->report('1064493', VerifactuSamples::b2brouterFixture('record-1064493.xml'), ['signed']);

        $retried = $this->manager()->retryB2BrouterImport($row);

        $this->assertCount(1, $this->api->imported());
        $this->assertSame(VerifactuRecordState::Pending, $retried->state);
        $this->assertSame('24433033', $retried->b2brouter_invoice_id);
        $this->assertSame('1064493', $retried->b2brouter_report_id);
        $this->assertNull($retried->error_message);
        $this->assertCount(1, $this->api->requests('GET /accounts/'.FakeB2BrouterVerifactuApi::ACCOUNT.'/invoices?'));
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $row->id);
    }

    public function test_retry_reimports_the_stored_source_once_when_b2brouter_has_none(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));
        $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $row = VerifactuRecord::query()->sole();
        // The lookup runs first and finds nothing; B2Brouter holds the invoice only after the new import.
        $this->api->importable('24433040', SpanishInvoices::NUMBER, '1064493')->onImport('24433040');

        $retried = $this->manager()->retryB2BrouterImport($row);

        $this->assertSame([$row->source, $row->source], $this->api->imported());
        $this->assertSame(VerifactuRecordState::Pending, $retried->state);
        $this->assertSame('24433040', $retried->b2brouter_invoice_id);
        Queue::assertPushed(PollVerifactuTaxReport::class);
    }

    public function test_registered_report_attaches_to_b2brouter_chain(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);

        $row = $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');

        $this->assertSame(VerifactuRecordState::Registered, $row->state, (string) json_encode($row->cross_check));
        $this->assertSame(1, $row->sequence);
        $this->assertSame(self::HUELLA_1064490, $row->hash);
        $this->assertNull($row->previous_hash);
        $this->assertSame(VerifactuSamples::b2brouterFixture('record-1064490.xml'), $row->xml);
        $this->assertSame('1064490', $row->b2brouter_report_id);
        $this->assertNotNull($row->generated_at);
        $this->assertStringStartsWith('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B64829179&numserie=PROBE-ES-0001', (string) $row->qr_url);
        $this->assertIsArray($row->cross_check);
        $this->assertTrue($row->cross_check['passed']);

        $chain = VerifactuChain::query()->sole();
        $this->assertSame($chain->id, $row->chain_id);
        $this->assertSame(IssuerDriver::B2Brouter, $chain->driver);
        $this->assertSame('1-999999', $chain->installation_number);
        $this->assertSame(VerifactuEnvironment::Test, $chain->environment);
        $this->assertFalse($chain->seeded);
        $this->assertNull($chain->throttle_key);
        $this->assertSame(1, $chain->sequence);
        $this->assertSame(self::HUELLA_1064490, $chain->head_hash);

        Event::assertDispatched(VerifactuRecordRegistered::class, static fn (VerifactuRecordRegistered $event): bool => $event->record->id === $row->id);
        Event::assertNotDispatched(VerifactuCrossCheckFailed::class);
    }

    public function test_first_record_with_a_previous_link_seeds_the_chain(): void
    {
        $row = $this->registerAndPoll($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');

        $this->assertSame(VerifactuRecordState::Registered, $row->state, (string) json_encode($row->cross_check));
        $this->assertSame(1, $row->sequence);
        $this->assertSame(self::HUELLA_1064490, $row->previous_hash);
        $chain = VerifactuChain::query()->sole();
        $this->assertTrue($chain->seeded);
        $this->assertSame(self::HUELLA_1064493, $chain->head_hash);
        // Without a stored predecessor the chain step is skipped, which is not a failure.
        $this->assertIsArray($row->cross_check);
        $this->assertTrue($row->cross_check['passed']);
        $this->assertStringContainsString('Step 3 Chain: skipped', $row->cross_check['summary']);
    }

    public function test_second_report_links_after_first(): void
    {
        $first = $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');

        $second = $this->registerAndPoll($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');

        $this->assertSame(VerifactuRecordState::Registered, $second->state, (string) json_encode($second->cross_check));
        $this->assertSame(2, $second->sequence);
        $this->assertSame($first->chain_id, $second->chain_id);
        $this->assertSame(self::HUELLA_1064490, $second->previous_hash);
        $this->assertSame(self::QR_1064493, $second->qr_url);
        $this->assertIsArray($second->cross_check);
        $this->assertTrue($second->cross_check['passed']);
        $this->assertSame([], $second->cross_check['failures']);
        foreach (['Schema', 'Fingerprint', 'Chain', 'Semantics', 'Qr'] as $index => $step) {
            $this->assertStringContainsString(sprintf('Step %d %s: passed', $index + 1, $step), $second->cross_check['summary']);
        }
        // The QR image was decoded, not compared through the fallback.
        $this->assertStringNotContainsString('fallback', $second->cross_check['summary']);
        // previous_id is opaque: no tax report is read by it.
        $this->assertSame([], $this->api->requests('GET /tax_reports/290'));
        $this->assertSame(2, VerifactuChain::query()->sole()->sequence);
    }

    public function test_tampered_huella_fails_row_in_strict_mode(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');

        $row = $this->registerAndPoll($this->ubl2Invoice(), '24433033', '1064493', $this->tampered(), fixtureIsXml: true);

        $this->assertSame(VerifactuRecordState::Failed, $row->state);
        $this->assertSame('cross_check', $row->error_code);
        $this->assertStringContainsString('Huella', (string) $row->error_message);
        $this->assertIsArray($row->cross_check);
        $this->assertFalse($row->cross_check['passed']);
        $this->assertSame('Fingerprint', $row->cross_check['failures'][0]['step']);
        $this->assertSame('Huella', $row->cross_check['failures'][0]['field']);
        // B2Brouter registered it, so it stays in the chain the next record links to.
        $this->assertSame(2, $row->sequence);
        $this->assertSame(str_repeat('A', 64), VerifactuChain::query()->sole()->head_hash);

        Event::assertDispatched(VerifactuCrossCheckFailed::class, static fn (VerifactuCrossCheckFailed $event): bool => $event->record->id === $row->id && ! $event->result->passed());
        Event::assertNotDispatched(VerifactuRecordRegistered::class, static fn (VerifactuRecordRegistered $event): bool => $event->record->id === $row->id);
    }

    public function test_report_mode_registers_and_fires_cross_check_failed(): void
    {
        config()->set('einvoicing.verifactu.cross_check', 'report');
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');

        $row = $this->registerAndPoll($this->ubl2Invoice(), '24433033', '1064493', $this->tampered(), fixtureIsXml: true);

        $this->assertSame(VerifactuRecordState::Registered, $row->state);
        $this->assertNull($row->error_code);
        $this->assertIsArray($row->cross_check);
        $this->assertFalse($row->cross_check['passed']);
        Event::assertDispatched(VerifactuRecordRegistered::class, static fn (VerifactuRecordRegistered $event): bool => $event->record->id === $row->id);
        Event::assertDispatched(VerifactuCrossCheckFailed::class, static fn (VerifactuCrossCheckFailed $event): bool => $event->record->id === $row->id);
    }

    public function test_known_divergence_is_not_a_failure(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        $this->registerAndPoll($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');

        // Our invoice says E6; B2Brouter's record says E1.
        $row = $this->registerAndPoll($this->exemptProbe(ExemptOperation::E6), '24433050', '1064500', 'record-1064500.xml');

        $this->assertSame(VerifactuRecordState::Registered, $row->state, (string) json_encode($row->cross_check));
        $this->assertSame(3, $row->sequence);
        $this->assertIsArray($row->cross_check);
        $this->assertTrue($row->cross_check['passed']);
        $this->assertSame([], $row->cross_check['failures']);
        $this->assertCount(1, $row->cross_check['known_divergences']);
        $this->assertStringEndsWith('OperacionExenta', $row->cross_check['known_divergences'][0]['field']);
        $this->assertSame('E6', $row->cross_check['known_divergences'][0]['ours']);
        $this->assertSame('E1', $row->cross_check['known_divergences'][0]['theirs']);
        Event::assertNotDispatched(VerifactuCrossCheckFailed::class);
    }

    public function test_registered_with_errors_is_attached_not_failed(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuRecordRejected::class]);

        $row = $this->registerAndPoll(
            SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'),
            '24433001',
            '1064490',
            'record-1064490.xml',
            ['processing', 'registered_with_errors'],
        );

        $this->assertSame(VerifactuRecordState::AcceptedWithErrors, $row->state);
        $this->assertSame('registered_with_errors', $row->error_code);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(self::HUELLA_1064490, VerifactuChain::query()->sole()->head_hash);
        Event::assertDispatched(VerifactuRecordRegistered::class, static fn (VerifactuRecordRegistered $event): bool => $event->record->id === $row->id);
        Event::assertNotDispatched(VerifactuRecordRejected::class);
    }

    public function test_poll_is_bounded_then_unconfirmed(): void
    {
        Event::fake([VerifactuRecordUnconfirmed::class, VerifactuRecordRegistered::class]);
        // Two reads per run through the container-bound client; three runs on the sync queue.
        $this->app->instance(B2BrouterVerifactu::class, $this->api->client(FakeB2BrouterVerifactuApi::polling(2)));
        $this->api->invoice('24433001', 'PROBE-ES-0001', '1064490')
            ->report('1064490', VerifactuSamples::b2brouterFixture('record-1064490.xml'), ['signed'])
            ->onImport('24433001');

        $this->manager()->register(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'));

        $row = VerifactuRecord::query()->sole();
        $this->assertSame(VerifactuRecordState::Pending, $row->state);
        $this->assertNull($row->chain_id);
        $this->assertNotNull($row->error_message);
        $this->assertStringContainsString('3', $row->error_message);
        // Each run reads the invoice once, then its report once: 3 runs, 6 reads.
        $this->assertCount(6, $this->api->requests('GET '));
        $this->assertCount(3, $this->api->requests('GET /tax_reports/1064490'));
        $this->assertCount(3, $this->api->requests('GET /invoices/24433001'));
        Event::assertDispatchedTimes(VerifactuRecordUnconfirmed::class, 1);
        Event::assertNotDispatched(VerifactuRecordRegistered::class);
    }

    public function test_poll_reschedules_itself_until_the_bound(): void
    {
        Queue::fake();
        $this->api->invoice('24433001', 'PROBE-ES-0001', '1064490')
            ->report('1064490', VerifactuSamples::b2brouterFixture('record-1064490.xml'), ['signed'])
            ->onImport('24433001');
        $row = $this->manager()->register(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'));

        $this->runPoll($row->id, 1);

        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $row->id
            && $job->attempt === 2
            && $job->delay === 30);
        $this->assertSame(VerifactuRecordState::Pending, $row->refresh()->state);
    }

    public function test_report_error_state_fails_row_with_errors(): void
    {
        Event::fake([VerifactuRecordRejected::class, VerifactuRecordRegistered::class]);

        $row = $this->registerAndPoll(
            SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'),
            '24433001',
            '1064490',
            'record-1064490.xml',
            ['processing', 'error'],
            ['El NIF del emisor no está identificado'],
        );

        $this->assertSame(VerifactuRecordState::Failed, $row->state);
        $this->assertSame('error', $row->error_code);
        $this->assertStringContainsString('error', (string) $row->error_message);
        $this->assertStringContainsString('El NIF del emisor no está identificado', (string) $row->error_message);
        $this->assertNull($row->chain_id);
        $this->assertSame(0, VerifactuChain::query()->count());
        Event::assertDispatched(VerifactuRecordRejected::class, static fn (VerifactuRecordRejected $event): bool => $event->record->id === $row->id
            && $event->errorCode === 'error'
            && str_contains((string) $event->errorMessage, 'El NIF del emisor'));
        Event::assertNotDispatched(VerifactuRecordRegistered::class);
    }

    public function test_b2brouter_mode_never_advances_a_local_head(): void
    {
        Queue::fake();
        $this->api->invoice('24433001', 'PROBE-ES-0001', '1064490')
            ->report('1064490', VerifactuSamples::b2brouterFixture('record-1064490.xml'))
            ->onImport('24433001');

        $row = $this->manager()->register(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'));

        // Nothing is chained at import time: no local head, no throttle, no submission.
        $this->assertSame(0, VerifactuChain::query()->count());
        $this->runPoll($row->id);

        $chain = VerifactuChain::query()->sole();
        $this->assertSame(IssuerDriver::B2Brouter, $chain->driver);
        $this->assertSame('1-999999', $chain->installation_number);
        // The local installation (383) has no chain; the head is B2Brouter's record.
        $this->assertSame(0, VerifactuChain::query()->where('installation_number', '383')->count());
        $this->assertSame(self::HUELLA_1064490, $chain->head_hash);
        $this->assertSame(0, VerifactuThrottle::query()->count());
        Queue::assertNotPushed(SubmitVerifactuRecords::class);
    }

    public function test_cancel_and_correct_in_b2brouter_mode_are_refused(): void
    {
        $id = new InvoiceId(Nif::parse(self::ISSUER), SpanishInvoices::NUMBER, new DateTimeImmutable(SpanishInvoices::ISSUE_DATE));
        foreach ([
            fn () => $this->manager()->cancel($id),
            fn () => $this->manager()->cancel($id, operation: AnulacionOperation::WithoutPriorRecord),
            fn () => $this->manager()->correct(SpanishInvoices::domesticAs(AltaOperation::Correction)),
        ] as $call) {
            $exception = $this->catch($call);
            $this->assertInstanceOf(LogicException::class, $exception);
            $this->assertStringContainsString('B2Brouter', $exception->getMessage());
        }
        $this->assertSame(0, VerifactuRecord::query()->count());
        $this->assertSame([], $this->api->requests());
    }

    public function test_stored_extension_is_explicit_json_and_refuses_anything_else(): void
    {
        Queue::fake();
        $this->api->invoice('24433050', 'PROBE-ES-UBL-E-0003', '1064500')->onImport('24433050');
        $row = $this->manager()->register($this->exemptProbe(ExemptOperation::E6));
        $this->assertNotNull($row->source_extension);
        $stored = json_decode($row->source_extension, true, 32, JSON_THROW_ON_ERROR);
        $this->assertIsArray($stored);
        $this->assertSame(1, $stored['version']);
        $this->assertSame('E6', $stored['breakdown']['E|-']['exemption']);
        $this->assertSame('Initial', $stored['operation']);
        $this->assertSame([ExemptOperation::E6], array_map(
            static fn (BreakdownOverride $override): ?ExemptOperation => $override->exemption,
            array_values(SourceInvoice::of($row)->extensions()->get(SpanishInvoice::class)->breakdown ?? []),
        ));

        foreach ([
            serialize(new ArrayObject([1])) => 'Syntax error',
            '{"version":2}' => 'version',
            '{"version":1,"type":"Z9"}' => 'Z9',
            '{"version":1,"operation":"Sideways"}' => 'Sideways',
            '{"version":1,"breakdown":{"E|-":{"exemption":"E1","qualification":"S1"}}}' => 'either',
        ] as $tampered => $needle) {
            $row->source_extension = $tampered;
            $exception = $this->catch(static fn () => SourceInvoice::of($row));
            $this->assertInstanceOf(UnexpectedValueException::class, $exception, $tampered);
            $this->assertStringContainsString($needle, $exception->getMessage(), $tampered);
        }
    }

    /**
     * @return iterable<string, array{Invoice}>
     */
    public static function invoicesWithExtensions(): iterable
    {
        yield 'exempt' => [SpanishInvoices::exempt(ExemptOperation::E1)];
        yield 'R1 credit note' => [SpanishInvoices::creditNote()];
        yield 'export' => [SpanishInvoices::export()];
        yield 'every field' => [SpanishInvoices::domestic()->withExtension(new SpanishInvoice(
            type: InvoiceType::R1,
            correctionType: CorrectionType::Substitution,
            correctionAmounts: new CorrectionAmounts(Amount::of('100.00'), Amount::of('21.00'), Amount::of('5.20')),
            substituted: [new InvoiceId(Nif::parse(self::ISSUER), 'OLD-1', new DateTimeImmutable('2026-10-01'))],
            description: 'Sustitución de la factura OLD-1',
            simplifiedArt7273: false,
            withoutRecipientArt61d: false,
            issuedBy: IssuedBy::ThirdParty,
            thirdParty: new TaxParty('Tercero S.L.', Nif::parse('A39200019'), null),
            recipients: [new TaxParty('Buyer Inc.', null, new ForeignId(ForeignIdType::OfficialDocument, '123456789', CountryCode::US))],
            coupon: true,
            breakdown: ['S|21' => new BreakdownOverride(regime: RegimeKey::Key01, qualification: OperationQualification::S1, costBase: Amount::of('80.00'), equivalenceRate: TaxRate::of('5.2'), equivalenceQuota: Amount::of('5.20'))],
            euroAmounts: new EuroAmounts(Amount::of('21.00'), Amount::of('121.00'), ['S|21' => ['base' => Amount::of('100.00'), 'quota' => Amount::of('21.00')]]),
            externalReference: 'ext-1',
            agreementNumber: 'agr-2',
            systemAgreementId: 'sys-3',
            operation: AltaOperation::CorrectionAfterRejection,
        ))];
    }

    #[DataProvider('invoicesWithExtensions')]
    public function test_extension_round_trips_through_json(Invoice $invoice): void
    {
        $original = $invoice->extensions()->get(SpanishInvoice::class);
        $this->assertInstanceOf(SpanishInvoice::class, $original);

        $stored = SourceInvoice::extensionOf($invoice);
        $this->assertNotNull($stored);
        $data = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        $this->assertEquals($original, SourceInvoice::fromArray($data));
    }

    public function test_extension_keeps_the_mapping_of_the_source(): void
    {
        foreach ([SpanishInvoices::exempt(ExemptOperation::E1), SpanishInvoices::creditNote(), SpanishInvoices::export()] as $invoice) {
            $row = new VerifactuRecord;
            $row->forceFill(['source' => (new UblWriter)->write($invoice), 'source_extension' => SourceInvoice::extensionOf($invoice)]);

            $this->assertEquals((new RecordMapper)->map($invoice), (new RecordMapper)->map(SourceInvoice::of($row)), $invoice->number);
        }
    }

    public function test_out_of_order_polls_attach_in_chain_order(): void
    {
        Queue::fake();
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);
        $a = $this->import(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        $b = $this->import($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');

        // B is final first, but its predecessor A is still pending: B waits, the chain is not seeded.
        $this->runPoll($b->id);
        $b->refresh();
        $this->assertSame(VerifactuRecordState::Pending, $b->state);
        $this->assertNull($b->chain_id);
        $this->assertSame(PollVerifactuTaxReport::AWAITING_PREDECESSOR, $b->error_code);
        $this->assertStringContainsString((string) $a->id, (string) $b->error_message);
        $this->assertSame(0, VerifactuChain::query()->count());
        // Only the poll register() queued; waiting schedules nothing.
        $pollsOfB = static fn (): int => Queue::pushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $b->id)->count();
        $this->assertSame(1, $pollsOfB());

        // A attaches and wakes B.
        $this->runPoll($a->id);
        $this->assertSame(1, $a->refresh()->sequence);
        $this->assertSame(2, $pollsOfB());

        $this->runPoll($b->id);
        $b->refresh();
        $this->assertSame(VerifactuRecordState::Registered, $b->state, (string) json_encode($b->cross_check));
        $this->assertSame(2, $b->sequence);
        $this->assertNull($b->error_code);
        $this->assertSame($a->chain_id, $b->chain_id);
        $chain = VerifactuChain::query()->sole();
        $this->assertFalse($chain->seeded);
        $this->assertSame(self::HUELLA_1064493, $chain->head_hash);
        Event::assertNotDispatched(VerifactuCrossCheckFailed::class);
    }

    public function test_mid_chain_record_waits_for_its_predecessor(): void
    {
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        Queue::fake();
        $b = $this->import($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');
        $c = $this->import($this->exemptProbe(ExemptOperation::E6), '24433050', '1064500', 'record-1064500.xml');

        $this->runPoll($c->id);
        $this->assertSame(PollVerifactuTaxReport::AWAITING_PREDECESSOR, $c->refresh()->error_code);
        $this->assertNull($c->chain_id);

        $this->runPoll($b->id);
        $this->assertSame(2, $b->refresh()->sequence);
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $c->id);

        $this->runPoll($c->id);
        $c->refresh();
        $this->assertSame(VerifactuRecordState::Registered, $c->state, (string) json_encode($c->cross_check));
        $this->assertSame(3, $c->sequence);
    }

    public function test_successor_of_a_rejected_first_record_then_seeds_the_chain(): void
    {
        Queue::fake();
        $a = $this->import(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml', ['error']);
        $b = $this->import($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml');

        $this->runPoll($b->id);
        $this->assertSame(PollVerifactuTaxReport::AWAITING_PREDECESSOR, $b->refresh()->error_code);

        $this->runPoll($a->id);
        $this->assertSame(VerifactuRecordState::Failed, $a->refresh()->state);
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $b->id);

        // No older record is pending any more: B starts the chain from its own link.
        $this->runPoll($b->id);
        $b->refresh();
        $this->assertSame(VerifactuRecordState::Registered, $b->state, (string) json_encode($b->cross_check));
        $this->assertSame(1, $b->sequence);
        $this->assertTrue(VerifactuChain::query()->sole()->seeded);
    }

    public function test_successor_of_a_rejected_record_mid_chain_then_fails(): void
    {
        Event::fake([VerifactuCrossCheckFailed::class]);
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        Queue::fake();
        $b = $this->import($this->ubl2Invoice(), '24433033', '1064493', 'record-1064493.xml', ['error']);
        $c = $this->import($this->exemptProbe(ExemptOperation::E6), '24433050', '1064500', 'record-1064500.xml');

        $this->runPoll($c->id);
        $this->assertSame(PollVerifactuTaxReport::AWAITING_PREDECESSOR, $c->refresh()->error_code);
        $this->runPoll($b->id);
        $this->assertSame(VerifactuRecordState::Failed, $b->refresh()->state);

        // C links to B, which AEAT never registered: it cannot join the chain.
        $this->runPoll($c->id);
        $c->refresh();
        $this->assertSame(VerifactuRecordState::Failed, $c->state);
        $this->assertSame('chain_conflict', $c->error_code);
        $this->assertNull($c->chain_id);
        $this->assertSame(1, VerifactuChain::query()->sole()->sequence);
        Event::assertDispatched(VerifactuCrossCheckFailed::class, static fn (VerifactuCrossCheckFailed $event): bool => $event->record->id === $c->id);
    }

    public function test_second_run_after_attach_changes_nothing(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class]);
        $row = $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        $before = $row->getAttributes();

        $this->runPoll($row->id);

        $this->assertSame($before, $row->refresh()->getAttributes());
        Event::assertDispatchedTimes(VerifactuRecordRegistered::class, 1);
        Event::assertNotDispatched(VerifactuCrossCheckFailed::class);
    }

    public function test_stale_pending_run_changes_nothing(): void
    {
        Event::fake([VerifactuRecordRegistered::class, VerifactuCrossCheckFailed::class, VerifactuRecordRejected::class]);
        $row = $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        $before = $row->getAttributes();
        $this->staleOnce($row->id);

        // A concurrent run that read the row while it was still pending.
        $this->runPoll($row->id);

        $this->assertSame($before, $row->refresh()->getAttributes());
        $this->assertSame(1, VerifactuChain::query()->sole()->sequence);
        Event::assertDispatchedTimes(VerifactuRecordRegistered::class, 1);
        Event::assertNotDispatched(VerifactuCrossCheckFailed::class);
        Event::assertNotDispatched(VerifactuRecordRejected::class);
    }

    public function test_stale_run_after_rejection_fires_no_second_event(): void
    {
        Event::fake([VerifactuRecordRejected::class]);
        $row = $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml', ['error']);
        $before = $row->getAttributes();
        $this->staleOnce($row->id);

        $this->runPoll($row->id);

        $this->assertSame($before, $row->refresh()->getAttributes());
        Event::assertDispatchedTimes(VerifactuRecordRejected::class, 1);
    }

    public function test_failed_run_stores_the_reason_and_fires_unconfirmed(): void
    {
        Event::fake([VerifactuRecordUnconfirmed::class]);
        Queue::fake();
        $row = $this->import(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');

        (new PollVerifactuTaxReport($row->id))->failed(new RuntimeException('worker lost'));

        $row->refresh();
        $this->assertSame(VerifactuRecordState::Pending, $row->state);
        $this->assertStringContainsString('worker lost', (string) $row->error_message);
        $this->assertStringContainsString('RuntimeException', (string) $row->error_message);
        Event::assertDispatchedTimes(VerifactuRecordUnconfirmed::class, 1);
        Queue::assertNotPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->attempt > 0);

        // A settled record is left alone.
        $this->runPoll($row->id);
        $settled = $row->refresh()->getAttributes();
        (new PollVerifactuTaxReport($row->id))->failed(new RuntimeException('late'));
        $this->assertSame($settled, $row->refresh()->getAttributes());
        Event::assertDispatchedTimes(VerifactuRecordUnconfirmed::class, 1);
    }

    public function test_unreadable_source_extension_fails_the_cross_check_but_attaches(): void
    {
        Event::fake([VerifactuCrossCheckFailed::class, VerifactuRecordRegistered::class]);
        Queue::fake();
        $row = $this->import(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml');
        VerifactuRecord::query()->whereKey($row->id)->update(['source_extension' => '{"version":99}']);

        $this->runPoll($row->id);

        $row->refresh();
        $this->assertSame(VerifactuRecordState::Failed, $row->state);
        $this->assertSame('cross_check', $row->error_code);
        $this->assertSame(1, $row->sequence);
        $this->assertIsArray($row->cross_check);
        $this->assertSame('invoice', $row->cross_check['failures'][0]['field']);
        $this->assertStringContainsString('cannot be read back', $row->cross_check['failures'][0]['message']);
        Event::assertDispatched(VerifactuCrossCheckFailed::class);
        Event::assertNotDispatched(VerifactuRecordRegistered::class);
    }

    public function test_retry_when_lookup_cannot_decide_stays_unconfirmed(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));
        $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $row = VerifactuRecord::query()->sole();
        $this->api->undecidableListing();

        $retried = $this->manager()->retryB2BrouterImport($row);

        $this->assertSame(VerifactuRecordState::Unconfirmed, $retried->state);
        $this->assertNull($retried->b2brouter_invoice_id);
        $this->assertStringContainsString('cannot decide', (string) $retried->error_message);
        $this->assertCount(1, $this->api->imported());
        $this->assertCount(1, $this->api->requests('POST '));
        Queue::assertNotPushed(PollVerifactuTaxReport::class);
    }

    public function test_retry_does_not_adopt_an_invoice_of_another_idfactura(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));
        $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $row = VerifactuRecord::query()->sole();
        // An invoice with the number exists, but its record is of another invoice.
        $this->api->invoice('24433099', SpanishInvoices::NUMBER, '1064490')
            ->report('1064490', VerifactuSamples::b2brouterFixture('record-1064490.xml'));

        $retried = $this->manager()->retryB2BrouterImport($row);

        $this->assertSame(VerifactuRecordState::Unconfirmed, $retried->state);
        $this->assertNull($retried->b2brouter_invoice_id);
        $this->assertStringContainsString('not adopted', (string) $retried->error_message);
        $this->assertCount(1, $this->api->imported());
        Queue::assertNotPushed(PollVerifactuTaxReport::class);
    }

    public function test_concurrent_retries_import_once(): void
    {
        Queue::fake();
        $this->api->onImport(new Response(503));
        $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $first = VerifactuRecord::query()->sole();
        $second = VerifactuRecord::query()->sole();
        $this->api->importable('24433040', SpanishInvoices::NUMBER, '1064493')->onImport('24433040');

        $this->manager()->retryB2BrouterImport($first);
        $exception = $this->catch(fn () => $this->manager()->retryB2BrouterImport($second));

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertCount(2, $this->api->imported());
        $this->assertSame('24433040', $first->refresh()->b2brouter_invoice_id);
        $this->assertSame(VerifactuRecordState::Pending, $first->state);
    }

    public function test_rows_events_and_jobs_carry_no_secret(): void
    {
        $events = [];
        Event::listen('IntrAgile\EInvoicing\Laravel\Events\*', static function (string $name, array $payload) use (&$events): void {
            $events[] = serialize($payload);
        });
        $this->registerAndPoll(SpanishInvoices::domestic()->withNumber('PROBE-ES-0001'), '24433001', '1064490', 'record-1064490.xml', ['processing', 'error'], ['refused']);
        $this->api->onImport(new Response(503));
        $exception = $this->catch(fn () => $this->manager()->register(SpanishInvoices::domestic()));

        $this->assertNotNull($exception);
        $this->assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());
        $this->assertStringNotContainsString(self::SECRET_MARK, serialize(new PollVerifactuTaxReport(1)));
        foreach (VerifactuRecord::query()->get() as $row) {
            $this->assertStringNotContainsString(self::SECRET_MARK, (string) json_encode($row->getAttributes()));
        }
        $this->assertNotSame([], $events);
        foreach ($events as $event) {
            $this->assertStringNotContainsString(self::SECRET_MARK, $event);
        }
    }

    /**
     * Imports the invoice through register() and runs its poll job once, returning the fresh row.
     *
     * @param  list<string>  $states
     * @param  list<string>  $errors
     */
    private function registerAndPoll(
        Invoice $invoice,
        string $invoiceId,
        string $reportId,
        string $fixture,
        array $states = ['registered'],
        array $errors = [],
        bool $fixtureIsXml = false,
    ): VerifactuRecord {
        $xml = $fixtureIsXml ? $fixture : VerifactuSamples::b2brouterFixture($fixture);
        $this->api->invoice($invoiceId, $invoice->number, $reportId)
            ->report($reportId, $xml, $states, null, true, $errors)
            ->onImport($invoiceId);

        $queue = Queue::getFacadeRoot();
        Queue::fake();
        try {
            $row = $this->manager()->register($invoice);
            $this->runPoll($row->id);
        } finally {
            Queue::swap($queue);
        }

        return $row->refresh();
    }

    /**
     * Imports the invoice through register() with its B2Brouter invoice and report prepared, and
     * returns the pending row. The queue must be faked.
     *
     * @param  list<string>  $states
     */
    private function import(Invoice $invoice, string $invoiceId, string $reportId, string $fixture, array $states = ['registered']): VerifactuRecord
    {
        $this->api->invoice($invoiceId, $invoice->number, $reportId)
            ->report($reportId, VerifactuSamples::b2brouterFixture($fixture), $states)
            ->onImport($invoiceId);

        return $this->manager()->register($invoice);
    }

    /** Makes the next read of the row see it as still pending and unattached, as a concurrent run would. */
    private function staleOnce(int $id): void
    {
        $stale = true;
        VerifactuRecord::retrieved(static function (VerifactuRecord $model) use (&$stale, $id): void {
            if ($stale && $model->id === $id) {
                $stale = false;
                $model->setRawAttributes(['state' => VerifactuRecordState::Pending->value, 'chain_id' => null, 'sequence' => null] + $model->getAttributes(), true);
            }
        });
    }

    private function runPoll(int $recordId, int $attempt = 0): void
    {
        $this->app->call([new PollVerifactuTaxReport($recordId, $attempt), 'handle']);
    }

    /** Record 1064493 with its own Huella, and the report's fingerprint, replaced. */
    private function tampered(): string
    {
        $xml = VerifactuSamples::b2brouterFixture('record-1064493.xml');
        $tampered = str_replace('<sum1:Huella>'.self::HUELLA_1064493.'</sum1:Huella>', '<sum1:Huella>'.str_repeat('A', 64).'</sum1:Huella>', $xml);
        $this->assertNotSame($xml, $tampered);

        return $tampered;
    }

    /** The invoice imported into the B2Brouter sandbox for record 1064493, read from its UBL. */
    private function ubl2Invoice(): Invoice
    {
        return (new UblReader)->read(VerifactuSamples::b2brouterFixture('es-ubl2.xml'))->invoice;
    }

    /** The exempt probe invoice: one exempt line of 100.00 under the given article. */
    private function exemptProbe(ExemptOperation $article): Invoice
    {
        return SpanishInvoices::invoice(
            [SpanishInvoices::line('1', '100.00', VatCategory::Exempt, null)],
            spanish: new SpanishInvoice(breakdown: ['E|-' => new BreakdownOverride(exemption: $article)]),
            number: 'PROBE-ES-UBL-E-0003',
        );
    }

    private function manager(): VerifactuManager
    {
        return $this->app->make(VerifactuManager::class);
    }

    private function catch(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
