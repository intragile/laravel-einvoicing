<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Tests\Support\AeatCertificates;
use IntrAgile\EInvoicing\Laravel\Tests\Support\CallbackValidator;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuClients;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuDoctor;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainStore;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\CaBundle;
use IntrAgile\EInvoicing\Transport\Aeat\ClientCertificate;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class DoctorVerifactuTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = VerifactuSamples::ISSUER_NIF;

    private const OTHER = VerifactuSamples::SANDBOX_ISSUER_NIF;

    private const PASSWORD = 'pw-'.self::SECRET_MARK;

    private const HASH_A = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

    private const HASH_B = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

    /** Seconds the TLS server child lives at most. */
    private const SERVER_LIFETIME = 10;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $dir;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');

        // The verifactu-databases group runs the doctor's queries on a throwaway server database.
        $url = getenv('EINVOICING_TEST_DB_URL');
        if (is_string($url) && $url !== '') {
            $config = (new ConfigurationUrlParser)->parseConfiguration(['url' => $url]);
            $config['driver'] = match ($config['driver'] ?? null) {
                'mysql', 'mariadb' => 'mysql',
                'pgsql', 'postgres', 'postgresql' => 'pgsql',
                default => throw new InvalidArgumentException('EINVOICING_TEST_DB_URL must be a mysql:// or pgsql:// URL.'),
            };
            $config += $config['driver'] === 'mysql'
                ? ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']
                : ['charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer'];
            $app['config']->set('database.default', 'doctor');
            $app['config']->set('database.connections.doctor', $config);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureRecommand();
        $this->app->instance(DocumentValidator::class, new CallbackValidator(static fn () => CallbackValidator::valid()));
        $this->dir = $this->emptyDir();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server);
        }

        parent::tearDown();
    }

    public function test_not_configured_is_info_only(): void
    {
        config()->set('einvoicing.verifactu.issuers', []);
        $resolved = false;
        $this->app->bind(ChainStore::class, static function () use (&$resolved): never {
            $resolved = true;

            throw new LogicException('The chain store must not be built without issuers.');
        });
        // Without issuers, missing tables are no failure: the application does not use VERI*FACTU.
        Schema::drop(VerifactuRecord::TABLE);

        $this->assertSame([['verifactu', 'info', 'not configured: einvoicing.verifactu.issuers is empty']], $this->doctorRows());
        $this->assertFalse($resolved);

        [$code, $output] = $this->doctor();
        $this->assertSame(0, $code, $output);
        $this->assertRow($output, 'verifactu', 'info', 'not configured');
        $this->assertStringNotContainsString('verifactu:', $output);
        $this->assertFalse($resolved);
    }

    #[Group('verifactu-official')]
    public function test_complete_config_is_ok(): void
    {
        $this->configureComplete();

        $rows = $this->doctorRows();

        $this->assertSame([
            'verifactu: config',
            'verifactu: schemas',
            'verifactu: system',
            'verifactu: issuer '.self::ISSUER,
            'verifactu: certificate '.self::ISSUER,
            'verifactu: ca bundle',
            'verifactu: tables',
            'verifactu: driver switch',
            'verifactu: chain heads',
            'verifactu: stuck records',
            'verifactu: b2brouter chains',
            'verifactu: submission',
        ], array_column($rows, 0));
        foreach ($rows as [$check, $status, $detail]) {
            $this->assertSame('ok', $status, "{$check}: {$detail}");
        }
        $certificate = $this->row($rows, 'verifactu: certificate '.self::ISSUER);
        $this->assertStringContainsString('IDCES-'.self::ISSUER, $certificate[2]);
        $this->assertStringContainsString('NIF '.self::ISSUER, $certificate[2]);
        $this->assertStringContainsString('valid until', $certificate[2]);
    }

    public function test_missing_system_keys_fail(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.system.name', null);
        config()->set('einvoicing.verifactu.system.installation_number', '');
        config()->set('einvoicing.verifactu.schemas_dir', $this->dir);

        $rows = $this->doctorRows();

        $this->assertRowIn($rows, 'verifactu: system', 'fail', 'einvoicing.verifactu.system.name');
        $this->assertStringContainsString('einvoicing.verifactu.system.installation_number', $this->row($rows, 'verifactu: system')[2]);
        $schemas = $this->row($rows, 'verifactu: schemas');
        $this->assertSame('fail', $schemas[1]);
        $this->assertStringContainsString($this->dir.'/verifactu-tikeV1.0/', $schemas[2]);
        $this->assertStringContainsString('is missing', $schemas[2]);
        $this->assertStringContainsString('php artisan einvoicing:verifactu-install-schemas', $schemas[2]);
    }

    public function test_system_is_not_needed_when_every_issuer_uses_b2brouter(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.system.name', null);
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.driver', 'b2brouter');

        $rows = $this->doctorRows();

        $this->assertRowIn($rows, 'verifactu: system', 'info', 'B2Brouter');
        $this->assertSame('verifactu: system', $rows[2][0]);

        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.driver', 'aeat');
        $this->assertRowIn($this->doctorRows(), 'verifactu: system', 'fail', 'einvoicing.verifactu.system.name');
    }

    public function test_invalid_issuer_keys_fail_each(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.issuers', [
            'NOTANIF' => ['name' => 'Broken One'],
            self::ISSUER => ['name' => 'Test Issuer S.L.'],
            '12345678A' => ['name' => 'Broken Two'],
            self::OTHER => [],
        ]);

        $rows = $this->doctorRows();

        $this->assertRowIn($rows, 'verifactu: issuer NOTANIF', 'fail', 'not a valid Spanish NIF');
        $this->assertRowIn($rows, 'verifactu: issuer 12345678A', 'fail', 'not a valid Spanish NIF');
        $this->assertRowIn($rows, 'verifactu: issuer '.self::ISSUER, 'ok', 'Test Issuer S.L.');
        $this->assertRowIn($rows, 'verifactu: issuer '.self::OTHER, 'fail', 'einvoicing.verifactu.issuers.'.self::OTHER.'.name');
        $this->assertRowIn($rows, 'verifactu: certificate '.self::ISSUER, 'ok');
    }

    public function test_expiring_certificate_warns(): void
    {
        $this->configureComplete(schemas: false, days: 10);
        $rows = $this->doctorRows();
        $this->assertRowIn($rows, 'verifactu: certificate '.self::ISSUER, 'warn', 'expires in');

        // A certificate of another holder.
        $this->configureComplete(schemas: false, holder: self::OTHER);
        $this->assertRowIn($this->doctorRows(), 'verifactu: certificate '.self::ISSUER, 'warn', 'names NIF '.self::OTHER.', not the issuer');

        // A certificate held by the issuer's representative is the expected one.
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.representative', ['name' => 'Gestoria S.L.', 'nif' => self::OTHER]);
        $this->assertRowIn($this->doctorRows(), 'verifactu: certificate '.self::ISSUER, 'ok', 'representative');

        // An expired certificate fails.
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.representative', null);
        $this->configureComplete(schemas: false, days: 0);
        $this->travel(2)->days();
        $this->assertRowIn($this->doctorRows(), 'verifactu: certificate '.self::ISSUER, 'fail', 'expired');

        // An unreadable certificate fails; a wrong password is never quoted.
        config()->set('einvoicing.verifactu.aeat.password', 'wrong-'.self::SECRET_MARK);
        $row = $this->row($this->doctorRows(), 'verifactu: certificate '.self::ISSUER);
        $this->assertSame('fail', $row[1]);
        $this->assertStringContainsString('cannot be read', $row[2]);
        $this->assertStringNotContainsString(self::SECRET_MARK, $row[2]);

        config()->set('einvoicing.verifactu.aeat.certificate', $this->dir.'/missing.p12');
        $this->assertRowIn($this->doctorRows(), 'verifactu: certificate '.self::ISSUER, 'fail', $this->dir.'/missing.p12');
    }

    public function test_missing_fnmt_root_warns(): void
    {
        $this->configureComplete(schemas: false);
        $bundle = $this->write('unrelated.pem', AeatCertificates::selfSigned('Unrelated Test Root CA'));
        config()->set('einvoicing.verifactu.aeat.ca_bundle', $bundle);

        $this->assertRowIn($this->doctorRows(), 'verifactu: ca bundle', 'warn', CaBundle::FNMT_ROOT_CN);
        $this->assertStringContainsString('einvoicing.verifactu.aeat.ca_bundle', $this->row($this->doctorRows(), 'verifactu: ca bundle')[2]);

        config()->set('einvoicing.verifactu.aeat.ca_bundle', $this->dir.'/missing.pem');
        $this->assertRowIn($this->doctorRows(), 'verifactu: ca bundle', 'fail', $this->dir.'/missing.pem');

        // Without an AEAT issuer the bundle is not needed.
        config()->set('einvoicing.verifactu.driver', 'b2brouter');
        $this->assertNotContains('verifactu: ca bundle', array_column($this->doctorRows(), 0));
    }

    public function test_b2brouter_key_prefix_must_match_environment(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.driver', 'b2brouter');

        // No transport named.
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter key', 'fail', 'einvoicing.verifactu.b2brouter.transport');

        config()->set('einvoicing.verifactu.b2brouter.transport', 'b2b');
        config()->set('einvoicing.transports.b2b', ['driver' => 'b2brouter', 'api_key' => 'live-'.self::SECRET_MARK, 'account_id' => '999999']);
        $rows = $this->doctorRows();
        $this->assertRowIn($rows, 'verifactu: b2brouter key', 'fail', 'test_');
        $this->assertStringContainsString('einvoicing.transports.b2b.api_key', $this->row($rows, 'verifactu: b2brouter key')[2]);
        // No certificate is needed on the B2Brouter driver.
        $this->assertNotContains('verifactu: certificate '.self::ISSUER, array_column($rows, 0));

        config()->set('einvoicing.transports.b2b.api_key', 'test_'.self::SECRET_MARK);
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter key', 'ok', 'test');

        config()->set('einvoicing.verifactu.environment', 'production');
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter key', 'fail', 'production');

        config()->set('einvoicing.transports.b2b.api_key', 'live-'.self::SECRET_MARK);
        $rows = $this->doctorRows();
        $this->assertRowIn($rows, 'verifactu: b2brouter key', 'ok', 'production');

        config()->set('einvoicing.transports.b2b.driver', 'recommand');
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter key', 'fail', 'not a B2Brouter transport');

        $this->assertStringNotContainsString(self::SECRET_MARK, json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_driver_switch_fails(): void
    {
        $this->configureComplete(schemas: false);
        $this->chain(self::ISSUER, '383', IssuerDriver::Aeat, 1, self::HASH_A);
        $this->record(self::ISSUER, 1, 1, 'F-1', self::HASH_A);
        // An unused chain row of the other driver is no chain.
        $unused = $this->chain(self::ISSUER, '1-999999', IssuerDriver::B2Brouter, 0, null);
        // Chains of other issuers, environments, and closed ones do not count.
        $this->chain(self::OTHER, '1-999999', IssuerDriver::B2Brouter, 0, null, seeded: true, hash: self::HASH_B);
        $this->chain(self::ISSUER, '1-999999', IssuerDriver::B2Brouter, 3, self::HASH_B, environment: VerifactuEnvironment::Production);

        $this->assertRowIn($this->doctorRows(), 'verifactu: driver switch', 'ok');

        $unused->forceFill(['sequence' => 1, 'head_number' => 'B-1', 'head_issue_date' => '2026-11-23', 'head_hash' => self::HASH_B])->save();
        $this->record(self::ISSUER, $unused->id, 1, 'B-1', self::HASH_B, IssuerDriver::B2Brouter);
        $row = $this->row($this->doctorRows(), 'verifactu: driver switch');
        $this->assertSame('fail', $row[1]);
        $this->assertStringContainsString(self::ISSUER, $row[2]);
        $this->assertStringContainsString('aeat', $row[2]);
        $this->assertStringContainsString('b2brouter', $row[2]);
        $this->assertStringContainsString('einvoicing:verifactu-close-chain', $row[2]);

        $unused->forceFill(['closed_at' => Carbon::now()])->save();
        $this->assertRowIn($this->doctorRows(), 'verifactu: driver switch', 'ok');
    }

    public function test_head_mismatch_fails_and_seeded_is_reported(): void
    {
        $this->configureComplete(schemas: false);
        $good = $this->chain(self::ISSUER, '383', IssuerDriver::Aeat, 2, self::HASH_B, number: 'F-2');
        $this->record(self::ISSUER, $good->id, 1, 'F-1', self::HASH_A);
        $this->record(self::ISSUER, $good->id, 2, 'F-2', self::HASH_B);
        $this->chain(self::OTHER, '383', IssuerDriver::Aeat, 0, null, seeded: true, hash: self::HASH_A, number: 'OLD-9');

        $rows = $this->doctorRows();
        $this->assertRowIn($rows, 'verifactu: chain heads', 'ok', '1 chain');
        $this->assertRowIn($rows, 'verifactu: seeded chains', 'info', 'OLD-9');

        $bad = $this->chain(self::OTHER, '384', IssuerDriver::Aeat, 1, self::HASH_B, number: 'G-1');
        $this->record(self::OTHER, $bad->id, 1, 'G-1', self::HASH_A);
        // A head without its record.
        $this->chain(self::ISSUER, '385', IssuerDriver::Aeat, 4, self::HASH_A);

        $row = $this->row($this->doctorRows(), 'verifactu: chain heads');
        $this->assertSame('fail', $row[1]);
        $this->assertStringContainsString(self::OTHER.'|384', $row[2]);
        $this->assertStringContainsString(self::ISSUER.'|385', $row[2]);
        $this->assertStringNotContainsString('|383', $row[2]);
        $this->assertStringContainsString('einvoicing:verifactu-verify-chain', $row[2]);
    }

    public function test_stuck_records_warn(): void
    {
        $this->configureComplete(schemas: false);
        $chain = $this->chain(self::ISSUER, '383', IssuerDriver::Aeat, 4, self::HASH_B);
        $this->record(self::ISSUER, $chain->id, 1, 'F-1', self::HASH_A, state: VerifactuRecordState::Generated, age: 120);
        $this->record(self::ISSUER, $chain->id, 2, 'F-2', null, state: VerifactuRecordState::Sending, age: 61);
        // Within the threshold, or settled.
        $this->record(self::ISSUER, $chain->id, 3, 'F-3', null, state: VerifactuRecordState::Sending, age: 5);
        $this->record(self::ISSUER, $chain->id, 4, 'F-4', self::HASH_B, state: VerifactuRecordState::Accepted, age: 600);

        $row = $this->row($this->doctorRows(), 'verifactu: stuck records');
        $this->assertSame('warn', $row[1]);
        $this->assertStringContainsString('generated 1', $row[2]);
        $this->assertStringContainsString('sending 1', $row[2]);
        $this->assertStringNotContainsString('sending 2', $row[2]);
        $this->assertStringContainsString('php artisan einvoicing:verifactu-submit --retry-unconfirmed', $row[2]);
        $this->assertStringContainsString('60 minutes', $row[2]);

        config()->set('einvoicing.verifactu.stuck_after_minutes', 180);
        $this->assertRowIn($this->doctorRows(), 'verifactu: stuck records', 'ok');
    }

    public function test_blocked_b2brouter_chain_fails(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.driver', 'b2brouter');
        // The chain it should have joined, last changed before the record failed.
        $chain = $this->chain(self::ISSUER, '1-999999', IssuerDriver::B2Brouter, 2, self::HASH_B);
        VerifactuChain::query()->whereKey($chain->id)->toBase()->update(['updated_at' => Carbon::now()->subHour()]);
        $failed = $this->pending('B-7', age: 10, attributes: ['state' => VerifactuRecordState::Failed, 'error_code' => 'chain_conflict', 'error_message' => 'The record cannot join its chain.']);

        $row = $this->row($this->doctorRows(), 'verifactu: b2brouter chains');
        $this->assertSame('fail', $row[1]);
        $this->assertStringContainsString(self::ISSUER, $row[2]);
        $this->assertStringContainsString('row '.$failed->id, $row[2]);
        $this->assertStringContainsString('B-7', $row[2]);
        $this->assertStringContainsString('chain_conflict', $row[2]);

        // Once the chain moved on (re-seeded or closed by the operator), it is no longer blocked.
        $chain->forceFill(['closed_at' => Carbon::now()])->save();
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter chains', 'ok');
    }

    public function test_stalled_b2brouter_predecessor_warns(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.issuers.'.self::ISSUER.'.driver', 'b2brouter');
        $stalled = $this->pending('B-1', age: 30, attributes: ['error_message' => 'The registration is not known after 10 polls of B2Brouter.']);
        $this->pending('B-2', age: 20, attributes: ['error_code' => PollVerifactuTaxReport::AWAITING_PREDECESSOR]);
        $this->pending('B-3', age: 20, attributes: ['error_code' => PollVerifactuTaxReport::AWAITING_PREDECESSOR]);

        $row = $this->row($this->doctorRows(), 'verifactu: b2brouter chains');
        $this->assertSame('warn', $row[1]);
        $this->assertStringContainsString('row '.$stalled->id, $row[2]);
        $this->assertStringContainsString('B-1', $row[2]);
        $this->assertStringContainsString('2 records wait', $row[2]);
        $this->assertStringContainsString('php artisan einvoicing:verifactu-submit --retry-unconfirmed', $row[2]);

        // A predecessor whose polls are still running is not reported.
        VerifactuRecord::query()->whereKey($stalled->id)->toBase()->update(['updated_at' => Carbon::now()->subSeconds(30)]);
        $this->assertRowIn($this->doctorRows(), 'verifactu: b2brouter chains', 'ok');
    }

    #[Group('verifactu-databases')]
    public function test_failed_cross_checks_are_counted_in_sql_and_listed_up_to_the_limit(): void
    {
        $this->configureComplete(schemas: false);
        $chain = $this->chain(self::ISSUER, '1-999999', IssuerDriver::B2Brouter, 9, self::HASH_B);
        $failed = [];
        foreach (range(1, 9) as $sequence) {
            $row = $this->record(self::ISSUER, $chain->id, $sequence, "B-{$sequence}", null, IssuerDriver::B2Brouter, VerifactuRecordState::Registered);
            $passed = $sequence === 1 || $sequence === 9;
            $row->forceFill(['cross_check' => ['passed' => $passed, 'summary' => $passed ? 'passed' : 'failed', 'failures' => [], 'known_divergences' => []]])->save();
            if (! $passed) {
                $failed[] = $row;
            }
        }
        // An unattached failure is reported by the chain rows, not here; a row without a check is skipped.
        $this->pending('B-10', age: 0, attributes: ['state' => VerifactuRecordState::Failed, 'cross_check' => ['passed' => false]]);
        $this->record(self::ISSUER, $chain->id, 10, 'B-11', null, IssuerDriver::B2Brouter, VerifactuRecordState::Registered);

        $row = $this->row($this->doctorRows(), 'verifactu: cross-check');
        $this->assertSame('warn', $row[1]);
        $this->assertStringStartsWith('7 B2Brouter records failed', $row[2]);
        foreach (array_slice($failed, 0, 5) as $listed) {
            $this->assertStringContainsString("row {$listed->id}, {$listed->number}", $row[2]);
        }
        $this->assertStringNotContainsString("row {$failed[5]->id},", $row[2]);
        $this->assertStringContainsString('and 2 more', $row[2]);

        VerifactuRecord::query()->whereKey(array_map(static fn (VerifactuRecord $row): int => $row->id, $failed))->delete();
        $this->assertNotContains('verifactu: cross-check', array_column($this->doctorRows(), 0));
    }

    public function test_blocked_submission_fails(): void
    {
        $this->configureComplete(schemas: false);
        VerifactuThrottle::query()->forceCreate(['key' => 'A39200019|77|383|test', 'wait_seconds' => 60]);
        $this->assertRowIn($this->doctorRows(), 'verifactu: submission', 'ok');

        VerifactuThrottle::query()->forceCreate([
            'key' => 'A39200019|77|384|test',
            'wait_seconds' => 60,
            'blocked_at' => Carbon::now()->subHour(),
            'blocked_code' => '4104',
            'blocked_message' => 'Error en la cabecera: el NIF del obligado no esta identificado.',
        ]);

        $row = $this->row($this->doctorRows(), 'verifactu: submission');
        $this->assertSame('fail', $row[1]);
        $this->assertStringContainsString('A39200019|77|384|test', $row[2]);
        $this->assertStringContainsString('4104', $row[2]);
        $this->assertStringContainsString('el NIF del obligado no esta identificado', $row[2]);
        $this->assertStringContainsString('php artisan einvoicing:verifactu-submit', $row[2]);
        $this->assertStringNotContainsString('|383|', $row[2]);
    }

    public function test_doctor_output_carries_no_secret(): void
    {
        $this->configureComplete(schemas: false);
        config()->set('einvoicing.verifactu.issuers.'.self::OTHER, ['name' => 'Other S.L.', 'driver' => 'b2brouter']);
        config()->set('einvoicing.verifactu.b2brouter.transport', 'b2b');
        config()->set('einvoicing.transports.b2b', ['driver' => 'b2brouter', 'api_key' => 'live-'.self::SECRET_MARK, 'account_id' => '999999']);
        // Stored text that quotes a credential, as a faulty proxy or listener might.
        VerifactuThrottle::query()->forceCreate([
            'key' => 'A39200019|77|383|test',
            'wait_seconds' => 60,
            'blocked_at' => Carbon::now(),
            'blocked_code' => '4104',
            'blocked_message' => 'Rejected with password '.self::PASSWORD.' and key live-'.self::SECRET_MARK,
        ]);

        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertRow($output, 'verifactu: submission', 'fail', '[redacted]');
        $this->assertRow($output, 'verifactu: certificate '.self::ISSUER, 'ok');

        // A wrong password and an unreadable certificate.
        config()->set('einvoicing.verifactu.aeat.password', 'wrong-'.self::SECRET_MARK);
        VerifactuThrottle::query()->update(['blocked_message' => 'Rejected with password wrong-'.self::SECRET_MARK]);
        [, $wrong] = $this->doctor();
        $this->assertRow($wrong, 'verifactu: certificate '.self::ISSUER, 'fail');

        foreach ([$output, $wrong] as $text) {
            $this->assertStringNotContainsString(self::SECRET_MARK, $text);
            $this->assertStringNotContainsString('PRIVATE KEY', $text);
        }
    }

    public function test_live_probe_is_not_run_without_live(): void
    {
        $this->configureComplete(schemas: false);

        $this->assertSame([], array_values(array_filter(array_column($this->doctorRows(), 0), static fn (string $check): bool => str_contains($check, 'tls'))));

        // With --live, only issuers on the AEAT driver are probed; a B2Brouter issuer needs no
        // network to be reported, so no AEAT host is contacted here.
        config()->set('einvoicing.verifactu.driver', 'b2brouter');
        $rows = (new VerifactuDoctor($this->app))->rows(true);
        $this->assertRowIn($rows, 'verifactu: tls '.self::ISSUER, 'info', 'B2Brouter');
        $this->assertSame([], glob($this->app->storagePath(VerifactuClients::TLS_PATH).'/doctor-*') ?: []);
    }

    public function test_tls_probe_presents_the_materialized_certificate(): void
    {
        $server = AeatCertificates::server();
        $caFile = $this->write('ca.pem', AeatCertificates::caPem());
        $port = $this->startServer($this->write('server.pem', $server['certificate']), $this->write('server-key.pem', $server['privateKey']), $caFile);
        $client = AeatCertificates::client(['commonName' => 'Probe Client', 'serialNumber' => 'IDCES-'.self::ISSUER]);
        $certificate = ClientCertificate::fromPem($client['certificate'], $client['privateKey']);
        $doctor = new VerifactuDoctor($this->app);

        $seen = [];
        $inspect = function (string $folder) use (&$seen): void {
            clearstatcache();
            $this->assertSame(0700, fileperms($folder) & 0777);
            $files = glob($folder.'/aeat-*.pem') ?: [];
            $this->assertCount(2, $files);
            foreach ($files as $file) {
                $this->assertSame(0600, fileperms($file) & 0777);
            }
            $seen[] = $folder;
        };
        // A permissive umask must not widen the folder.
        $umask = umask(0);
        try {
            $this->assertNull($doctor->probeTls($certificate, '127.0.0.1', $port, $caFile, inspect: $inspect));
        } finally {
            umask($umask);
        }
        $this->assertCount(1, $seen);
        $this->assertDirectoryDoesNotExist($seen[0]);

        // A bundle without the server's root fails the handshake.
        $other = $this->write('other.pem', AeatCertificates::selfSigned('Unrelated Test Root CA'));
        $this->assertNotNull($doctor->probeTls($certificate, '127.0.0.1', $port, $other));

        // The written certificate files and their folder are gone afterwards.
        $this->assertSame([], glob($this->app->storagePath(VerifactuClients::TLS_PATH).'/doctor-*') ?: []);
    }

    #[Group('verifactu-official')]
    public function test_doctor_exit_code_fails_only_on_fail_rows(): void
    {
        $this->configureComplete(days: 10);
        $bundle = $this->write('unrelated.pem', AeatCertificates::selfSigned('Unrelated Test Root CA'));
        config()->set('einvoicing.verifactu.aeat.ca_bundle', $bundle);

        [$code, $output] = $this->doctor();
        $this->assertRow($output, 'verifactu: certificate '.self::ISSUER, 'warn');
        $this->assertRow($output, 'verifactu: ca bundle', 'warn');
        $this->assertDoesNotMatchRegularExpression('/\|\s*fail\s*\|/', $output);
        $this->assertSame(0, $code, $output);

        VerifactuThrottle::query()->forceCreate(['key' => 'k', 'wait_seconds' => 60, 'blocked_at' => Carbon::now(), 'blocked_code' => '4104', 'blocked_message' => 'Blocked.']);
        [$code, $output] = $this->doctor();
        $this->assertRow($output, 'verifactu: submission', 'fail');
        $this->assertSame(1, $code);
    }

    public function test_retry_after_counts_the_verifactu_jobs(): void
    {
        config()->set('queue.default', 'db');
        config()->set('queue.connections.db', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 200]);
        config()->set('einvoicing.verifactu.aeat.timeout', 300);

        config()->set('einvoicing.verifactu.issuers', []);
        [, $output] = $this->doctor();
        $this->assertRow($output, 'queue retry_after', 'ok');

        $this->configureComplete(schemas: false);
        [, $output] = $this->doctor();
        // SubmitVerifactuRecords: the AEAT timeout of 300 s plus its margin.
        $this->assertRow($output, 'queue retry_after', 'warn', 'longest 360 s');
    }

    /**
     * A complete configuration: the computer system, one AEAT issuer whose PKCS#12 names it in
     * serialNumber, and a CA bundle holding a certificate named like the FNMT root.
     */
    private function configureComplete(bool $schemas = true, int $days = 365, string $holder = self::ISSUER): void
    {
        config()->set('einvoicing.verifactu.schemas_dir', $schemas ? VerifactuFiles::schemasDir() : $this->dir.'/schemas');
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
        $client = AeatCertificates::client(['commonName' => 'Test Issuer S.L.', 'serialNumber' => 'IDCES-'.$holder], $days);
        $p12 = $this->write('issuer-'.bin2hex(random_bytes(4)).'.p12', AeatCertificates::pkcs12($client['certificate'], $client['privateKey'], self::PASSWORD));
        config()->set('einvoicing.verifactu.aeat.certificate', $p12);
        config()->set('einvoicing.verifactu.aeat.password', self::PASSWORD);
        config()->set('einvoicing.verifactu.aeat.ca_bundle', $this->write('fnmt.pem', AeatCertificates::selfSigned(CaBundle::FNMT_ROOT_CN)));
    }

    /**
     * @param  array<string, mixed>  $driverColumns
     */
    private function chain(
        string $issuer,
        string $installation,
        IssuerDriver $driver,
        int $sequence,
        ?string $headHash,
        bool $seeded = false,
        ?string $hash = null,
        string $number = 'HEAD-1',
        VerifactuEnvironment $environment = VerifactuEnvironment::Test,
        array $driverColumns = [],
    ): VerifactuChain {
        $head = $headHash ?? $hash;

        return VerifactuChain::query()->forceCreate([
            'issuer_nif' => $issuer,
            'installation_number' => $installation,
            'environment' => $environment,
            'driver' => $driver,
            'throttle_key' => $driver === IssuerDriver::Aeat ? 'A39200019|77|'.$installation.'|'.$environment->value : null,
            'sequence' => $sequence,
            'head_number' => $head === null ? null : $number,
            'head_issue_date' => $head === null ? null : '2026-11-23',
            'head_hash' => $head,
            'seeded' => $seeded,
            ...$driverColumns,
        ]);
    }

    private function record(
        string $issuer,
        int $chainId,
        int $sequence,
        string $number,
        ?string $hash,
        IssuerDriver $driver = IssuerDriver::Aeat,
        VerifactuRecordState $state = VerifactuRecordState::Accepted,
        int $age = 0,
    ): VerifactuRecord {
        $row = VerifactuRecord::query()->forceCreate([
            'chain_id' => $chainId,
            'sequence' => $sequence,
            'kind' => 'alta',
            'issuer_nif' => $issuer,
            'number' => $number,
            'issue_date' => '2026-11-23',
            'environment' => VerifactuEnvironment::Test,
            'driver' => $driver,
            'hash' => $hash,
            'state' => $state,
        ]);
        VerifactuRecord::query()->whereKey($row->id)->toBase()->update(['updated_at' => Carbon::now()->subMinutes($age)]);

        return $row;
    }

    /**
     * An unattached B2Brouter row of the issuer, last written $age minutes ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pending(string $number, int $age, array $attributes = []): VerifactuRecord
    {
        $row = VerifactuRecord::query()->forceCreate([
            'kind' => 'alta',
            'issuer_nif' => self::ISSUER,
            'number' => $number,
            'issue_date' => '2026-11-23',
            'environment' => VerifactuEnvironment::Test,
            'driver' => IssuerDriver::B2Brouter,
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => '2443'.random_int(1000, 9999),
            'attempts' => 1,
            ...$attributes,
        ]);
        VerifactuRecord::query()->whereKey($row->id)->toBase()->update(['updated_at' => Carbon::now()->subMinutes($age)]);

        return $row->refresh();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function doctorRows(): array
    {
        return (new VerifactuDoctor($this->app))->rows(false);
    }

    /**
     * @param  list<array{string, string, string}>  $rows
     * @return array{string, string, string}
     */
    private function row(array $rows, string $check): array
    {
        foreach ($rows as $row) {
            if ($row[0] === $check) {
                return $row;
            }
        }

        $this->fail("No row {$check} in: ".json_encode($rows, JSON_PRETTY_PRINT));
    }

    /**
     * @param  list<array{string, string, string}>  $rows
     */
    private function assertRowIn(array $rows, string $check, string $status, ?string $detail = null): void
    {
        $row = $this->row($rows, $check);
        $this->assertSame($status, $row[1], "{$check}: {$row[2]}");
        if ($detail !== null) {
            $this->assertStringContainsString($detail, $row[2]);
        }
    }

    /**
     * @return array{int, string}
     */
    private function doctor(array $options = []): array
    {
        $code = Artisan::call('einvoicing:doctor', $options);

        return [$code, Artisan::output()];
    }

    private function assertRow(string $output, string $check, string $status, ?string $detail = null): void
    {
        $pattern = '/\|\s*'.preg_quote($check, '/').'\s*\|\s*'.preg_quote($status, '/').'\s*\|'
            .($detail !== null ? '[^\n]*'.preg_quote($detail, '/') : '').'/';
        $this->assertMatchesRegularExpression($pattern, $output);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function startServer(string $certificate, string $key, string $caFile): int
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__).'/Support/tls-echo-server.php', $certificate, $key, $caFile, (string) self::SERVER_LIFETIME];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the TLS test server.');
        }
        $this->server = $process;
        $this->pipes = $pipes;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $line = '';
        $deadline = hrtime(true) / 1e9 + self::SERVER_LIFETIME;
        while (! str_contains($line, "\n")) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0 || ! proc_get_status($process)['running']) {
                stream_set_blocking($pipes[2], false);
                $this->fail('The TLS test server did not start: '.stream_get_contents($pipes[2]));
            }
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200_000, $remaining * 1e6)) > 0) {
                $line .= (string) fread($pipes[1], 64);
            }
        }
        if (preg_match('/\APORT (\d+)\n/', $line, $match) !== 1) {
            $this->fail('The TLS test server reported no port.');
        }

        return (int) $match[1];
    }
}
