<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Jobs\MessageJob;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandConfig;
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

    private const BUILT_IN_DRIVER = 'recommand';

    /** Settings of the built-in driver without which it cannot be built. */
    private const REQUIRED_KEYS = ['api_key_id', 'secret', 'company_id'];

    /** Settings that are never printed. */
    private const SECRET_KEYS = ['api_key_id', 'secret', 'webhook_secret'];

    /** @var list<array{string, string, string}> */
    private array $rows = [];

    /** @var list<string> configured credentials, cut out of every exception message */
    private array $secrets = [];

    public function handle(EInvoicingManager $manager): int
    {
        $this->rows = [];
        $this->secrets = $this->secrets();

        $this->checkDefault($manager);
        foreach ($manager->names() as $name) {
            $this->checkTransport($manager, $name);
        }
        $this->checkValidation($manager);
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
                self::BUILT_IN_DRIVER,
            ));

            return;
        }
        if ($driver !== self::BUILT_IN_DRIVER) {
            $this->row("{$check}: config", self::OK, sprintf('custom driver "%s"; its settings are checked by its factory', $driver));

            return;
        }

        $missing = array_values(array_filter(
            self::REQUIRED_KEYS,
            static fn (string $key): bool => ! self::filled($config[$key] ?? null),
        ));
        $missing === []
            ? $this->row("{$check}: config", self::OK, 'API key id, secret and company id set')
            : $this->row("{$check}: config", self::FAIL, 'missing or empty: '.implode(', ', array_map(
                static fn (string $key): string => "einvoicing.transports.{$name}.{$key}",
                $missing,
            )));

        $this->checkBaseUrl($name, $config['base_url'] ?? null);
        $this->checkWebhook($name, $config);
    }

    private function checkBaseUrl(string $name, #[SensitiveParameter] mixed $baseUrl): void
    {
        $check = "transport {$name}: base URL";
        if ($baseUrl === null || $baseUrl === '') {
            $this->row($check, self::OK, 'default '.RecommandConfig::DEFAULT_BASE_URL);

            return;
        }

        // The URL itself is never printed: it may carry credentials as user info.
        try {
            if (! is_string($baseUrl)) {
                throw new InvalidArgumentException('The base URL is not a string.');
            }
            new RecommandConfig('id', 'secret', 'company', $baseUrl);
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
    private function checkWebhook(string $name, #[SensitiveParameter] array $config): void
    {
        $check = "transport {$name}: webhook";
        if (! self::filled($config['webhook_secret'] ?? null)) {
            $this->row($check, self::WARN, 'webhooks off; schedule einvoicing:poll-inbound');

            return;
        }
        if (! Route::has('einvoicing.webhook')) {
            $this->row($check, self::WARN, 'einvoicing.webhooks.enabled is off, so no webhook route exists; schedule einvoicing:poll-inbound');

            return;
        }
        if (! self::filled($config['company_id'] ?? null)) {
            $this->row($check, self::WARN, sprintf(
                'einvoicing.transports.%s.company_id is missing, so every notification that names a company is ignored',
                $name,
            ));

            return;
        }

        $this->row($check, self::OK, route('einvoicing.webhook', ['transport' => $name]));
    }

    private function checkValidation(EInvoicingManager $manager): void
    {
        if (! (bool) config('einvoicing.validation.enabled', true)) {
            $this->row('validation', self::WARN, 'off: documents are sent unchecked, and a document whose CustomizationID is not Peppol BIS Billing is not refused before sending');

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

        $missing === []
            ? $this->row('tables', self::OK, 'einvoicing_outbound_documents, einvoicing_inbound_documents')
            : $this->row('tables', self::FAIL, 'missing: '.implode(', ', $missing).'; run php artisan migrate');
    }

    private function checkQueue(): void
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

        extension_loaded('pcntl')
            ? $this->row('queue', self::OK, "{$connection} (driver {$driver})")
            : $this->row('queue', self::WARN, sprintf(
                '%s (driver %s), but pcntl is not loaded: workers cannot stop a job at its timeout, so a row einvoicing:resolve takes as abandoned may still be sending',
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
     * earlier; a FIFO queue supports no per-message delay at all, so a sending claim would
     * expire as soon as it is taken.
     */
    private function checkSqs(string $connection): void
    {
        $queue = config('einvoicing.queue.name');
        if (! is_string($queue) || $queue === '') {
            $queue = config("queue.connections.{$connection}.queue");
        }
        if (is_string($queue) && str_ends_with($queue, '.fifo')) {
            $this->row('queue fifo', self::FAIL, sprintf(
                'the SQS queue %s is a FIFO queue, which ignores job delays: every sending claim would expire at once '
                .'and turn each send unconfirmed; use a standard queue',
                $queue,
            ));
        }

        $delays = [
            'einvoicing.sending.backoff (send and inbound retries)' => SendDocument::longestBackoff(),
            'sending claim expiry (from einvoicing.http timeouts)' => SendDocument::claimLifetime(),
        ];
        if ((bool) config('einvoicing.status.poll', true)) {
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
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function filled(#[SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
