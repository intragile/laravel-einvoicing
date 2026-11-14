<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;

/**
 * Base of the jobs that drive a stored document through its lifecycle.
 *
 * A job carries only the row id and the attempt it stands for, never the document, the
 * configuration or a credential; it re-reads the row and acts only when the row is in the state
 * the job expects, so duplicate jobs are harmless. Jobs are never retried by the queue: a failed
 * attempt is either recorded on the row or scheduled again as a new job.
 */
abstract class MessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Seconds the worker may run the job; null uses the worker's default. */
    public ?int $timeout = null;

    final public function __construct(public readonly int $messageId, public readonly int $attempt = 0)
    {
        $connection = config('einvoicing.queue.connection');
        $queue = config('einvoicing.queue.name');

        $this->onConnection(is_string($connection) && $connection !== '' ? $connection : null);
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : null);
        self::assertSettings();
        $this->timeout = static::jobTimeout();
    }

    /** Numeric settings the jobs read, some of them only midway through a job. */
    private const NUMERIC_SETTINGS = ['http.timeout', 'http.connect_timeout', 'sending.max_attempts', 'status.max_attempts'];

    private const DEFAULT_MAX_FETCH_ATTEMPTS = 10;

    /** The largest accepted inbound attempt cap, so the counter fits a small unsigned column everywhere. */
    private const MAX_FETCH_ATTEMPTS_LIMIT = 65535;

    /**
     * Checks every numeric setting the jobs read. It runs before a row is written, when a job is
     * built and when a job starts, before any claim or other state change, so a setting that a
     * job reads only after the transport has answered can never fail at that point.
     *
     * @throws ConfigurationException naming the first invalid setting
     */
    public static function assertSettings(): void
    {
        foreach (self::NUMERIC_SETTINGS as $key) {
            self::setting($key, 0);
        }
        self::attemptCap();
    }

    /**
     * The total number of failed fetches and acknowledgements after which an inbound document is
     * no longer tried automatically.
     *
     * @throws ConfigurationException when inbound.max_fetch_attempts is not a whole number from 1 to 65535
     */
    public static function attemptCap(): int
    {
        return ConfigValue::integer(
            config('einvoicing.inbound.max_fetch_attempts') ?? self::DEFAULT_MAX_FETCH_ATTEMPTS,
            'einvoicing.inbound.max_fetch_attempts',
            1,
            self::MAX_FETCH_ATTEMPTS_LIMIT,
        );
    }

    /** The longest delay, in seconds, that Amazon SQS accepts for a message. */
    public const SQS_MAX_DELAY = 900;

    /**
     * Queues the job for a row after the given number of seconds.
     *
     * The job is dispatched after the open database transaction commits, so it never reads a
     * row that is not visible yet. The delay is never waited out here; on the sync connection,
     * which ignores delays, callers decide whether delayed work makes sense at all. On Amazon
     * SQS, which refuses longer delays, the delay is capped at SQS_MAX_DELAY, so the job simply
     * runs earlier than configured.
     *
     * @param  string|null  $connection  the queue connection; the configured one when null
     */
    public static function schedule(int $messageId, int $delay = 0, int $attempt = 0, ?string $connection = null): void
    {
        $job = new static($messageId, $attempt);
        $job->afterCommit();
        if ($connection !== null) {
            $job->onConnection($connection);
        }
        if (self::driverOf($connection ?? self::connectionName()) === 'sqs') {
            $delay = min($delay, self::SQS_MAX_DELAY);
        }
        if ($delay > 0) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    /** Queue drivers that run a job in the current process and ignore its delay. */
    private const SYNCHRONOUS_DRIVERS = ['sync', 'deferred', 'background'];

    /**
     * Whether the connection, by default the one configured for the jobs of this package, has a
     * driver that runs jobs in the current process and ignores delays, so no delayed work can be
     * scheduled on it.
     */
    public static function runsSynchronously(?string $connection = null): bool
    {
        return in_array(self::driverOf($connection ?? self::connectionName()), self::SYNCHRONOUS_DRIVERS, true);
    }

    /**
     * The queue connection this job runs on: the one the worker took it from, else the one it was
     * dispatched to, else the configured one. Follow-up jobs are scheduled on the same connection,
     * so the decision whether they can be delayed holds for the connection that carries them.
     */
    protected function queueConnection(): ?string
    {
        $connection = $this->job?->getConnectionName() ?? $this->connection;

        return is_string($connection) && $connection !== '' ? $connection : self::connectionName();
    }

    /**
     * The name of the queue connection the jobs of this package use.
     */
    public static function connectionName(): ?string
    {
        $connection = config('einvoicing.queue.connection');
        if (! is_string($connection) || $connection === '') {
            $connection = config('queue.default');
        }

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * The driver of the queue connection the jobs of this package use, when it is configured.
     */
    public static function connectionDriver(): ?string
    {
        return self::driverOf(self::connectionName());
    }

    private static function driverOf(?string $connection): ?string
    {
        $driver = $connection !== null ? config("queue.connections.{$connection}.driver") : null;

        return is_string($driver) ? $driver : null;
    }

    protected static function jobTimeout(): ?int
    {
        return null;
    }

    /**
     * An integer setting of the einvoicing configuration, rounded up; the default when it is not
     * set.
     *
     * @throws ConfigurationException when the value is not a number or a numeric string
     */
    protected static function setting(string $key, int $default): int
    {
        $value = config('einvoicing.'.$key) ?? $default;

        return (int) ceil(ConfigValue::number($value, 'einvoicing.'.$key));
    }

    /**
     * The configured delay in seconds at the given position of a list of delays; the last delay
     * repeats, and the default list applies when none is configured.
     *
     * @param  non-empty-list<int>  $default
     */
    protected static function delayAt(string $key, array $default, int $index): int
    {
        $delays = self::delays($key, $default);

        return max(0, $delays[min(max($index, 0), count($delays) - 1)]);
    }

    /**
     * The longest configured delay in seconds of a list of delays.
     *
     * @param  non-empty-list<int>  $default
     */
    protected static function longestDelayOf(string $key, array $default): int
    {
        return max(0, ...self::delays($key, $default));
    }

    /**
     * The configured delays in seconds, rounded up; the default list when none is configured.
     *
     * @param  non-empty-list<int>  $default
     * @return non-empty-list<int>
     */
    private static function delays(string $key, array $default): array
    {
        $configured = config('einvoicing.'.$key);
        $delays = is_array($configured)
            ? array_values(array_map(static fn (mixed $delay): int => (int) ceil((float) $delay), array_filter($configured, is_numeric(...))))
            : [];

        return $delays === [] ? $default : $delays;
    }
}
