<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

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
        $this->timeout = static::jobTimeout();
    }

    /**
     * Queues the job for a row after the given number of seconds.
     *
     * The job is dispatched after the open database transaction commits, so it never reads a
     * row that is not visible yet. The delay is never waited out here; on the sync connection,
     * which ignores delays, callers decide whether delayed work makes sense at all.
     */
    public static function schedule(int $messageId, int $delay = 0, int $attempt = 0): void
    {
        $job = new static($messageId, $attempt);
        $job->afterCommit();
        if ($delay > 0) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    /** Queue drivers that run a job in the current process and ignore its delay. */
    private const SYNCHRONOUS_DRIVERS = ['sync', 'deferred', 'background'];

    /**
     * Whether jobs of this package run on a connection whose driver runs them in the current
     * process and ignores delays, so no delayed work can be scheduled.
     */
    public static function runsSynchronously(): bool
    {
        $connection = config('einvoicing.queue.connection');
        if (! is_string($connection) || $connection === '') {
            $connection = config('queue.default');
        }

        return is_string($connection)
            && in_array(config("queue.connections.{$connection}.driver"), self::SYNCHRONOUS_DRIVERS, true);
    }

    protected static function jobTimeout(): ?int
    {
        return null;
    }

    /**
     * An integer setting of the einvoicing configuration, rounded up; the default when the
     * value is not numeric.
     */
    protected static function setting(string $key, int $default): int
    {
        $value = config('einvoicing.'.$key, $default);

        return is_numeric($value) ? (int) ceil((float) $value) : $default;
    }

    /**
     * The configured delay in seconds at the given position of a list of delays; the last delay
     * repeats, and the default list applies when none is configured.
     *
     * @param  non-empty-list<int>  $default
     */
    protected static function delayAt(string $key, array $default, int $index): int
    {
        $configured = config('einvoicing.'.$key);
        $delays = is_array($configured)
            ? array_values(array_map(static fn (mixed $delay): int => (int) ceil((float) $delay), array_filter($configured, is_numeric(...))))
            : [];
        if ($delays === []) {
            $delays = $default;
        }

        return max(0, $delays[min(max($index, 0), count($delays) - 1)]);
    }
}
