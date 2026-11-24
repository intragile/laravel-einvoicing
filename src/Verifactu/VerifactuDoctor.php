<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\AeatConfig;
use IntrAgile\EInvoicing\Transport\Aeat\CaBundle;
use IntrAgile\EInvoicing\Transport\Aeat\ClientCertificate;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The VERI*FACTU rows of einvoicing:doctor.
 *
 * Without issuers, a single info row says that VERI*FACTU is not configured, and nothing else is
 * read: no table, no schema, no chain store. With issuers, the rows check the settings, the
 * installed schemas, the declared computer system, every issuer the resolver enumerates (the
 * configuration keys one by one for the configuration resolver), the AEAT certificates, the CA
 * bundle, the B2Brouter key, and the stored chains, records and throttles of the configured
 * environment.
 *
 * Only a fail row makes the doctor exit non-zero. No AEAT or B2Brouter API is called; with $live,
 * a TLS handshake is made to the AEAT endpoint of each issuer on the AEAT driver, presenting its
 * certificate, and closed without sending a request. Certificate contents, private keys, passwords
 * and API keys are never printed: every configured credential is cut out of every row.
 */
final class VerifactuDoctor
{
    private const OK = 'ok';

    private const INFO = 'info';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    /** Days before the expiry of an AEAT certificate from which the doctor warns. */
    public const EXPIRY_WARNING_DAYS = 30;

    /** Seconds the TLS probe may take to connect and complete the handshake. */
    public const TLS_TIMEOUT = 5;

    /** Seconds after a B2Brouter row's last poll from which no poll of it is taken to be running. */
    private const POLL_SETTLE_MARGIN = 60;

    /** Items a row lists at most. */
    private const LIST_LIMIT = 5;

    /** Characters of a stored message a row quotes at most. */
    private const MESSAGE_LIMIT = 300;

    /** The error codes of a B2Brouter record that AEAT registered but that could not join its chain. */
    private const UNATTACHED_CODES = ['unreadable_record', 'chain_conflict'];

    /** Transport settings that are never printed. */
    private const TRANSPORT_SECRET_KEYS = ['api_key_id', 'secret', 'api_key', 'webhook_secret'];

    /** @var Closure(): Application */
    private readonly Closure $app;

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    /** @var array<string, ClientCertificate> the loaded AEAT certificates by issuer NIF */
    private array $certificates = [];

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * Whether any issuer is configured, so the VERI*FACTU jobs run on the queue.
     */
    public function configured(): bool
    {
        $resolver = $this->manager()->resolver();
        if ($resolver instanceof ConfigIssuerResolver) {
            return $this->issuerKeys() !== [];
        }

        try {
            return $resolver->issuers() !== [];
        } catch (Throwable) {
            // A resolver that cannot enumerate is reported by rows().
            return true;
        }
    }

    /**
     * @return list<array{string, string, string}> [check, ok|info|warn|fail, detail]
     */
    public function rows(bool $live): array
    {
        $this->rows = [];
        $this->certificates = [];

        $issuers = $this->enumerate();
        if ($issuers === []) {
            return [['verifactu', self::INFO, 'not configured: einvoicing.verifactu.issuers is empty']];
        }

        try {
            $settings = $this->manager()->settings();
        } catch (ConfigurationException $exception) {
            $this->row('config', self::FAIL, $exception->getMessage());

            return $this->redacted();
        }
        $this->row('config', self::OK, sprintf(
            '%s environment%s, default driver %s, %d %s',
            $settings->environment->value,
            $settings->environment === VerifactuEnvironment::Production ? ' (records are sent to AEAT for real)' : '',
            $settings->driver->value,
            count($issuers),
            count($issuers) === 1 ? 'issuer' : 'issuers',
        ));

        $this->checkSchemas($settings);
        $systemRow = count($this->rows);
        $contexts = $this->checkIssuers($issuers, $settings);

        $aeat = array_filter($contexts, static fn (IssuerContext $context): bool => $context->driver === IssuerDriver::Aeat);
        $b2brouter = array_filter($contexts, static fn (IssuerContext $context): bool => $context->driver === IssuerDriver::B2Brouter);
        // B2Brouter acts as the billing software of its issuers, so only AEAT issuers need one.
        array_splice($this->rows, $systemRow, 0, [$this->systemRow($settings, required: $b2brouter === [] || $aeat !== [] || count($contexts) < count($issuers))]);
        $bundle = $aeat === [] ? null : $this->checkCaBundle();
        if ($b2brouter !== []) {
            $this->checkB2BrouterKey($settings);
        }

        if ($this->checkTables()) {
            $environment = $settings->environment;
            $this->checkDriverSwitch($environment);
            $this->checkChainHeads($environment);
            $this->checkStuckRecords($environment, $settings->stuckAfterMinutes);
            $this->checkB2BrouterChains($environment, $settings);
            $this->checkCrossChecks($environment);
            $this->checkSubmission();
        }

        if ($live) {
            foreach ($contexts as $context) {
                $this->checkTls($context, $settings, $bundle);
            }
        }

        return $this->redacted();
    }

    /**
     * Makes a TLS handshake to $host:$port presenting the certificate, verifying the server
     * against $bundle (null: the system store), and closes the connection without sending data.
     *
     * The certificate is written to a new private folder (0700) below the bridge's TLS folder for
     * the handshake only; the files and the folder are deleted afterwards.
     *
     * @param  (Closure(string): void)|null  $inspect  called with that folder once the files are
     *                                                 written, just before the handshake
     * @return string|null null when the handshake succeeded, else why it failed
     */
    public function probeTls(ClientCertificate $certificate, string $host, int $port, ?string $bundle, float $timeout = self::TLS_TIMEOUT, ?Closure $inspect = null): ?string
    {
        $directory = ($this->app)()->storagePath(VerifactuClients::TLS_PATH).'/doctor-'.bin2hex(random_bytes(8));
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return sprintf('the folder %s for the client certificate cannot be created', $directory);
        }
        @chmod($directory, 0700);

        try {
            // Held in a variable: the files exist only as long as the instance does.
            $materialized = $certificate->materialize($directory);
            try {
                if ($inspect !== null) {
                    $inspect($directory);
                }
                $context = stream_context_create(['ssl' => [
                    ...$materialized->streamContextOptions($bundle),
                    'peer_name' => $host,
                    'SNI_enabled' => true,
                ]]);
                $warnings = [];
                set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                    $warnings[] = $message;

                    return true;
                });
                try {
                    $socket = stream_socket_client(sprintf('ssl://%s:%d', $host, $port), $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
                } finally {
                    restore_error_handler();
                }
                while (openssl_error_string() !== false) {
                    // Drained, so a later OpenSSL call does not report this handshake.
                }
                if ($socket === false) {
                    $reasons = array_values(array_filter([...$warnings, $errstr], static fn (?string $text): bool => $text !== null && trim($text) !== ''));

                    return $reasons === [] ? 'the handshake failed' : implode('; ', array_unique($reasons));
                }
                fclose($socket);

                return null;
            } finally {
                $materialized->dispose();
            }
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return 'the client certificate cannot be written: '.$exception->getMessage();
        } finally {
            @rmdir($directory);
        }
    }

    /**
     * The issuers to check: the configuration keys, one row per key that is not a valid NIF, for
     * the configuration resolver; else what the bound resolver enumerates.
     *
     * @return list<Nif|string> a valid NIF, or the key or reason of an issuer that failed
     */
    private function enumerate(): array
    {
        $resolver = $this->manager()->resolver();
        if ($resolver instanceof ConfigIssuerResolver) {
            $issuers = [];
            foreach ($this->issuerKeys() as $key) {
                try {
                    $issuers[] = Nif::parse($key);
                } catch (InvalidArgumentException) {
                    $issuers[] = $key;
                }
            }

            return $issuers;
        }

        try {
            return $resolver->issuers();
        } catch (Throwable $exception) {
            $this->row('issuers', self::FAIL, sprintf('the bound %s cannot list its issuers: %s', $resolver::class, $exception->getMessage()));

            return ['?'];
        }
    }

    /**
     * Whether any value of einvoicing.verifactu.system is set.
     */
    private function systemDeclared(): bool
    {
        $filled = static function (mixed $value) use (&$filled): bool {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($filled($item)) {
                        return true;
                    }
                }

                return false;
            }

            return $value !== null && (! is_string($value) || trim($value) !== '');
        };

        return $filled($this->config('einvoicing.verifactu.system'));
    }

    /**
     * @return list<string>
     */
    private function issuerKeys(): array
    {
        $issuers = $this->config('einvoicing.verifactu.issuers');

        return is_array($issuers) ? array_map(strval(...), array_keys($issuers)) : [];
    }

    private function checkSchemas(VerifactuSettings $settings): void
    {
        try {
            $this->manager()->schemas();
            $this->row('schemas', self::OK, sprintf('AEAT %s installed in %s, checksums intact', VerifactuSchemas::DIR, $settings->schemasDir));
        } catch (ConfigurationException $exception) {
            $this->row('schemas', self::FAIL, $exception->getMessage());
        }
    }

    /**
     * The row of the declared computer system; a missing one fails only when it is needed.
     *
     * @param  bool  $required  an issuer submits to AEAT directly, or an issuer did not resolve
     * @return array{string, string, string}
     */
    private function systemRow(VerifactuSettings $settings, bool $required): array
    {
        try {
            $system = $settings->system();
        } catch (ConfigurationException $exception) {
            if ($required) {
                return ['verifactu: system', self::FAIL, $exception->getMessage()];
            }

            // Unused while every issuer uses B2Brouter, but a block that is filled in is meant to
            // be used, so its errors are shown.
            return $this->systemDeclared()
                ? ['verifactu: system', self::WARN, $exception->getMessage().' (not used while every issuer uses the B2Brouter driver)']
                : ['verifactu: system', self::INFO, 'not declared; not needed while every issuer uses the B2Brouter driver, whose records name B2Brouter as the billing software'];
        }

        return ['verifactu: system', self::OK, sprintf(
            '%s %s (id %s) by %s, installation %s',
            $system->systemName,
            $system->version,
            $system->systemId,
            $system->name,
            $system->installationNumber,
        )];
    }

    /**
     * The issuer rows, and the certificate row of each issuer on the AEAT driver.
     *
     * @param  list<Nif|string>  $issuers
     * @return list<IssuerContext> the issuers that resolved
     */
    private function checkIssuers(array $issuers, VerifactuSettings $settings): array
    {
        $resolver = $this->manager()->resolver();
        $contexts = [];
        foreach ($issuers as $issuer) {
            if (is_string($issuer)) {
                if ($issuer !== '?') {
                    $this->row("issuer {$issuer}", self::FAIL, sprintf('the key "%s" of einvoicing.verifactu.issuers is not a valid Spanish NIF', $issuer));
                }

                continue;
            }

            $check = "issuer {$issuer->value}";
            try {
                $context = $resolver->resolve($issuer);
            } catch (Throwable $exception) {
                $this->row($check, self::FAIL, $exception->getMessage());

                continue;
            }
            if ($context->nif->value !== $issuer->value) {
                $this->row($check, self::FAIL, sprintf('the resolver returns issuer %s for it', $context->nif->value));

                continue;
            }
            if ($context->environment !== $settings->environment) {
                $this->row($check, self::FAIL, sprintf(
                    'resolves to the %s environment, but einvoicing.verifactu.environment is %s; its records are refused',
                    $context->environment->value,
                    $settings->environment->value,
                ));

                continue;
            }

            $this->row($check, self::OK, sprintf(
                '%s, driver %s%s',
                $context->name,
                $context->driver->value,
                $context->representative === null ? '' : ', representative '.$context->representative->nif->value,
            ));
            $contexts[] = $context;
            if ($context->driver === IssuerDriver::Aeat) {
                $this->checkCertificate($context);
            }
        }

        return $contexts;
    }

    private function checkCertificate(IssuerContext $context): void
    {
        $check = "certificate {$context->nif->value}";
        if ($context->credentials === null) {
            $this->row($check, self::FAIL, 'no AEAT credentials');

            return;
        }

        try {
            $certificate = ClientCertificate::fromCredentials($context->credentials);
        } catch (InvalidArgumentException $exception) {
            $this->row($check, self::FAIL, $exception->getMessage());

            return;
        }
        $this->certificates[$context->nif->value] = $certificate;

        $now = Carbon::now()->toDateTimeImmutable();
        $until = $certificate->notAfter->format('Y-m-d H:i').' UTC';
        $holder = $certificate->nifHint === null ? 'names no NIF' : 'NIF '.$certificate->nifHint;
        $describe = static fn (string $state): string => sprintf('%s; %s, %s', $state, $certificate->subject, $holder);

        $days = $certificate->daysUntilExpiry($now);
        if ($certificate->notAfter <= $now) {
            $this->row($check, self::FAIL, $describe("expired on {$until}"));
        } elseif ($certificate->notBefore > $now) {
            $this->row($check, self::FAIL, $describe('not valid before '.$certificate->notBefore->format('Y-m-d H:i').' UTC'));
        } elseif ($days < self::EXPIRY_WARNING_DAYS) {
            $this->row($check, self::WARN, $describe(sprintf('expires in %d days, on %s; renew it in time', $days, $until)));
        } elseif ($certificate->nifHint === null) {
            $this->row($check, self::WARN, $describe("valid until {$until}").sprintf('; AEAT accepts it only for issuer %s or its representative', $context->nif->value));
        } elseif ($certificate->nifHint === $context->nif->value) {
            $this->row($check, self::OK, $describe("valid until {$until}"));
        } elseif ($certificate->nifHint === $context->representative?->nif->value) {
            $this->row($check, self::OK, $describe("valid until {$until}").' (the representative)');
        } else {
            $this->row($check, self::WARN, sprintf(
                'valid until %s; %s names NIF %s, not the issuer %s%s',
                $until,
                $certificate->subject,
                $certificate->nifHint,
                $context->nif->value,
                $context->representative === null ? '' : ' or its representative '.$context->representative->nif->value,
            ));
        }
    }

    /**
     * @return string|null the bundle the AEAT clients verify against, null for the system store
     */
    private function checkCaBundle(): ?string
    {
        $configured = $this->config('einvoicing.verifactu.aeat.ca_bundle');
        $bundle = is_string($configured) && $configured !== '' ? $configured : CaBundle::defaultPath();
        if ($bundle === null) {
            $this->row('ca bundle', self::WARN, 'no CA bundle found; the system store is used. AEAT chains to '.CaBundle::FNMT_ROOT_CN
                .', which some system stores lack; set einvoicing.verifactu.aeat.ca_bundle to a bundle that holds it');

            return null;
        }

        try {
            $found = CaBundle::containsFnmtRoot($bundle);
        } catch (InvalidArgumentException $exception) {
            $this->row('ca bundle', self::FAIL, $exception->getMessage());

            return $bundle;
        }

        $found
            ? $this->row('ca bundle', self::OK, sprintf('%s holds %s', $bundle, CaBundle::FNMT_ROOT_CN))
            : $this->row('ca bundle', self::WARN, sprintf(
                '%s does not hold %s, the root of the AEAT server certificates, so the TLS handshake with AEAT fails; '
                .'set einvoicing.verifactu.aeat.ca_bundle to a bundle that holds it (the Mozilla bundle does)',
                $bundle,
                CaBundle::FNMT_ROOT_CN,
            ));

        return $bundle;
    }

    /**
     * B2Brouter keys starting with "test_" reach its sandbox; the others are live keys.
     */
    private function checkB2BrouterKey(VerifactuSettings $settings): void
    {
        $name = $settings->b2brouterTransport;
        if ($name === null) {
            ($this->app)()->bound(B2BrouterVerifactu::class)
                ? $this->row('b2brouter key', self::INFO, 'the B2Brouter client is bound in the container; its key is not checked')
                : $this->row('b2brouter key', self::FAIL, 'an issuer uses the B2Brouter driver; set einvoicing.verifactu.b2brouter.transport to a B2Brouter transport of einvoicing.transports');

            return;
        }

        $transports = $this->config('einvoicing.transports');
        $transport = is_array($transports) && is_array($transports[$name] ?? null) ? $transports[$name] : null;
        if ($transport === null || ($transport['driver'] ?? null) !== 'b2brouter') {
            $this->row('b2brouter key', self::FAIL, sprintf('einvoicing.verifactu.b2brouter.transport names "%s", which is not a B2Brouter transport of einvoicing.transports', $name));

            return;
        }

        $key = $transport['api_key'] ?? null;
        $setting = "einvoicing.transports.{$name}.api_key";
        if (! is_string($key) || trim($key) === '') {
            $this->row('b2brouter key', self::FAIL, "{$setting} is not set");

            return;
        }

        $sandbox = str_starts_with($key, 'test_');
        $production = $settings->environment === VerifactuEnvironment::Production;
        if ($sandbox === $production) {
            $this->row('b2brouter key', self::FAIL, $production
                ? "{$setting} is a sandbox key (test_), but einvoicing.verifactu.environment is production; use a live key"
                : "{$setting} is a live key, but einvoicing.verifactu.environment is test; use a sandbox key (test_), or set the environment to production");

            return;
        }

        $this->row('b2brouter key', self::OK, $production
            ? 'a live key for the production environment'
            : 'a sandbox key (test_) for the test environment');
    }

    /**
     * Whether the three VERI*FACTU tables exist; the rows that read them need them.
     */
    private function checkTables(): bool
    {
        $tables = [VerifactuChain::TABLE, VerifactuRecord::TABLE, VerifactuThrottle::TABLE];
        try {
            $missing = array_values(array_filter($tables, static fn (string $table): bool => ! Schema::hasTable($table)));
        } catch (Throwable $exception) {
            $this->row('tables', self::FAIL, $exception::class.': '.$exception->getMessage());

            return false;
        }

        if ($missing !== []) {
            $this->row('tables', self::FAIL, 'missing: '.implode(', ', $missing).'; run php artisan migrate');

            return false;
        }

        $this->row('tables', self::OK, implode(', ', $tables));

        return true;
    }

    /**
     * An issuer must not have open chains of both drivers: two SIFs chaining for one issuer look
     * like tampering. A chain row without records or seed is no chain.
     */
    private function checkDriverSwitch(VerifactuEnvironment $environment): void
    {
        $chains = $this->usedChains($environment)->whereNull('closed_at')->get();
        $switched = [];
        foreach ($chains->groupBy('issuer_nif') as $issuer => $open) {
            $drivers = $open->map(static fn (VerifactuChain $chain): string => $chain->driver->value)->unique()->sort()->values();
            if ($drivers->count() > 1) {
                $switched[] = sprintf(
                    '%s (%s)',
                    $issuer,
                    $open->map(static fn (VerifactuChain $chain): string => $chain->driver->value.' installation '.$chain->installation_number)->implode(', '),
                );
            }
        }

        $switched === []
            ? $this->row('driver switch', self::OK, 'no issuer has open chains of both drivers')
            : $this->row('driver switch', self::FAIL, sprintf(
                'open chains of both drivers for %s; finish the cut-over with php artisan einvoicing:verifactu-close-chain --issuer=<nif>',
                implode('; ', $switched),
            ));
    }

    /**
     * Every head must be the hash of the chain's last record; a seeded head is trusted input.
     */
    private function checkChainHeads(VerifactuEnvironment $environment): void
    {
        $mismatched = [];
        $seeded = [];
        $checked = 0;
        foreach ($this->usedChains($environment)->orderBy('id')->get() as $chain) {
            if ($chain->seeded) {
                $seeded[] = sprintf('%s from %s of %s', self::chainName($chain), self::headNumber($chain), (string) $chain->head_issue_date);
            }
            if ($chain->sequence < 1) {
                continue;
            }
            $checked++;
            $last = VerifactuRecord::query()->where('chain_id', $chain->id)->where('sequence', $chain->sequence)->first(['id', 'number', 'hash']);
            if ($last === null) {
                $mismatched[] = sprintf('%s: no record at sequence %d', self::chainName($chain), $chain->sequence);
            } elseif ($last->hash !== $chain->head_hash || $last->number !== $chain->head_number) {
                $mismatched[] = sprintf('%s: the head is not record row %d (%s)', self::chainName($chain), $last->id, $last->number);
            }
        }

        $mismatched === []
            ? $this->row('chain heads', self::OK, sprintf('%d %s, every head is the hash of its last record', $checked, $checked === 1 ? 'chain' : 'chains'))
            : $this->row('chain heads', self::FAIL, sprintf(
                '%s; nothing can be appended to such a chain; run php artisan einvoicing:verifactu-verify-chain',
                self::listed($mismatched),
            ));
        if ($seeded !== []) {
            $this->row('seeded chains', self::INFO, sprintf('continued from the previous software: %s', self::listed($seeded)));
        }
    }

    /**
     * Records not settled after stuck_after_minutes, measured from their last change, by state.
     */
    private function checkStuckRecords(VerifactuEnvironment $environment, int $minutes): void
    {
        $counts = VerifactuRecord::query()
            ->where('environment', $environment->value)
            ->whereIn('state', [
                VerifactuRecordState::Generated->value,
                VerifactuRecordState::Sending->value,
                VerifactuRecordState::Unconfirmed->value,
                VerifactuRecordState::Pending->value,
            ])
            ->where('updated_at', '<', Carbon::now()->subMinutes($minutes))
            ->toBase()
            ->selectRaw('state, count(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $remedies = [
            VerifactuRecordState::Generated->value => 'not submitted; check that a queue worker runs, then run php artisan einvoicing:verifactu-submit',
            VerifactuRecordState::Sending->value => 'claimed by a submission that never finished (a worker stopped mid-request); php artisan einvoicing:verifactu-submit --retry-unconfirmed resends the same bytes',
            VerifactuRecordState::Unconfirmed->value => 'outcome unknown; run php artisan einvoicing:verifactu-submit --retry-unconfirmed, or einvoicing:verifactu-status --lookup for a duplicate',
            VerifactuRecordState::Pending->value => 'B2Brouter registration not known; php artisan einvoicing:verifactu-submit --retry-unconfirmed polls them again',
        ];
        $parts = [];
        foreach ($remedies as $state => $remedy) {
            $value = $counts[$state] ?? 0;
            $count = is_numeric($value) ? (int) $value : 0;
            if ($count > 0) {
                $parts[] = "{$state} {$count} ({$remedy})";
            }
        }

        $parts === []
            ? $this->row('stuck records', self::OK, sprintf('none unsettled for over %d minutes', $minutes))
            : $this->row('stuck records', self::WARN, sprintf('unsettled for over %d minutes: %s', $minutes, implode('; ', $parts)));
    }

    /**
     * B2Brouter chains: a record AEAT registered that could not join its chain blocks the records
     * after it until an operator acts; a predecessor still pending after its polls holds back the
     * records waiting for it.
     */
    private function checkB2BrouterChains(VerifactuEnvironment $environment, VerifactuSettings $settings): void
    {
        $b2brouter = static fn () => VerifactuRecord::query()
            ->where('environment', $environment->value)
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->whereNull('chain_id');

        $blocked = [];
        foreach ($b2brouter()->where('state', VerifactuRecordState::Failed->value)->whereIn('error_code', self::UNATTACHED_CODES)->orderBy('id')->get(['id', 'issuer_nif', 'number', 'issue_date', 'error_code', 'updated_at']) as $row) {
            // A chain of the issuer that changed since (moved on, re-seeded or closed) is no longer blocked by it.
            $movedOn = VerifactuChain::query()
                ->where('issuer_nif', $row->issuer_nif)
                ->where('environment', $environment->value)
                ->where('driver', IssuerDriver::B2Brouter->value)
                ->where('updated_at', '>', $row->updated_at)
                ->exists();
            if (! $movedOn) {
                $blocked[] = sprintf('issuer %s: record row %d (%s of %s, %s)', $row->issuer_nif, $row->id, $row->number, substr($row->issue_date, 0, 10), (string) $row->error_code);
            }
        }
        if ($blocked !== []) {
            $this->row('b2brouter chains', self::FAIL, sprintf(
                'blocked by a record B2Brouter registered that cannot join the chain, so the records after it cannot either: %s; see its error_message and settle it with B2Brouter',
                self::listed($blocked),
            ));

            return;
        }

        // One poll run lasts up to the job timeout and the next starts poll_delay seconds later.
        $quiet = $settings->pollDelay + (int) (new PollVerifactuTaxReport(0))->timeout + self::POLL_SETTLE_MARGIN;
        $stalled = [];
        $predecessors = $b2brouter()
            ->where('state', VerifactuRecordState::Pending->value)
            ->where(static fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', PollVerifactuTaxReport::AWAITING_PREDECESSOR))
            ->where('updated_at', '<', Carbon::now()->subSeconds($quiet))
            ->orderBy('id')
            ->get(['id', 'issuer_nif', 'number']);
        foreach ($predecessors->groupBy('issuer_nif') as $issuer => $rows) {
            $waiting = $b2brouter()
                ->where('issuer_nif', $issuer)
                ->where('state', VerifactuRecordState::Pending->value)
                ->where('error_code', PollVerifactuTaxReport::AWAITING_PREDECESSOR)
                ->count();
            if ($waiting > 0) {
                $stalled[] = sprintf(
                    'issuer %s: %s, and %d %s for a predecessor',
                    $issuer,
                    $rows->map(static fn (VerifactuRecord $row): string => sprintf('record row %d (%s) is still pending after its polls', $row->id, $row->number))->implode(', '),
                    $waiting,
                    $waiting === 1 ? 'record waits' : 'records wait',
                );
            }
        }

        $stalled === []
            ? $this->row('b2brouter chains', self::OK, 'no B2Brouter record is blocked or held back')
            : $this->row('b2brouter chains', self::WARN, sprintf(
                '%s; run php artisan einvoicing:verifactu-submit --retry-unconfirmed to poll them again',
                implode('; ', $stalled),
            ));
    }

    /**
     * Attached B2Brouter records whose cross-check against our own engine failed: the chain is
     * intact, but B2Brouter's record differs from the invoice.
     */
    private function checkCrossChecks(VerifactuEnvironment $environment): void
    {
        // A JSON path comparison with a boolean: json_extract on SQLite and MySQL, jsonb on PostgreSQL.
        $failed = VerifactuRecord::query()
            ->where('environment', $environment->value)
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->whereNotNull('chain_id')
            ->where('cross_check->passed', false);
        $total = (clone $failed)->count();
        if ($total === 0) {
            return;
        }

        $listed = $failed->orderBy('id')->limit(self::LIST_LIMIT)->get(['id', 'number']);
        $more = $total - $listed->count();
        $this->row('cross-check', self::WARN, sprintf(
            '%d B2Brouter %s failed the cross-check against the invoice (%s%s); see the cross_check column',
            $total,
            $total === 1 ? 'record' : 'records',
            $listed->map(static fn (VerifactuRecord $row): string => sprintf('row %d, %s', $row->id, $row->number))->implode(', '),
            $more > 0 ? sprintf(' and %d more', $more) : '',
        ));
    }

    private function checkSubmission(): void
    {
        $blocked = VerifactuThrottle::query()->whereNotNull('blocked_at')->orderBy('id')->get();
        if ($blocked->isEmpty()) {
            $this->row('submission', self::OK, 'no submission is blocked');

            return;
        }

        $this->row('submission', self::FAIL, sprintf(
            'blocked after AEAT refused a submission: %s; fix the cause, then run php artisan einvoicing:verifactu-submit, which clears the block',
            self::listed(array_values($blocked->map(static fn (VerifactuThrottle $throttle): string => sprintf(
                '%s since %s UTC, code %s: %s',
                $throttle->key,
                $throttle->blocked_at?->copy()->utc()->format('Y-m-d H:i') ?? '?',
                $throttle->blocked_code ?? 'none',
                self::cut((string) $throttle->blocked_message),
            ))->all())),
        ));
    }

    private function checkTls(IssuerContext $context, VerifactuSettings $settings, ?string $bundle): void
    {
        if ($context->driver === IssuerDriver::B2Brouter) {
            $this->row("tls {$context->nif->value}", self::INFO, 'not probed: the issuer submits through B2Brouter');

            return;
        }

        try {
            $endpoint = AeatConfig::forIssuer($context, new VerifactuSchemas($settings->schemasDir))->endpoint();
        } catch (InvalidArgumentException $exception) {
            $this->row("tls {$context->nif->value}", self::FAIL, $exception->getMessage());

            return;
        }
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        $port = is_int($port) ? $port : 443;
        $check = "tls {$host}";
        $certificate = $this->certificates[$context->nif->value] ?? null;
        if ($certificate === null) {
            $this->row($check, self::FAIL, sprintf('issuer %s: not probed, its certificate cannot be loaded', $context->nif->value));

            return;
        }

        $failure = $this->probeTls($certificate, $host, $port, $bundle);
        $failure === null
            ? $this->row($check, self::OK, sprintf('issuer %s: handshake completed with its certificate (no request sent)', $context->nif->value))
            : $this->row($check, self::FAIL, sprintf('issuer %s: %s', $context->nif->value, $failure));
    }

    /**
     * The chains of the environment that have a record or a seed; a chain row with neither is no
     * chain.
     *
     * @return Builder<VerifactuChain>
     */
    private function usedChains(VerifactuEnvironment $environment): Builder
    {
        return VerifactuChain::query()
            ->where('environment', $environment->value)
            ->where(static fn ($query) => $query->where('sequence', '>', 0)->orWhereNotNull('head_hash'));
    }

    private function manager(): VerifactuManager
    {
        return ($this->app)()->make(VerifactuManager::class);
    }

    private function config(string $key): mixed
    {
        return ($this->app)()->make('config')->get($key);
    }

    private function row(string $check, string $status, string $detail): void
    {
        $this->rows[] = ["verifactu: {$check}", $status, $detail];
    }

    /**
     * The rows with every configured credential cut out.
     *
     * @return list<array{string, string, string}>
     */
    private function redacted(): array
    {
        $secrets = $this->secrets();
        if ($secrets === []) {
            return $this->rows;
        }

        return array_map(
            static fn (array $row): array => [str_replace($secrets, '[redacted]', $row[0]), $row[1], str_replace($secrets, '[redacted]', $row[2])],
            $this->rows,
        );
    }

    /**
     * The AEAT passwords and the transport credentials, longest first so a value goes before its
     * parts.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $secrets = [$this->config('einvoicing.verifactu.aeat.password')];
        $issuers = $this->config('einvoicing.verifactu.issuers');
        foreach (is_array($issuers) ? $issuers : [] as $issuer) {
            $secrets[] = is_array($issuer) && is_array($issuer['aeat'] ?? null) ? ($issuer['aeat']['password'] ?? null) : null;
        }
        $transports = $this->config('einvoicing.transports');
        foreach (is_array($transports) ? $transports : [] as $transport) {
            foreach (self::TRANSPORT_SECRET_KEYS as $key) {
                $secrets[] = is_array($transport) ? ($transport[$key] ?? null) : null;
            }
        }

        $secrets = array_values(array_unique(array_filter($secrets, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    private static function chainName(VerifactuChain $chain): string
    {
        return sprintf('%s|%s|%s (%s)', $chain->issuer_nif, $chain->installation_number, $chain->environment->value, $chain->driver->value);
    }

    private static function headNumber(VerifactuChain $chain): string
    {
        return $chain->head_number ?? '?';
    }

    /**
     * @param  list<string>  $items
     */
    private static function listed(array $items): string
    {
        $shown = array_slice($items, 0, self::LIST_LIMIT);
        $more = count($items) - count($shown);

        return implode(', ', $shown).($more > 0 ? sprintf(' and %d more', $more) : '');
    }

    private static function cut(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::MESSAGE_LIMIT ? mb_substr($text, 0, self::MESSAGE_LIMIT).'…' : $text;
    }
}
