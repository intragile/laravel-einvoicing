<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\DatabaseChainStore;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainLink;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordTimestamp;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Throwable;

/**
 * Several processes append to one chain at the same time; the chain must stay a single line.
 *
 * Runs on the database named by EINVOICING_TEST_DB_URL (mysql://… or pgsql://…), else on a
 * SQLite file. The tables are dropped and migrated again, so the URL must name a throwaway database.
 */
#[Group('verifactu-concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class VerifactuConcurrencyTest extends TestCase
{
    private const WRITERS = 4;

    private const RECORDS_PER_WRITER = 25;

    /** Appends a writer retries itself when one still ends in ChainConflict. */
    private const CONFLICT_RETRIES = 5;

    private const WAIT_SECONDS = 60;

    protected function defineEnvironment($app): void
    {
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
        } else {
            $file = $this->emptyDir().'/chains.sqlite';
            touch($file);
            $config = [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 10000,
            ];
        }

        $app['config']->set('database.default', 'concurrency');
        $app['config']->set('database.connections.concurrency', $config);
    }

    public function test_forked_writers_never_fork_the_chain(): void
    {
        // All package tables, so the server databases also run every migration.
        $this->artisan('migrate:fresh')->assertSuccessful();
        $key = $this->key();

        // A child must not share the parent's socket or file handle: the parent closes its
        // connection before forking, and every child opens its own.
        DB::disconnect();

        // All writers start at the same instant, so their appends overlap.
        $start = microtime(true) + 0.5;
        $children = [];
        for ($writer = 1; $writer <= self::WRITERS; $writer++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->stop($children);
                $this->fail(sprintf('pcntl_fork() failed for writer %d.', $writer));
            }
            if ($pid === 0) {
                $this->runWriter($writer, $key, $start);
            }
            $children[$pid] = $writer;
        }

        $codes = $this->waitFor($children);
        $this->assertSame(array_fill(1, self::WRITERS, 0), $codes, 'Every writer exits with 0 (writer => exit code).');

        DB::purge();
        $rows = VerifactuRecord::query()->orderBy('sequence')->get();
        $this->assertCount(self::WRITERS * self::RECORDS_PER_WRITER, $rows);

        $sequences = $rows->map(fn (VerifactuRecord $row): int => (int) $row->sequence)->all();
        $this->assertSame(range(1, self::WRITERS * self::RECORDS_PER_WRITER), $sequences);
        $this->assertCount(count($rows), array_unique($rows->pluck('number')->all()));

        $previous = null;
        foreach ($rows as $row) {
            $this->assertSame($previous, $row->previous_hash, sprintf('Record %d links to the record before it.', $row->sequence));
            $previous = $row->hash;
        }

        $chain = VerifactuChain::query()->sole();
        $last = $rows->last();
        $this->assertNotNull($last);
        $this->assertSame(self::WRITERS * self::RECORDS_PER_WRITER, (int) $chain->sequence);
        $this->assertSame($last->hash, $chain->head_hash);
        $this->assertSame($last->number, $chain->head_number);
        $this->assertSame($last->generated_at, $chain->head_generated_at);
    }

    /**
     * Appends this writer's records on a connection of its own and ends the child process.
     */
    private function runWriter(int $writer, ChainKey $key, float $start): never
    {
        $code = 1;

        try {
            DB::purge();
            $store = new DatabaseChainStore(DB::connection(), new RecordWriter, $this->throttle());
            DB::connection()->getPdo();
            while (microtime(true) < $start) {
                usleep(1_000);
            }

            for ($n = 1; $n <= self::RECORDS_PER_WRITER; $n++) {
                $number = sprintf('W%d-%03d', $writer, $n);
                for ($attempt = 1; ; $attempt++) {
                    try {
                        $store->appendRow($key, IssuerDriver::Aeat, $this->throttle(), fn (?ChainHead $head): BillingRecord => $this->record($number, $head));
                        break;
                    } catch (ChainConflict $conflict) {
                        if ($attempt >= self::CONFLICT_RETRIES) {
                            throw $conflict;
                        }
                    }
                }
            }
            $code = 0;
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("Writer %d failed: %s: %s\n", $writer, $e::class, $e->getMessage()));
        }

        try {
            DB::disconnect();
        } catch (Throwable) {
            // The exit code already tells the parent whether the appends succeeded.
        }

        exit($code);
    }

    /**
     * Reaps the children, killing any still running after WAIT_SECONDS.
     *
     * @param  array<int, int>  $children  pid => writer
     * @return array<int, int> writer => exit code (minus the signal number when a signal ended it)
     */
    private function waitFor(array $children): array
    {
        $codes = [];
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while ($children !== []) {
            foreach ($children as $pid => $writer) {
                $status = 0;
                if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                    $codes[$writer] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -pcntl_wtermsig($status);
                    unset($children[$pid]);
                }
            }

            if ($children !== [] && microtime(true) > $deadline) {
                $this->stop($children);
                $this->fail(sprintf('Writers %s were still running after %d seconds.', implode(', ', $children), self::WAIT_SECONDS));
            }

            usleep(20_000);
        }

        ksort($codes);

        return $codes;
    }

    /**
     * Kills and reaps the given children, so none outlives a failed test.
     *
     * @param  array<int, int>  $children  pid => writer
     */
    private function stop(array $children): void
    {
        foreach (array_keys($children) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    private function key(): ChainKey
    {
        return ChainKey::for(Nif::parse(VerifactuSamples::ISSUER_NIF), VerifactuSamples::system(), VerifactuEnvironment::Test);
    }

    private function throttle(): ThrottleKey
    {
        return ThrottleKey::for(VerifactuSamples::system(), VerifactuEnvironment::Test);
    }

    private function record(string $number, ?ChainHead $head): BillingRecord
    {
        $at = RecordTimestamp::of(new DateTimeImmutable('2026-11-24T10:00:00Z'), new DateTimeZone('Europe/Madrid'));

        return RegistrationRecord::create(VerifactuSamples::vectorDraft($number, '24-11-2026'), $head?->link() ?? ChainLink::first(), VerifactuSamples::system(), $at);
    }
}
