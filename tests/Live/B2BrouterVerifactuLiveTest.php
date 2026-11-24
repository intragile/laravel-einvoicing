<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Live;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRegistered;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Tests\Support\SpanishInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Model\PrecedingInvoiceReference;
use IntrAgile\EInvoicing\Spain\ExemptOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CheckStatus;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\ChillerlanQrDecoder;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\VerifactuPolling;
use IntrAgile\EInvoicing\Transport\Http\Redactor;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use Throwable;

/**
 * Registers VERI*FACTU records through the installed bridge on the B2Brouter Spanish sandbox: two
 * chained domestic invoices, an exempt one and a credit note by difference (R1, I), each polled to
 * registered, attached to one B2Brouter chain and cross-checked against our own engine.
 *
 * Needs B2BROUTER_ES_API_KEY and B2BROUTER_ES_ACCOUNT_ID of the Spanish sandbox account and skips
 * without them. Only a "test_" key on https://api.b2brouter.net is accepted; any other key or host
 * fails the test before the first request, and no base-URL variable is read. AEAT is never called.
 * The installed AEAT schemas and chillerlan/php-qrcode with ext-gd or ext-imagick are required.
 *
 * The queue runs synchronously, so register() returns after the record settled. Each poll run
 * reads the tax report up to 60 times 3 s apart, once per record (poll_attempts 1): a record that
 * B2Brouter has not registered after about three minutes fails the test. Every HTTP call has a
 * 30 s timeout. One run creates four sandbox invoices.
 *
 * Output never contains the API key or the account id; the installation number of a B2Brouter
 * chain is derived from the account id, so it is redacted too.
 */
#[Group('b2brouter-spain-live')]
final class B2BrouterVerifactuLiveTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 'b2brouter-spain-live';

    private const KEY_ENV = 'B2BROUTER_ES_API_KEY';

    private const ACCOUNT_ENV = 'B2BROUTER_ES_ACCOUNT_ID';

    private const TRANSPORT = 'b2brouter-es';

    /** The fields of a correction by difference whose sign B2Brouter writes positive. */
    private const AMOUNT_FIELDS = ['BaseImponibleOimporteNoSujeto', 'CuotaRepercutida', 'CuotaTotal', 'ImporteTotal'];

    private Redactor $redactor;

    /** @var list<string> every line reported, already redacted */
    private array $output = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.http.timeout', 30);
        $app['config']->set('einvoicing.transports.'.self::TRANSPORT, [
            'driver' => 'b2brouter',
            'api_key' => (string) getenv(self::KEY_ENV),
            'account_id' => (string) getenv(self::ACCOUNT_ENV),
            'base_url' => B2BrouterConfig::DEFAULT_BASE_URL,
        ]);
        $app['config']->set('einvoicing.verifactu.environment', 'test');
        $app['config']->set('einvoicing.verifactu.driver', 'b2brouter');
        $app['config']->set('einvoicing.verifactu.issuers', [SpanishInvoices::SELLER_NIF => ['name' => SpanishInvoices::SELLER_NAME]]);
        $app['config']->set('einvoicing.verifactu.b2brouter.transport', self::TRANSPORT);
        $app['config']->set('einvoicing.verifactu.b2brouter.poll_attempts', 1);
        $app['config']->set('einvoicing.verifactu.b2brouter.poll_delay', 0);
        $app['config']->set('einvoicing.verifactu.cross_check', 'strict');
    }

    protected function setUp(): void
    {
        if ((string) getenv(self::KEY_ENV) === '' || (string) getenv(self::ACCOUNT_ENV) === '') {
            self::markTestSkipped(sprintf('Set %s and %s of the B2Brouter Spanish sandbox account.', self::KEY_ENV, self::ACCOUNT_ENV));
        }

        parent::setUp();

        config()->set('einvoicing.verifactu.schemas_dir', VerifactuFiles::schemasDir());
        if (! ChillerlanQrDecoder::isAvailable()) {
            self::fail('The Spanish live test reads the QR codes with chillerlan/php-qrcode and ext-gd or ext-imagick; install them.');
        }

        $config = app(EInvoicingManager::class)->driverConfig(self::TRANSPORT);
        self::assertInstanceOf(B2BrouterConfig::class, $config);
        $refusal = self::refusal($config);
        if ($refusal !== null) {
            self::fail($refusal);
        }
        $secrets = [...$config->secrets(), (string) $config->accountId];
        $this->redactor = new Redactor($secrets);

        // One poll run reads the report up to 60 times, 3 s apart; every call goes through the
        // bridge client with einvoicing.http.timeout and keeps the key and account out of messages.
        $this->app->instance(B2BrouterVerifactu::class, new B2BrouterVerifactu(
            $config,
            app(EInvoicingManager::class)->httpClient(secrets: $secrets),
            new VerifactuPolling(60, 3000),
        ));
    }

    /**
     * Why the configuration must not be used, or null for a "test_" key on the sandbox host. The
     * message names neither the key nor the account.
     */
    public static function refusal(B2BrouterConfig $config): ?string
    {
        if ($config->isSandboxKey() && $config->baseUrl === B2BrouterConfig::DEFAULT_BASE_URL) {
            return null;
        }

        return 'The bridge Spanish live test runs only with a "test_" key on https://api.b2brouter.net (sandbox); it refuses any other key or host, staging and production included.';
    }

    public function test_register_chained_exempt_and_corrective_records_through_b2brouter(): void
    {
        $registered = [];
        Event::listen(VerifactuRecordRegistered::class, static function (VerifactuRecordRegistered $event) use (&$registered): void {
            $registered[] = $event->record->id;
        });
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('ymdHis');

        $this->redacted(function () use ($stamp, &$registered): void {
            $first = $this->registerLive('F1', SpanishInvoices::domestic(), $stamp, 1);
            $second = $this->registerLive('F1, chained', SpanishInvoices::domestic(), $stamp, 2);
            $exempt = $this->registerLive('exempt E6', SpanishInvoices::exempt(ExemptOperation::E6), $stamp, 3);
            $original = new PrecedingInvoiceReference($first->number, new DateTimeImmutable($first->issue_date));
            $credit = $this->registerLive('R1/I credit note', SpanishInvoices::creditNote()->withPrecedingInvoices([$original]), $stamp, 4);

            $rows = [$first, $second, $exempt, $credit];
            $chain = VerifactuChain::query()->sole();
            foreach ($rows as $index => $row) {
                // The first record links to a record B2Brouter registered before this run, which
                // seeds the chain as trusted input: there is no previous report to check it against.
                $this->assertSettled($row, seedsTheChain: $index === 0 && $chain->seeded);
            }

            $this->assertSame([], $this->failures($first), 'F1');
            $this->assertSame([], $this->divergences($first), 'F1');
            $this->assertSame([], $this->failures($second), 'F1, chained');
            $this->assertSame([], $this->divergences($second), 'F1, chained');
            $this->assertSame([], $this->failures($exempt), 'exempt');
            foreach ($this->divergences($exempt) as $field) {
                $this->assertStringEndsWith('OperacionExenta', $field, 'exempt');
            }
            $this->assertSame([], $this->failures($credit), 'R1/I');
            $this->assertNotSame([], $this->divergences($credit), 'R1/I: B2Brouter writes the amounts of a correction by difference positive.');
            foreach ($this->divergences($credit) as $field) {
                $this->assertContains(self::fieldName($field), self::AMOUNT_FIELDS, 'R1/I');
            }

            // One B2Brouter chain; every record links to the one registered before it.
            $this->assertSame(IssuerDriver::B2Brouter, $chain->driver);
            $this->assertNull($chain->throttle_key);
            foreach ($rows as $index => $row) {
                $this->assertSame($chain->id, $row->chain_id);
                if ($index > 0) {
                    $this->assertSame($rows[$index - 1]->sequence + 1, $row->sequence);
                    $this->assertSame($rows[$index - 1]->hash, $row->previous_hash);
                }
            }
            $this->assertSame($credit->hash, $chain->head_hash);
            $this->assertSame($credit->sequence, $chain->sequence);
            $this->report(sprintf(
                'chain %s: sequence %d, %s, head %s.',
                $chain->key()->toString(),
                $chain->sequence,
                $chain->seeded ? 'seeded from the record before this run' : 'not seeded',
                $chain->head_hash,
            ));

            $this->assertSame(array_map(static fn (VerifactuRecord $row): int => $row->id, $rows), $registered, 'VerifactuRecordRegistered fires once per record.');

            $exit = Artisan::call('einvoicing:verifactu-verify-chain');
            $this->report("verify-chain exit {$exit}:\n".trim(Artisan::output()));
            $this->assertSame(0, $exit);
        });

        $output = implode("\n", $this->output);
        // assertFalse, not assertStringNotContainsString: a failure must not print the value.
        $this->assertFalse(str_contains($output, (string) getenv(self::KEY_ENV)), 'The output contains the API key.');
        $this->assertFalse(str_contains($output, (string) getenv(self::ACCOUNT_ENV)), 'The output contains the account id.');
    }

    /**
     * Registers the invoice with the number PROBE-ES-LV-<stamp>-<n>, dated today in Madrid; the
     * synchronous queue polls its tax report before register() returns.
     */
    private function registerLive(string $label, Invoice $invoice, string $stamp, int $n): VerifactuRecord
    {
        $invoice = $invoice
            ->withNumber(sprintf('PROBE-ES-LV-%s-%d', $stamp, $n))
            ->withIssueDate(new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid')));

        $started = microtime(true);
        $row = app(VerifactuManager::class)->register($invoice)->refresh();
        $crossCheck = is_array($row->cross_check) ? (string) ($row->cross_check['summary'] ?? '') : 'none';
        $this->report(sprintf(
            "%s: %s as B2Brouter invoice %s, tax report %s, state %s after %.1f s, sequence %s%s.\n%s",
            $label,
            $row->number,
            $row->b2brouter_invoice_id ?? '-',
            $row->b2brouter_report_id ?? '-',
            $row->state->value,
            microtime(true) - $started,
            $row->sequence ?? '-',
            $row->error_message === null ? '' : '; '.$row->error_message,
            $crossCheck,
        ));

        return $row;
    }

    /**
     * @param  bool  $seedsTheChain  the record seeded the chain, so step 3 had no previous report
     */
    private function assertSettled(VerifactuRecord $row, bool $seedsTheChain): void
    {
        $label = (string) $row->number;
        $this->assertSame(VerifactuRecordState::Registered, $row->state, $label.': '.($row->error_message ?? ''));
        $this->assertNotNull($row->chain_id, $label);
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{64}\z/', (string) $row->hash, $label);
        $this->assertMatchesRegularExpression('#<(?:[A-Za-z0-9]+:)?Huella>'.$row->hash.'</(?:[A-Za-z0-9]+:)?Huella>#', (string) $row->xml, $label);
        $this->assertStringStartsWith('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?', (string) $row->qr_url, $label);
        $this->assertIsArray($row->cross_check, $label);
        $this->assertTrue($row->cross_check['passed'] ?? null, $label);
        $summary = (string) ($row->cross_check['summary'] ?? '');
        foreach (['Schema' => 1, 'Fingerprint' => 2, 'Chain' => 3, 'Qr' => 5] as $step => $number) {
            $status = $step === 'Chain' && $seedsTheChain ? CheckStatus::Skipped : CheckStatus::Passed;
            $this->assertStringContainsString(sprintf('Step %d %s: %s', $number, $step, $status->value), $summary, $label);
        }
        // Step 5 must be decided by the decoded QR, not by the parameter fallback.
        $this->assertStringNotContainsString('QR fallback', $summary, $label);
    }

    /**
     * @return list<string> the fields of the cross-check failures
     */
    private function failures(VerifactuRecord $row): array
    {
        return self::fields($row, 'failures');
    }

    /**
     * @return list<string> the fields of the known divergences
     */
    private function divergences(VerifactuRecord $row): array
    {
        return self::fields($row, 'known_divergences');
    }

    /**
     * @return list<string>
     */
    private static function fields(VerifactuRecord $row, string $key): array
    {
        $entries = is_array($row->cross_check) && is_array($row->cross_check[$key] ?? null) ? $row->cross_check[$key] : [];
        $fields = [];
        foreach ($entries as $entry) {
            $fields[] = is_array($entry) ? (string) ($entry['field'] ?? '') : '';
        }

        return $fields;
    }

    /** The element name of a field, without the breakdown line prefix. */
    private static function fieldName(string $field): string
    {
        $dot = strrpos($field, '.');

        return $dot === false ? $field : substr($field, $dot + 1);
    }

    private function report(string $line): void
    {
        $line = $this->redactor->redact($line);
        $this->output[] = $line;
        fwrite(STDERR, sprintf("\n[%s] %s\n", self::TAG, $line));
    }

    /**
     * Runs the callback and rethrows any failure with its message redacted; a skip passes through.
     */
    private function redacted(callable $callback): void
    {
        try {
            $callback();
        } catch (SkippedTest|IncompleteTest $skip) {
            throw $skip;
        } catch (Throwable $throwable) {
            $message = $this->redactor->redact($throwable::class.': '.$throwable->getMessage());
            $this->output[] = $message;

            throw new AssertionFailedError($message);
        }
    }
}
