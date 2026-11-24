<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\CodeList\VatCategory;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordAccepted;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeAeatApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeB2BrouterVerifactuApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\SpanishInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\CancellationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\Chainer;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationDraft;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\Aeat\ErrorAction;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

#[Group('verifactu-official')]
final class VerifactuCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-24T10:00:00Z';

    private const ISSUER = VerifactuSamples::ISSUER_NIF;

    private const OTHER = VerifactuSamples::SANDBOX_ISSUER_NIF;

    private const THIRD = VerifactuSamples::RECIPIENT_NIF;

    private const HASH_1 = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    private const HASH_2 = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

    private const HASH_3 = '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68';

    private const SEED_HASH = '1E823150439412AB8A572FBFD66D676CA366074F933949010EC70FDDC1D5FC73';

    private FakeAeatApi $aeat;

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
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => 'Test Issuer S.L.']]);
        // Never read: the AEAT client is bound below.
        config()->set('einvoicing.verifactu.aeat.certificate', '/nonexistent/'.self::SECRET_MARK.'.p12');
        config()->set('einvoicing.verifactu.aeat.password', 'pw-'.self::SECRET_MARK);

        $this->aeat = new FakeAeatApi;
        $this->app->instance(AeatClient::class, $this->aeat->client(new VerifactuSchemas(VerifactuFiles::schemasDir())));
        Queue::fake();
    }

    public function test_submit_schedules_each_due_throttle_and_clears_blocks(): void
    {
        config()->set('einvoicing.verifactu.submission.throttle_scope', 'issuer');
        config()->set('einvoicing.verifactu.issuers', [
            self::ISSUER => ['name' => 'Test Issuer S.L.'],
            self::OTHER => ['name' => 'Other Issuer S.L.'],
            self::THIRD => ['name' => 'Third Issuer S.L.'],
        ]);
        $this->generate('A-1', self::ISSUER, ThrottleScope::Issuer);
        $this->generate('B-1', self::OTHER, ThrottleScope::Issuer);
        $settled = $this->generate('C-1', self::THIRD, ThrottleScope::Issuer);
        $settled->forceFill(['state' => VerifactuRecordState::Accepted, 'csv' => 'A-CSV'])->save();
        $keyA = $this->key(self::ISSUER, ThrottleScope::Issuer);
        $keyB = $this->key(self::OTHER, ThrottleScope::Issuer);
        $keyC = $this->key(self::THIRD, ThrottleScope::Issuer);
        VerifactuThrottle::query()->where('key', $keyA)->update(['blocked_at' => Carbon::now(), 'blocked_code' => '4104', 'blocked_message' => 'Blocked by a client fault.']);

        // Filtered by issuer: only that issuer's key, and the other block stays.
        $this->assertSame(0, Artisan::call('einvoicing:verifactu-submit', ['--issuer' => self::OTHER]));
        $this->assertSame([$keyB], $this->scheduledKeys());
        $this->assertNotNull(VerifactuThrottle::query()->where('key', $keyA)->sole()->blocked_at);
        $this->releaseLocks([$keyA, $keyB, $keyC]);
        Queue::fake();

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-submit'));
        $output = Artisan::output();

        $this->assertEqualsCanonicalizing([$keyA, $keyB], $this->scheduledKeys());
        $throttle = VerifactuThrottle::query()->where('key', $keyA)->sole();
        $this->assertNull($throttle->blocked_at);
        $this->assertNull($throttle->blocked_code);
        $this->assertNull($throttle->blocked_message);
        $this->assertStringContainsString($keyA, $output);
        $this->assertStringContainsString('4104', $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
    }

    public function test_retry_unconfirmed_resets_attempts(): void
    {
        config()->set('einvoicing.sending.max_attempts', 3);
        $capped = $this->generate('F-1');
        $duplicate = $this->generate('F-2');
        $stale = $this->generate('F-3');
        $fresh = $this->generate('F-4');
        $capped->forceFill(['state' => VerifactuRecordState::Unconfirmed, 'attempts' => 3, 'submitted_at' => Carbon::now()->subHour()])->save();
        // A record held for a lookup is never resent, whatever its attempts.
        $duplicate->forceFill(['state' => VerifactuRecordState::Unconfirmed, 'attempts' => 2, 'submitted_at' => Carbon::now()->subHour(), 'error_code' => '3000', 'error_action' => ErrorAction::ResolveDuplicate->name])->save();
        // Left in sending by a killed worker (older than stuck_after_minutes), and one still in flight.
        VerifactuRecord::query()->whereKey($stale->id)->toBase()->update(['state' => VerifactuRecordState::Sending->value, 'updated_at' => Carbon::now()->subMinutes(61)]);
        VerifactuRecord::query()->whereKey($fresh->id)->toBase()->update(['state' => VerifactuRecordState::Sending->value, 'updated_at' => Carbon::now()->subMinutes(5)]);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-submit', ['--retry-unconfirmed' => true]));
        $output = Artisan::output();

        $capped->refresh();
        $this->assertSame(VerifactuRecordState::Unconfirmed, $capped->state);
        // One attempt is kept: the stored bytes were sent before, so AEAT's duplicate answer
        // confirms the record.
        $this->assertSame(1, $capped->attempts);
        $duplicate->refresh();
        $this->assertSame(2, $duplicate->attempts);
        $this->assertSame(ErrorAction::ResolveDuplicate->name, $duplicate->error_action);
        $stale->refresh();
        $this->assertSame(VerifactuRecordState::Unconfirmed, $stale->state);
        $this->assertSame(1, $stale->attempts);
        $this->assertNotNull($stale->submitted_at);
        $this->assertSame(VerifactuRecordState::Sending, $fresh->fresh()?->state);
        $this->assertSame([$this->key()], $this->scheduledKeys());
        $this->assertStringContainsString('--lookup', $output);

        // The job takes both again and sends the stored bytes; the duplicate answer accepts them.
        Queue::fake();
        $this->releaseLocks([$this->key()]);
        $this->aeat->queue(FakeAeatApi::answer([
            FakeAeatApi::line($capped->invoiceId(), 'duplicado_correcta'),
            FakeAeatApi::line($stale->invoiceId(), 'duplicado_correcta'),
        ], csv: null));
        $this->app->call([new SubmitVerifactuRecords($this->key()), 'handle']);

        $this->assertSame(['F-1', 'F-3'], $this->aeat->sentNumbers(0));
        $this->assertSame(VerifactuRecordState::Accepted, $capped->fresh()?->state);
        $this->assertSame(VerifactuRecordState::Accepted, $stale->fresh()?->state);
    }

    public function test_retry_unconfirmed_settles_b2brouter_imports_and_repolls_pending_rows(): void
    {
        $api = $this->useB2Brouter();
        $api->onImport(new Response(503));
        $this->capture(fn () => $this->manager()->register(SpanishInvoices::domestic()));
        $unconfirmed = VerifactuRecord::query()->sole();
        $this->assertSame(VerifactuRecordState::Unconfirmed, $unconfirmed->state);
        $api->invoice('24433033', SpanishInvoices::NUMBER, '1064493')
            ->report('1064493', VerifactuSamples::b2brouterFixture('record-1064493.xml'), ['signed']);
        // Its polls were used up while the report was not final.
        $stalled = VerifactuRecord::query()->forceCreate([
            'kind' => 'alta',
            'issuer_nif' => SpanishInvoices::SELLER_NIF,
            'number' => 'PROBE-ES-UBL-0003',
            'issue_date' => '2026-11-23',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => '24433099',
            'b2brouter_report_id' => '1064499',
            'attempts' => 1,
            'error_message' => 'The tax report was not known after 3 polls.',
        ]);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-submit', ['--retry-unconfirmed' => true]));

        $unconfirmed->refresh();
        $this->assertSame(VerifactuRecordState::Pending, $unconfirmed->state);
        $this->assertSame('24433033', $unconfirmed->b2brouter_invoice_id);
        $this->assertCount(1, $api->imported());
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $unconfirmed->id);
        Queue::assertPushed(PollVerifactuTaxReport::class, static fn (PollVerifactuTaxReport $job): bool => $job->recordId === $stalled->id && $job->attempt === 0);
        Queue::assertNotPushed(SubmitVerifactuRecords::class);
    }

    public function test_status_prints_csv_and_errors_without_secrets(): void
    {
        [$first, $second] = $this->official();
        $first->forceFill(['state' => VerifactuRecordState::Accepted, 'csv' => 'A-CSV-0001', 'attempts' => 1])->save();
        $second->forceFill(['state' => VerifactuRecordState::Rejected, 'error_code' => '1142', 'error_message' => 'El NIF del destinatario no esta identificado.', 'attempts' => 1])->save();

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--date' => '2024-01-01']));
        $output = Artisan::output();
        $this->assertStringContainsString('accepted', $output);
        $this->assertStringContainsString('A-CSV-0001', $output);
        $this->assertStringContainsString(self::HASH_1, $output);
        $this->assertStringContainsString((string) $first->qr_url, $output);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345679/G34']));
        $output = Artisan::output();
        $this->assertStringContainsString('rejected', $output);
        $this->assertStringContainsString('1142', $output);
        $this->assertStringContainsString('El NIF del destinatario no esta identificado.', $output);
        $this->assertStringContainsString(self::HASH_2, $output);
        // The registration and the cancellation of the invoice are both listed.
        $this->assertStringContainsString(self::HASH_3, $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => 'NOPE-1']));
        $this->assertStringContainsString('No VERI*FACTU record', Artisan::output());
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--date' => '01-01-2024']));
        $this->assertStringContainsString('Y-m-d', Artisan::output());
    }

    public function test_status_lookup_reconciles_unconfirmed_with_same_huella(): void
    {
        Event::fake([VerifactuRecordAccepted::class]);
        [$first] = $this->official(1);
        $first->forceFill(['state' => VerifactuRecordState::Unconfirmed, 'attempts' => 1, 'error_code' => '3000', 'error_message' => 'Registro duplicado.', 'error_action' => ErrorAction::ResolveDuplicate->name])->save();
        $this->aeat->queue($this->soap('consulta-con-datos.xml'));

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--date' => '2024-01-01', '--lookup' => true]));
        $output = Artisan::output();

        $first->refresh();
        $this->assertSame(VerifactuRecordState::Accepted, $first->state);
        $this->assertNull($first->csv);
        $this->assertNull($first->error_code);
        $this->assertNull($first->error_action);
        $this->assertSame(1, $this->aeat->requestCount());
        $this->assertStringContainsString('Correcto', $output);
        $this->assertStringContainsString(self::HASH_1, $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        Event::assertDispatched(VerifactuRecordAccepted::class, static fn (VerifactuRecordAccepted $event): bool => $event->record->id === $first->id && ! $event->withErrors);
    }

    public function test_status_lookup_confirms_an_unconfirmed_cancellation(): void
    {
        [$first, $second, $third] = $this->official();
        $first->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $second->forceFill(['state' => VerifactuRecordState::Accepted])->save();
        $third->forceFill(['state' => VerifactuRecordState::Unconfirmed, 'attempts' => 5])->save();
        // AEAT reports the invoice as cancelled (EstadoRegistro Anulado).
        $this->aeat->queue($this->soap('consulta-con-datos.xml'));

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345679/G34', '--lookup' => true]));

        $this->assertStringContainsString('Anulado', Artisan::output());
        $this->assertSame(VerifactuRecordState::Accepted, $third->fresh()?->state);
        $this->assertSame(VerifactuRecordState::Accepted, $second->fresh()?->state);
    }

    public function test_status_lookup_leaves_the_row_when_aeat_holds_none_or_fails(): void
    {
        [$first] = $this->official(1);
        $first->forceFill(['state' => VerifactuRecordState::Unconfirmed, 'attempts' => 1, 'error_action' => ErrorAction::ResolveDuplicate->name])->save();
        $before = $first->fresh()?->getAttributes();
        $this->aeat->queue(
            // AEAT holds another record for the invoice: another Huella.
            new Response(200, [], str_replace(self::HASH_1, self::SEED_HASH, (string) $this->soap('consulta-con-datos.xml')->getBody())),
            $this->soap('consulta-sin-datos.xml'),
            FakeAeatApi::serverFault(),
            FakeAeatApi::authenticationFault(),
        );

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--lookup' => true]));
        $this->assertStringContainsString('AEAT holds another record', Artisan::output());

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--lookup' => true]));
        $this->assertStringContainsString('AEAT holds no record', Artisan::output());

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--lookup' => true]));
        $this->assertStringContainsString('12345678/G33', Artisan::output());

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--lookup' => true]));
        $output = Artisan::output();
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);

        $this->assertSame(4, $this->aeat->requestCount());
        $this->assertSame($before, $first->fresh()?->getAttributes());
    }

    public function test_status_lookup_rereads_the_b2brouter_tax_report_without_changing_the_row(): void
    {
        $api = $this->useB2Brouter();
        $api->report('1064493', VerifactuSamples::b2brouterFixture('record-1064493.xml'), ['registered']);
        $row = VerifactuRecord::query()->forceCreate([
            'kind' => 'alta',
            'issuer_nif' => SpanishInvoices::SELLER_NIF,
            'number' => SpanishInvoices::NUMBER,
            'issue_date' => SpanishInvoices::ISSUE_DATE,
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => '24433033',
            'b2brouter_report_id' => '1064493',
            'attempts' => 1,
        ]);
        $before = $row->fresh()?->getAttributes();

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => SpanishInvoices::NUMBER, '--lookup' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('registered (final)', $output);
        $this->assertStringContainsString('24433033', $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        $this->assertCount(1, $api->requests('GET'));
        $this->assertCount(0, $api->imported());
        $this->assertSame($before, $row->fresh()?->getAttributes());
    }

    public function test_status_needs_issuer_when_several(): void
    {
        config()->set('einvoicing.verifactu.issuers', [
            self::ISSUER => ['name' => 'Test Issuer S.L.'],
            self::OTHER => ['name' => 'Other Issuer S.L.'],
        ]);
        $this->official(1);

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33']));
        $this->assertStringContainsString('--issuer', Artisan::output());

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--issuer' => self::ISSUER]));
        $this->assertStringContainsString(self::HASH_1, Artisan::output());

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => '12345678/G33', '--issuer' => 'not-a-nif']));
    }

    public function test_verify_chain_passes_on_official_chain(): void
    {
        $this->official();
        // A chain row that never held a record or a seed is no chain.
        $this->unusedChain(self::OTHER);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-verify-chain'));
        $output = Artisan::output();

        $key = $this->chainKey()->toString();
        $this->assertStringContainsString($key, $output);
        $this->assertStringContainsString('3 records', $output);
        $this->assertStringNotContainsString('FAIL', $output);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-verify-chain', ['--issuer' => self::OTHER]));
        $this->assertStringNotContainsString($key, Artisan::output());
    }

    public function test_verify_chain_names_tampered_sequence(): void
    {
        [, $second] = $this->official();
        $xml = (string) $second->xml;
        $tampered = str_replace('F7B94CFD8924', 'F7B94CFE8924', $xml);
        $this->assertNotSame($xml, $tampered);
        VerifactuRecord::query()->whereKey($second->id)->toBase()->update(['xml' => $tampered]);

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-verify-chain'));
        $output = Artisan::output();

        $this->assertStringContainsString('sequence 2', $output);
        $this->assertStringNotContainsString('sequence 1', $output);
        $this->assertStringNotContainsString('sequence 3', $output);
    }

    public function test_verify_chain_flags_broken_links_and_head(): void
    {
        [$first, , $third] = $this->official();
        VerifactuRecord::query()->whereKey($third->id)->toBase()->update(['previous_hash' => self::HASH_1]);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-verify-chain'));
        $this->assertStringContainsString('sequence 3', Artisan::output());

        VerifactuRecord::query()->whereKey($third->id)->toBase()->update(['previous_hash' => self::HASH_2]);
        VerifactuChain::query()->update(['head_hash' => self::HASH_2]);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-verify-chain'));
        $this->assertStringContainsString('head', Artisan::output());

        VerifactuChain::query()->update(['head_hash' => self::HASH_3]);
        VerifactuRecord::query()->whereKey($first->id)->toBase()->update(['previous_hash' => self::SEED_HASH]);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-verify-chain'));
        $this->assertStringContainsString('sequence 1', Artisan::output());
    }

    public function test_verify_chain_trusts_seeded_head(): void
    {
        $key = $this->chainKey();
        $store = $this->manager()->store();
        $store->seed($key, VerifactuSamples::invoiceId('PREV-0001', '31-12-2023'), self::SEED_HASH);
        $chainer = new Chainer(VerifactuSamples::system());
        $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), $this->build($chainer, VerifactuSamples::vectorDraft('12345678/G33', '01-01-2024'), '2024-01-01T18:20:30Z'));
        // A chain that holds only its seed.
        $this->manager()->store()->seed(ChainKey::for(Nif::parse(self::OTHER), VerifactuSamples::system(), VerifactuEnvironment::Test), VerifactuSamples::invoiceId('PREV-9', '31-12-2023', self::OTHER), self::SEED_HASH);

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-verify-chain'));
        $output = Artisan::output();

        $this->assertStringContainsString('seeded', $output);
        $this->assertStringContainsString('1 record', $output);
        $this->assertStringNotContainsString('FAIL', $output);
    }

    public function test_seed_chain_refuses_started_chain_and_bad_hash(): void
    {
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => strtolower(self::SEED_HASH)]));
        $this->assertStringContainsString('64 upper-case', Artisan::output());
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-1', 'date' => '31-12-2023', 'hash' => self::SEED_HASH]));
        $this->assertStringContainsString('Y-m-d', Artisan::output());
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => '12345678A', 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => self::SEED_HASH]));
        $this->assertSame(0, VerifactuChain::query()->count());

        $this->assertSame(0, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => self::SEED_HASH]));
        $chain = VerifactuChain::query()->sole();
        $this->assertTrue($chain->seeded);
        $this->assertSame('383', $chain->installation_number);
        $this->assertSame(0, $chain->sequence);
        $this->assertSame(self::SEED_HASH, $chain->head_hash);
        $this->assertSame('PREV-1', $chain->head_number);
        $this->assertSame('2023-12-31', $chain->head_issue_date);

        // Seeding again, or a chain that holds records, is refused.
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-2', 'date' => '2023-12-31', 'hash' => self::SEED_HASH]));
        $this->assertStringContainsString('cannot be seeded', Artisan::output());
        $this->assertSame('PREV-1', $chain->fresh()?->head_number);

        // A chain of another installation would never be written to: the records of this
        // system chain under its own installation number.
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => self::SEED_HASH, '--installation' => '384']));
        $this->assertStringContainsString('383', Artisan::output());
        $this->assertSame(['383'], VerifactuChain::query()->pluck('installation_number')->all());

        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => 'Test Issuer S.L.'], self::OTHER => ['name' => 'Other Issuer S.L.']]);
        $this->official(1, self::OTHER);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::OTHER, 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => self::SEED_HASH]));
        $this->assertStringContainsString('cannot be seeded', Artisan::output());
    }

    public function test_close_chain_refuses_pending_records_then_unblocks_driver_switch(): void
    {
        config()->set('einvoicing.verifactu.issuers', [SpanishInvoices::SELLER_NIF => ['name' => SpanishInvoices::SELLER_NAME]]);
        $aeatRow = $this->manager()->register(SpanishInvoices::domestic());
        $this->unusedChain(SpanishInvoices::SELLER_NIF, '999', IssuerDriver::Aeat);

        // The issuer moves to B2Brouter; its AEAT chain is still open.
        $api = $this->useB2Brouter();
        $next = SpanishInvoices::invoice([SpanishInvoices::line('1', '100.00', VatCategory::StandardRate, '21')], number: 'PROBE-ES-UBL-0003');
        $refused = $this->capture(fn () => $this->manager()->register($next));
        $this->assertInstanceOf(ConfigurationException::class, $refused);
        $this->assertStringContainsString('einvoicing:verifactu-close-chain', $refused->getMessage());

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-close-chain', ['--issuer' => SpanishInvoices::SELLER_NIF]));
        $output = Artisan::output();
        $this->assertStringContainsString('generated', $output);
        // Drain, switch, close: the records left are still sent with the AEAT credentials.
        $this->assertStringContainsString('einvoicing:verifactu-submit', $output);
        $this->assertStringContainsString('AEAT credentials', $output);
        $this->assertSame(0, VerifactuChain::query()->whereNotNull('closed_at')->count());

        $aeatRow->forceFill(['state' => VerifactuRecordState::Accepted, 'csv' => 'A-CSV-0002'])->save();
        $this->assertSame(0, Artisan::call('einvoicing:verifactu-close-chain', ['--issuer' => SpanishInvoices::SELLER_NIF, '--driver' => 'aeat']));
        $chain = VerifactuChain::query()->where('installation_number', '383')->sole();
        $this->assertNotNull($chain->closed_at);
        // The unused chain row is left as it is.
        $this->assertNull(VerifactuChain::query()->where('installation_number', '999')->sole()->closed_at);

        $api->invoice('24433034', 'PROBE-ES-UBL-0003', '1064493')->onImport('24433034');
        $row = $this->manager()->register($next);
        $this->assertSame(VerifactuRecordState::Pending, $row->state);
        $this->assertSame(IssuerDriver::B2Brouter, $row->driver);
    }

    public function test_close_chain_refuses_the_chain_of_the_current_driver_and_pending_imports(): void
    {
        [$first] = $this->official(1);
        $first->forceFill(['state' => VerifactuRecordState::Accepted])->save();

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-close-chain', ['--driver' => 'aeat']));
        $this->assertStringContainsString('uses the aeat driver', Artisan::output());
        $this->assertNull(VerifactuChain::query()->sole()->closed_at);

        $this->assertSame(1, Artisan::call('einvoicing:verifactu-close-chain', ['--driver' => 'other']));

        // A B2Brouter chain with an import still pending stays open.
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => 'Test Issuer S.L.', 'driver' => 'aeat']]);
        VerifactuChain::query()->forceCreate([
            'issuer_nif' => self::ISSUER,
            'installation_number' => '1-999999',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'sequence' => 1,
            'head_number' => 'B-1',
            'head_issue_date' => '2024-01-01',
            'head_hash' => self::HASH_1,
            'head_generated_at' => '2024-01-01T19:20:30+01:00',
        ]);
        VerifactuRecord::query()->forceCreate([
            'kind' => 'alta',
            'issuer_nif' => self::ISSUER,
            'number' => 'B-2',
            'issue_date' => '2024-01-01',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => '24433050',
        ]);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-close-chain', ['--issuer' => self::ISSUER]));
        $this->assertStringContainsString('pending', Artisan::output());
        $this->assertSame(0, VerifactuChain::query()->whereNotNull('closed_at')->count());
    }

    public function test_invoice_numbers_and_messages_are_printed_as_text_not_console_markup(): void
    {
        $tagged = 'F<comment>1';

        // Status of an invoice without records: the number appears as given.
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-status', ['number' => $tagged]));
        $this->assertStringContainsString('invoice F<comment>1', Artisan::output());

        // Seed chain: the error message quotes the invalid hash as given.
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-seed-chain', ['issuer' => self::ISSUER, 'number' => 'PREV-1', 'date' => '2023-12-31', 'hash' => '<info>x']));
        $this->assertStringContainsString('"<info>x"', Artisan::output());

        // Close chain: the error message quotes the option as given.
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-close-chain', ['--driver' => '<comment>x']));
        $this->assertStringContainsString('"<comment>x"', Artisan::output());

        // Submit: a B2Brouter import that cannot be settled names its invoice as given.
        VerifactuRecord::query()->forceCreate([
            'kind' => 'alta',
            'issuer_nif' => self::ISSUER,
            'number' => $tagged,
            'issue_date' => '2024-01-01',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Unconfirmed,
            'attempts' => 1,
        ]);
        $this->assertSame(1, Artisan::call('einvoicing:verifactu-submit', ['--retry-unconfirmed' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('invoice F<comment>1', $output);
        // The way out for an import B2Brouter truly refuses.
        $this->assertStringContainsString('einvoicing:verifactu-status NUMBER --date=Y-m-d --abandon', $output);
    }

    /**
     * The official AEAT chain (hash specification v0.1.2 §6) stored through the database store:
     * two registrations and a cancellation, in state generated.
     *
     * @return list<VerifactuRecord>
     */
    private function official(int $count = 3, string $issuer = self::ISSUER): array
    {
        $chainer = new Chainer(VerifactuSamples::system());
        $store = $this->manager()->store();
        $key = $this->chainKey($issuer);
        $steps = [
            [VerifactuSamples::vectorDraft('12345678/G33', '01-01-2024', $issuer), '2024-01-01T18:20:30Z'],
            [VerifactuSamples::vectorDraft('12345679/G34', '01-01-2024', $issuer), '2024-01-01T18:20:35Z'],
            [CancellationDraft::of(VerifactuSamples::invoiceId('12345679/G34', '01-01-2024', $issuer)), '2024-01-01T18:20:40Z'],
        ];

        $rows = [];
        foreach (array_slice($steps, 0, $count) as [$draft, $at]) {
            $rows[] = $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), $this->build($chainer, $draft, $at));
        }

        return $rows;
    }

    private function generate(string $number, string $issuer = self::ISSUER, ThrottleScope $scope = ThrottleScope::Installation): VerifactuRecord
    {
        $chainer = new Chainer(VerifactuSamples::system());
        $throttle = ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test, $scope, $scope === ThrottleScope::Issuer ? Nif::parse($issuer) : null);

        return $this->manager()->store()->appendRow(
            $this->chainKey($issuer),
            IssuerDriver::Aeat,
            $throttle,
            $this->build($chainer, VerifactuSamples::vectorDraft($number, '01-01-2024', $issuer), self::NOW),
        );
    }

    /**
     * @return Closure(?ChainHead): BillingRecord
     */
    private function build(Chainer $chainer, RegistrationDraft|CancellationDraft $draft, string $now): Closure
    {
        return static fn (?ChainHead $head): BillingRecord => $chainer->append($draft, $head, new DateTimeImmutable($now))[0];
    }

    private function unusedChain(string $issuer, string $installation = '383', IssuerDriver $driver = IssuerDriver::B2Brouter): VerifactuChain
    {
        return VerifactuChain::query()->forceCreate([
            'issuer_nif' => $issuer,
            'installation_number' => $installation,
            'environment' => VerifactuEnvironment::Test,
            'driver' => $driver,
            'sequence' => 0,
        ]);
    }

    private function chainKey(string $issuer = self::ISSUER): ChainKey
    {
        return ChainKey::for(Nif::parse($issuer), VerifactuSamples::system(), VerifactuEnvironment::Test);
    }

    private function throttle(): ThrottleKey
    {
        return ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test);
    }

    private function key(string $issuer = self::ISSUER, ThrottleScope $scope = ThrottleScope::Installation): string
    {
        return ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test, $scope, $scope === ThrottleScope::Issuer ? Nif::parse($issuer) : null)->toString();
    }

    /**
     * @return list<string>
     */
    private function scheduledKeys(): array
    {
        return array_values(array_map(static fn (SubmitVerifactuRecords $job): string => $job->throttleKey, Queue::pushed(SubmitVerifactuRecords::class)->all()));
    }

    /**
     * @param  list<string>  $keys
     */
    private function releaseLocks(array $keys): void
    {
        foreach ($keys as $key) {
            $this->app->make(Cache::class)->lock(UniqueLock::getKey(new SubmitVerifactuRecords($key)))->forceRelease();
        }
    }

    private function useB2Brouter(): FakeB2BrouterVerifactuApi
    {
        config()->set('einvoicing.verifactu.issuers', [SpanishInvoices::SELLER_NIF => ['name' => SpanishInvoices::SELLER_NAME, 'driver' => 'b2brouter']]);
        $api = new FakeB2BrouterVerifactuApi;
        $this->app->instance(B2BrouterVerifactu::class, $api->client(apiKey: 'test_'.self::SECRET_MARK.'key'));

        return $api;
    }

    private function soap(string $fixture): Response
    {
        $body = file_get_contents(dirname(__DIR__).'/fixtures/verifactu/aeat/'.$fixture);
        $this->assertIsString($body);

        return new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], $body);
    }

    private function manager(): VerifactuManager
    {
        return $this->app->make(VerifactuManager::class);
    }

    private function capture(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
