<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Jobs\MessageJob;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeConfig;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandConfig;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\Validator;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * Checks the configuration, the Peppol rules, the tables and the queue, and with --live asks each
 * transport's inbox once whether the credentials work.
 *
 * Configuration problems are named by their key path only, and the base URL, which may carry
 * credentials, is never printed. Exception messages are printed with every configured credential
 * cut out.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'einvoicing:doctor
        {--live : Call each transport\'s inbox once to check the credentials}';

    protected $description = 'Check the e-invoicing transports, validation rules, tables and queue';

    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    /** What the config row of each built-in driver confirms. */
    private const CONFIG_SUMMARIES = [
        'recommand' => 'API key id, secret and company id set',
        'b2brouter' => 'API key and account id set',
        'einvoicebe' => 'API key set',
    ];

    /** The base URL each built-in driver uses when none is configured. */
    private const DEFAULT_BASE_URLS = [
        'recommand' => RecommandConfig::DEFAULT_BASE_URL,
        'b2brouter' => B2BrouterConfig::DEFAULT_BASE_URL,
        'einvoicebe' => EInvoiceBeConfig::DEFAULT_BASE_URL,
    ];

    /**
     * Stand-in credentials for checking a base URL on its own; the configured ones are checked
     * separately and never passed along.
     */
    private const PLACEHOLDER_KEY_ID = 'placeholder-key-id';

    private const PLACEHOLDER_SECRET = 'placeholder-secret';

    /**
     * Rule packages this package requires, with what each one is needed for. Factur-X rules are
     * not used when sending, so their absence is only a warning.
     */
    private const RULE_PACKAGES = [
        'intragile/einvoicing-rules-en16931' => [self::FAIL, 'XRechnung documents cannot be validated'],
        'intragile/einvoicing-rules-xrechnung' => [self::FAIL, 'XRechnung documents cannot be validated'],
        'intragile/einvoicing-rules-facturx' => [self::WARN, 'EInvoicing::facturx() is not available'],
    ];

    /** Settings that are never printed. */
    private const SECRET_KEYS = ['api_key_id', 'secret', 'api_key', 'webhook_secret'];

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    /** @var list<string> configured credentials, cut out of every exception message */
    private array $secrets = [];

    public function handle(EInvoicingManager $manager): int
    {
        $this->rows = [];
        $this->secrets = $this->secrets();

        $this->checkDefault($manager);
        $this->checkFlags();
        foreach ($manager->names() as $name) {
            $this->checkTransport($manager, $name);
        }
        $this->checkValidation($manager);
        $this->checkCore();
        $this->checkRulePackages();
        $this->checkTables();
        $this->checkQueue();

        if ((bool) $this->option('live')) {
            foreach ($manager->names() as $name) {
                $this->checkInbox($manager, $name);
            }
        }

        $this->table(['Check', 'Status', 'Detail'], $this->rows);

        foreach ($this->rows as $row) {
            if ($row[1] === self::FAIL) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function checkDefault(EInvoicingManager $manager): void
    {
        try {
            $default = $manager->defaultName();
        } catch (Throwable $exception) {
            $this->row('default transport', self::FAIL, $this->describe($exception, withClass: false));

            return;
        }

        in_array($default, $manager->names(), true)
            ? $this->row('default transport', self::OK, $default)
            : $this->row('default transport', self::FAIL, sprintf(
                'einvoicing.default names "%s", but einvoicing.transports.%s is not configured',
                $default,
                $default,
            ));
    }

    private function checkTransport(EInvoicingManager $manager, string $name): void
    {
        $check = "transport {$name}";
        $config = $this->transportConfig($name);
        if ($config === null) {
            $this->row("{$check}: config", self::FAIL, "einvoicing.transports.{$name} must be an array");

            return;
        }

        $driver = $config['driver'] ?? null;
        if (! is_string($driver) || ! $manager->hasDriver($driver)) {
            $this->row("{$check}: config", self::FAIL, sprintf(
                'einvoicing.transports.%s.driver names no known driver; use "%s" or register one with extend()',
                $name,
                implode('", "', EInvoicingManager::BUILT_IN_DRIVERS),
            ));

            return;
        }
        if (! in_array($driver, EInvoicingManager::BUILT_IN_DRIVERS, true)) {
            $this->row("{$check}: config", self::OK, sprintf('custom driver "%s"; its settings are checked by its factory', $driver));

            return;
        }

        $failure = $this->checkDriverConfig($manager, $name, $driver, $config);
        // A config row that failed on the base URL already reports it; a second row would repeat it.
        if ($failure === null || ! str_contains($failure, "einvoicing.transports.{$name}.base_url")) {
            $this->checkBaseUrl($name, $driver, $config['base_url'] ?? null);
        }
        // A required account id that is invalid already fails the config row.
        if ($failure === null || ! in_array(EInvoicingManager::ACCOUNT_KEYS[$driver], EInvoicingManager::REQUIRED_KEYS[$driver], true)) {
            $this->checkAccount($manager, $name, $driver);
        }
        $this->checkWebhook($manager, $name, $config);
        if ($driver === 'einvoicebe') {
            $this->row("{$check}: acknowledge", self::OK, 'local only: e-invoice.be has no acknowledgement call, so a received '
                .'document stays listed for inbound_lookback_days; it is stored and fetched once');
        }
    }

    /**
     * The config row of a built-in driver: every missing setting by name, else the first invalid
     * one as the manager reports it when building the transport. The values are never printed.
     *
     * @param  array<array-key, mixed>  $config
     * @return string|null the detail of the failed config row, or null when the row is ok
     */
    private function checkDriverConfig(EInvoicingManager $manager, string $name, string $driver, #[SensitiveParameter] array $config): ?string
    {
        $check = "transport {$name}";
        $missing = array_values(array_filter(
            EInvoicingManager::REQUIRED_KEYS[$driver] ?? [],
            static fn (string $key): bool => ! self::present($config[$key] ?? null),
        ));
        if ($missing !== []) {
            $detail = 'missing or empty: '.self::keyPaths($name, $missing);
            $this->row("{$check}: config", self::FAIL, $detail);

            return $detail;
        }

        try {
            $driverConfig = $manager->driverConfig($name);
        } catch (ConfigurationException $exception) {
            $detail = $this->describe($exception, withClass: false);
            $this->row("{$check}: config", self::FAIL, $detail);

            return $detail;
        }
        $this->row("{$check}: config", self::OK, self::CONFIG_SUMMARIES[$driver] ?? 'set');

        if ($driverConfig instanceof B2BrouterConfig && $driverConfig->isSandboxKey()) {
            $this->row("{$check}: sandbox", self::WARN, 'the API key starts with "test_", so B2Brouter routes every request to '
                .'its sandbox: nothing reaches the Peppol network');
        }

        return null;
    }

    /**
     * Warns when the account id that webhook events are matched against is given but invalid,
     * which makes the webhook ignore every event naming an account. The value is never printed.
     */
    private function checkAccount(EInvoicingManager $manager, string $name, string $driver): void
    {
        try {
            $manager->accountId($name);
        } catch (ConfigurationException) {
            $this->row("transport {$name}: account", self::WARN, sprintf(
                'einvoicing.transports.%s.%s is not a valid account id; webhook events are ignored',
                $name,
                EInvoicingManager::ACCOUNT_KEYS[$driver] ?? 'account_id',
            ));
        }
    }

    private function checkBaseUrl(string $name, string $driver, #[SensitiveParameter] mixed $baseUrl): void
    {
        $check = "transport {$name}: base URL";
        if ($baseUrl === null || $baseUrl === '') {
            $this->row($check, self::OK, 'default '.(self::DEFAULT_BASE_URLS[$driver] ?? ''));

            return;
        }

        // The URL itself is never printed: it may carry credentials as user info. It is checked by
        // the driver's own configuration, with stand-in credentials.
        try {
            if (! is_string($baseUrl)) {
                throw new InvalidArgumentException('The base URL is not a string.');
            }
            match ($driver) {
                'b2brouter' => new B2BrouterConfig(self::PLACEHOLDER_SECRET, 1, $baseUrl),
                'einvoicebe' => new EInvoiceBeConfig(self::PLACEHOLDER_SECRET, $baseUrl),
                default => new RecommandConfig(self::PLACEHOLDER_KEY_ID, self::PLACEHOLDER_SECRET, 'placeholder-company', $baseUrl),
            };
            $this->row($check, self::OK, 'https');
        } catch (InvalidArgumentException) {
            $this->row($check, self::FAIL, sprintf(
                'einvoicing.transports.%s.base_url must be an https:// URL without user info, query or fragment',
                $name,
            ));
        }
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function checkWebhook(EInvoicingManager $manager, string $name, #[SensitiveParameter] array $config): void
    {
        $check = "transport {$name}: webhook";
        if (! self::filled($config['webhook_secret'] ?? null)) {
            $this->row($check, self::WARN, 'webhooks off; schedule einvoicing:poll-inbound');

            return;
        }
        // The parser checks the webhook settings; building it makes no request.
        try {
            $manager->webhook($name);
        } catch (ConfigurationException $exception) {
            $this->row($check, self::FAIL, $this->describe($exception, withClass: false));

            return;
        }
        // A missing account id already fails the config row of a driver that requires one.
        if (! Route::has('einvoicing.webhook')) {
            $this->row($check, self::WARN, 'einvoicing.webhooks.enabled is off, so no webhook route exists; schedule einvoicing:poll-inbound');

            return;
        }

        $this->row($check, self::OK, route('einvoicing.webhook', ['transport' => $name]));
    }

    /**
     * Warns about on/off settings that cannot be read as a flag; each falls back to its safe value.
     */
    private function checkFlags(): void
    {
        foreach (ConfigFlag::SETTINGS as $key => [$default, $unparseable]) {
            if (ConfigFlag::read(config("einvoicing.{$key}"), $default) === null) {
                $this->row('config flags', self::WARN, sprintf('einvoicing.%s is not a boolean; treated as %s', $key, $unparseable ? 'on' : 'off'));
            }
        }
    }

    private function checkValidation(EInvoicingManager $manager): void
    {
        if (! ConfigFlag::enabled('validation.enabled')) {
            $this->row('validation', self::WARN, 'off: documents are sent without rule checks; only documents that are not a sendable Peppol BIS or XRechnung type are refused');

            return;
        }

        if (app()->bound(DocumentValidator::class)) {
            $this->row('validation', self::OK, 'documents are checked by the '.DocumentValidator::class.' bound in the container');

            return;
        }

        $dir = $manager->rulesDir();
        try {
            Validator::peppol($dir);
            $this->row('validation', self::OK, "Peppol BIS rules loaded from {$dir}");
        } catch (RulesNotInstalled) {
            $this->row('validation', self::FAIL, "no Peppol rules in {$dir}; run php artisan einvoicing:install-rules");
        } catch (Throwable $exception) {
            $this->row('validation', self::FAIL, $this->describe($exception).'; run php artisan einvoicing:install-rules');
        }
    }

    private function checkCore(): void
    {
        $this->row('core php-einvoicing', self::OK, (string) InstalledVersions::getPrettyVersion('intragile/php-einvoicing'));
    }

    private function checkRulePackages(): void
    {
        foreach (self::RULE_PACKAGES as $package => [$status, $effect]) {
            $check = 'rules '.substr($package, strlen('intragile/'));
            InstalledVersions::isInstalled($package)
                ? $this->row($check, self::OK, (string) InstalledVersions::getPrettyVersion($package))
                : $this->row($check, $status, "missing: composer require {$package}; {$effect}");
        }
    }

    private function checkTables(): void
    {
        $missing = [];
        try {
            foreach ([new OutboundMessage, new InboundMessage] as $model) {
                if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                    $missing[] = $model->getTable();
                }
            }
        } catch (Throwable $exception) {
            $this->row('tables', self::FAIL, $this->describe($exception));

            return;
        }

        if ($missing !== []) {
            $this->row('tables', self::FAIL, 'missing: '.implode(', ', $missing).'; run php artisan migrate');

            return;
        }

        $this->row('tables', self::OK, 'einvoicing_outbound_documents, einvoicing_inbound_documents');
        $this->checkCapped();
    }

    /**
     * Counts the inbound documents that have used up their fetch attempts and are no longer
     * tried automatically.
     */
    private function checkCapped(): void
    {
        try {
            $cap = FetchInboundDocument::attemptCap();
            $states = [InboundState::Notified->value];
            if (ConfigFlag::enabled('inbound.acknowledge')) {
                $states[] = InboundState::Fetched->value;
            }
            $capped = InboundMessage::query()->whereIn('state', $states)->where('fetch_attempts', '>=', $cap)->count();
        } catch (ConfigurationException $exception) {
            $this->row('inbound capped', self::FAIL, $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->row('inbound capped', self::FAIL, $this->describe($exception));

            return;
        }

        $capped === 0
            ? $this->row('inbound capped', self::OK, "none at the cap of {$cap} attempts")
            : $this->row('inbound capped', self::WARN, sprintf(
                '%d inbound documents have used up einvoicing.inbound.max_fetch_attempts (%d) and are no longer fetched '
                .'or acknowledged automatically; see last_error, then run php artisan einvoicing:poll-inbound --include-capped',
                $capped,
                $cap,
            ));
    }

    private function checkQueue(): void
    {
        try {
            MessageJob::assertSettings();
        } catch (ConfigurationException $exception) {
            // The message names the setting, never its value. The queue checks derive timeouts
            // from these settings, so they are skipped.
            $this->row('job settings', self::FAIL, $exception->getMessage());

            return;
        }
        $this->row('job settings', self::OK, 'http timeouts, attempts and the inbound cap are numbers');

        $this->checkQueueConnection();
    }

    private function checkQueueConnection(): void
    {
        $connection = MessageJob::connectionName();
        $driver = MessageJob::connectionDriver();
        if ($connection === null || $driver === null) {
            $this->row('queue', self::FAIL, 'the queue connection is not configured in queue.connections');

            return;
        }

        if (MessageJob::runsSynchronously()) {
            $this->row('queue', self::WARN, sprintf(
                '%s (driver %s) runs jobs in the calling process: no retries of a failed send, no delivery status polling, '
                .'no expiry of a stuck sending claim; einvoicing:resolve takes a row sending for over %d s as abandoned, '
                .'which holds only if that process was killed, as a queue worker with pcntl does at its timeout',
                $connection,
                $driver,
                SendDocument::claimLifetime(),
            ));

            return;
        }

        // Only the PHP running this command can be inspected; workers may run another binary.
        extension_loaded('pcntl')
            ? $this->row('queue', self::OK, sprintf(
                '%s (driver %s); pcntl is loaded in the PHP running this command, make sure the workers\' PHP has it too',
                $connection,
                $driver,
            ))
            : $this->row('queue', self::WARN, sprintf(
                '%s (driver %s), but pcntl is not loaded in the PHP running this command: if the workers\' PHP lacks it too, '
                .'they cannot stop a job at its timeout, so a row einvoicing:resolve takes as abandoned may still be sending',
                $connection,
                $driver,
            ));

        $this->checkRetryAfter($connection);
        if ($driver === 'sqs') {
            $this->checkSqs($connection);
        }
    }

    /**
     * Amazon SQS refuses delays over 900 seconds, so longer ones are capped and the job runs
     * earlier; on a FIFO queue it does not allow per-message delays at all, so retries, status
     * polls and the expiry of a sending claim would not be spaced out.
     */
    private function checkSqs(string $connection): void
    {
        $queue = config('einvoicing.queue.name');
        if (! is_string($queue) || $queue === '') {
            $queue = config("queue.connections.{$connection}.queue");
        }
        if (is_string($queue) && self::isFifoQueue($queue, config("queue.connections.{$connection}.suffix"))) {
            $this->row('queue fifo', self::FAIL, sprintf(
                'the SQS queue %s is a FIFO queue; Amazon SQS does not allow per-message delays on FIFO queues '
                .'(DelaySeconds can only be set on the queue), so retries, status polls and the expiry of a sending claim '
                .'would not be spaced out; use a standard queue',
                $queue,
            ));
        }

        $delays = [
            'einvoicing.sending.backoff (send and inbound retries)' => SendDocument::longestBackoff(),
            'sending claim expiry (from einvoicing.http timeouts)' => SendDocument::claimLifetime(),
        ];
        if (ConfigFlag::enabled('status.poll')) {
            $delays['einvoicing.status.delays'] = PollDeliveryStatus::longestDelay();
        }
        $over = array_filter($delays, static fn (int $delay): bool => $delay > MessageJob::SQS_MAX_DELAY);

        $over === []
            ? $this->row('queue delays', self::OK, sprintf('all within the SQS maximum of %d s', MessageJob::SQS_MAX_DELAY))
            : $this->row('queue delays', self::WARN, sprintf(
                'SQS accepts delays up to %d s; longer ones are capped at %d s, so those jobs run earlier: %s',
                MessageJob::SQS_MAX_DELAY,
                MessageJob::SQS_MAX_DELAY,
                implode(', ', array_map(
                    static fn (string $setting, int $delay): string => "{$setting} up to {$delay} s",
                    array_keys($over),
                    $over,
                )),
            ));
    }

    /**
     * Whether the SQS queue Laravel addresses for this name is a FIFO queue. A full queue URL is
     * used as is; a plain name gets the connection's suffix appended, as Laravel's SQS queue does,
     * and the prefix only adds the account path in front, so the name decides.
     */
    private static function isFifoQueue(string $queue, mixed $suffix): bool
    {
        if (str_ends_with($queue, '.fifo')) {
            return true;
        }
        if (filter_var($queue, FILTER_VALIDATE_URL) !== false) {
            return false;
        }

        return is_string($suffix) && str_ends_with(Str::finish($queue, $suffix), '.fifo');
    }

    /**
     * A job that outlives the connection's retry_after is reserved again by another worker and,
     * as these jobs are never retried, marked failed while the first run may still succeed.
     */
    private function checkRetryAfter(string $connection): void
    {
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        if (! is_numeric($retryAfter)) {
            return;
        }
        $retryAfter = (int) $retryAfter;

        $send = (int) (new SendDocument(0))->timeout;
        $longest = max($send, (int) (new PollDeliveryStatus(0))->timeout, (int) (new FetchInboundDocument(0))->timeout);

        $retryAfter > $longest
            ? $this->row('queue retry_after', self::OK, sprintf('%d s, longer than the longest job timeout of %d s', $retryAfter, $longest))
            : $this->row('queue retry_after', self::WARN, sprintf(
                'queue.connections.%s.retry_after is %d s, not longer than the job timeouts (send %d s, longest %d s): '
                .'a send still running then is reserved again and marked failed; set it above %d',
                $connection,
                $retryAfter,
                $send,
                $longest,
                $longest,
            ));
    }

    private function checkInbox(EInvoicingManager $manager, string $name): void
    {
        $check = "transport {$name}: inbox";
        try {
            $this->row($check, self::OK, sprintf('%d waiting', count($manager->inbound($name)->pending())));
        } catch (Throwable $exception) {
            $this->row($check, self::FAIL, $this->describe($exception));
        }
    }

    private function row(string $check, string $status, string $detail): void
    {
        $this->rows[] = [$check, $status, $detail];
    }

    /**
     * The configuration of the transport with this name, looked up as an exact key.
     *
     * @return array<array-key, mixed>|null
     */
    private function transportConfig(string $name): ?array
    {
        $transports = config('einvoicing.transports');
        $config = is_array($transports) && array_key_exists($name, $transports) ? $transports[$name] : null;

        return is_array($config) ? $config : null;
    }

    /**
     * Every configured credential, with the API key pair in the forms an HTTP client sends it,
     * longest first so a value goes before its parts.
     *
     * @return list<string>
     */
    private function secrets(): array
    {
        $transports = config('einvoicing.transports');
        $secrets = [];
        foreach (is_array($transports) ? $transports : [] as $config) {
            if (! is_array($config)) {
                continue;
            }
            foreach (self::SECRET_KEYS as $key) {
                if (self::filled($config[$key] ?? null)) {
                    $secrets[] = (string) $config[$key];
                }
            }
            if (self::filled($config['api_key_id'] ?? null) && self::filled($config['secret'] ?? null)) {
                $pair = $config['api_key_id'].':'.$config['secret'];
                $secrets[] = $pair;
                $secrets[] = base64_encode($pair);
            }
        }
        $secrets = array_values(array_unique($secrets));
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /**
     * The exception's class and message, with every configured credential cut out of the message:
     * a driver or a database error may quote what it was given.
     */
    private function describe(Throwable $exception, bool $withClass = true): string
    {
        $message = $this->secrets === []
            ? $exception->getMessage()
            : str_replace($this->secrets, '[redacted]', $exception->getMessage());

        return $withClass ? $exception::class.': '.$message : $message;
    }

    /**
     * The full configuration paths of settings of a transport, comma separated.
     *
     * @param  list<string>  $keys
     */
    private static function keyPaths(string $name, array $keys): string
    {
        return implode(', ', array_map(
            static fn (string $key): string => "einvoicing.transports.{$name}.{$key}",
            $keys,
        ));
    }

    /**
     * Whether a setting is given: a number, or a string that is not blank.
     */
    private static function present(#[SensitiveParameter] mixed $value): bool
    {
        return is_int($value) || is_float($value) || self::filled($value);
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function filled(#[SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
