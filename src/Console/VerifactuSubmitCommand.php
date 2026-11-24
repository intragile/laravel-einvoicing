<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuThrottle;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\ErrorAction;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Drives the VERI*FACTU submission queue of the configured environment.
 *
 * For every throttle key of an open AEAT chain (of the issuer given with --issuer), a block left by
 * a refused submission is cleared and, when records wait to be sent, a SubmitVerifactuRecords run
 * is scheduled; the run itself applies the AEAT flow control and the resend backoff. Under the
 * installation throttle scope one key serves every issuer of the installation, so its run sends
 * the records of the other issuers too.
 *
 * With --retry-unconfirmed, records that are no longer resent automatically are taken again first:
 * - AEAT records left unconfirmed after their attempts, and records left in sending for longer
 *   than einvoicing.verifactu.stuck_after_minutes (a worker died between the claim and the
 *   answer), are resent with the same bytes. They keep one attempt, so AEAT's duplicate answer
 *   confirms them; with einvoicing.sending.max_attempts at 1 they restart at none, and a duplicate
 *   answer then needs a lookup;
 * - AEAT records answered with a duplicate on their first send are not resent: only
 *   einvoicing:verifactu-status --lookup can tell whose record AEAT holds;
 * - B2Brouter imports whose outcome is unknown are settled with
 *   VerifactuManager::retryB2BrouterImport(), which looks the invoice up before importing again;
 * - B2Brouter records still pending (for instance after their polls ran out) are polled again from
 *   the first attempt.
 *
 * The exit code is 1 when a setting is invalid or a B2Brouter import could not be settled.
 */
final class VerifactuSubmitCommand extends Command
{
    use InteractsWithVerifactu;

    protected $signature = 'einvoicing:verifactu-submit
        {--issuer= : Only the chains of this issuer NIF}
        {--retry-unconfirmed : Take again the records that are no longer resent or polled automatically}';

    protected $description = 'Schedule the VERI*FACTU submission runs, clear blocks, and retry unconfirmed records';

    /** The records a lookup hint lists at most. */
    private const HINT_LIMIT = 20;

    public function handle(VerifactuManager $verifactu): int
    {
        try {
            $settings = $verifactu->settings();
            $issuer = $this->issuerFilter();
        } catch (ConfigurationException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $environment = $settings->environment;

        $failed = false;
        if ($this->option('retry-unconfirmed') === true) {
            $this->retryAeat($environment, $issuer, $settings->stuckAfterMinutes);
            $failed = ! $this->retryB2Brouter($verifactu, $environment, $issuer);
        }

        $this->scheduleRuns($environment, $issuer);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Clears the blocks of the throttle keys and schedules a run for each key with records to send.
     */
    private function scheduleRuns(VerifactuEnvironment $environment, ?Nif $issuer): void
    {
        $chains = $this->openAeatChains($environment, $issuer)->get(['id', 'throttle_key']);
        $keys = $chains->pluck('throttle_key')->filter(static fn (mixed $key): bool => is_string($key) && $key !== '')->unique()->sort()->values();
        if ($keys->isEmpty()) {
            $this->components->info(sprintf('No open AEAT chain in the %s environment%s.', $environment->value, $issuer === null ? '' : ' for issuer '.$issuer->value));

            return;
        }

        $maxAttempts = SubmitVerifactuRecords::maxAttempts();
        foreach ($keys as $key) {
            $chainIds = $chains->where('throttle_key', $key)->pluck('id');
            $this->clearBlock($key);

            $waiting = VerifactuRecord::query()
                ->whereIn('chain_id', $chainIds)
                ->where('driver', IssuerDriver::Aeat->value)
                ->where(static fn (Builder $query) => $query
                    ->where('state', VerifactuRecordState::Generated->value)
                    ->orWhere(static fn (Builder $unconfirmed) => $unconfirmed
                        ->where('state', VerifactuRecordState::Unconfirmed->value)
                        ->where('attempts', '<', $maxAttempts)
                        ->where(static fn (Builder $action) => $action->whereNull('error_action')->orWhere('error_action', '!=', ErrorAction::ResolveDuplicate->name))))
                ->count();

            if ($waiting === 0) {
                $this->line(sprintf('  %s: nothing to send.', self::escape($key)));

                continue;
            }
            SubmitVerifactuRecords::schedule($key);
            $this->line(sprintf('  %s: %d %s to send; a submission run is scheduled.', self::escape($key), $waiting, $waiting === 1 ? 'record' : 'records'));
        }
    }

    private function clearBlock(string $key): void
    {
        $throttle = VerifactuThrottle::query()->where('key', $key)->first();
        if ($throttle === null || $throttle->blocked_at === null) {
            return;
        }

        $cleared = VerifactuThrottle::query()
            ->whereKey($throttle->id)
            ->whereNotNull('blocked_at')
            ->update(['blocked_at' => null, 'blocked_code' => null, 'blocked_message' => null, 'updated_at' => Carbon::now()]);
        if ($cleared === 1) {
            $this->line(sprintf(
                '  %s: cleared the block of %s (%s): %s',
                self::escape($key),
                $throttle->blocked_at->toIso8601String(),
                self::escape((string) $throttle->blocked_code),
                self::escape((string) $throttle->blocked_message),
            ));
        }
    }

    /**
     * Makes the AEAT records that are no longer resent automatically due again, and lists those
     * that wait for a lookup.
     */
    private function retryAeat(VerifactuEnvironment $environment, ?Nif $issuer, int $stuckAfterMinutes): void
    {
        $chainIds = $this->openAeatChains($environment, $issuer)->pluck('id');
        if ($chainIds->isEmpty()) {
            return;
        }
        // One attempt is kept, so the resend counts as one and AEAT's duplicate answer confirms
        // the record; it must stay below the cap for the job to take the record.
        $attempts = SubmitVerifactuRecords::maxAttempts() > 1 ? 1 : 0;
        $now = Carbon::now();

        $stale = VerifactuRecord::query()
            ->whereIn('chain_id', $chainIds)
            ->where('driver', IssuerDriver::Aeat->value)
            ->where('state', VerifactuRecordState::Sending->value)
            ->where('updated_at', '<', $now->copy()->subMinutes($stuckAfterMinutes))
            ->get(['id', 'updated_at']);
        $reclaimed = 0;
        foreach ($stale as $row) {
            $reclaimed += VerifactuRecord::query()
                ->whereKey($row->id)
                ->where('state', VerifactuRecordState::Sending->value)
                ->where('updated_at', $row->getRawOriginal('updated_at'))
                ->update([
                    'state' => VerifactuRecordState::Unconfirmed->value,
                    'attempts' => $attempts,
                    // The claim time: the request may have left then.
                    'submitted_at' => $row->updated_at,
                    'updated_at' => $now,
                ]);
        }

        $reset = VerifactuRecord::query()
            ->whereIn('chain_id', $chainIds)
            ->where('driver', IssuerDriver::Aeat->value)
            ->where('state', VerifactuRecordState::Unconfirmed->value)
            ->where('attempts', '>', $attempts)
            ->where(static fn (Builder $query) => $query->whereNull('error_action')->orWhere('error_action', '!=', ErrorAction::ResolveDuplicate->name))
            ->update(['attempts' => $attempts, 'updated_at' => $now]);

        if ($reclaimed > 0) {
            $this->line(sprintf('  %d %s left in sending for over %d minutes will be resent with the same bytes.', $reclaimed, $reclaimed === 1 ? 'record' : 'records', $stuckAfterMinutes));
        }
        if ($reset > 0) {
            $this->line(sprintf('  %d unconfirmed %s will be resent with the same bytes.', $reset, $reset === 1 ? 'record' : 'records'));
        }

        $held = VerifactuRecord::query()
            ->whereIn('chain_id', $chainIds)
            ->where('driver', IssuerDriver::Aeat->value)
            ->where('state', VerifactuRecordState::Unconfirmed->value)
            ->where('error_action', ErrorAction::ResolveDuplicate->name)
            ->orderBy('id');
        $count = (clone $held)->count();
        if ($count === 0) {
            return;
        }
        $this->components->warn(sprintf('%d %s answered as a duplicate on the first send; only a lookup can tell whose record AEAT holds:', $count, $count === 1 ? 'record was' : 'records were'));
        foreach ($held->limit(self::HINT_LIMIT)->get(['id', 'issuer_nif', 'number', 'issue_date']) as $row) {
            $this->line(sprintf(
                '  php artisan einvoicing:verifactu-status %s --date=%s --issuer=%s --lookup',
                self::escape(escapeshellarg($row->number)),
                $row->issue_date,
                $row->issuer_nif,
            ));
        }
    }

    /**
     * Settles the B2Brouter imports whose outcome is unknown and polls the pending records again.
     *
     * @return bool false when an import could not be settled
     */
    private function retryB2Brouter(VerifactuManager $verifactu, VerifactuEnvironment $environment, ?Nif $issuer): bool
    {
        $rows = static fn (): Builder => VerifactuRecord::query()
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->where('environment', $environment->value)
            ->when($issuer !== null, static fn (Builder $query) => $query->where('issuer_nif', $issuer?->value))
            ->orderBy('id');

        // Read first, so an import settled below is not polled twice.
        $pending = $rows()
            ->where('state', VerifactuRecordState::Pending->value)
            ->whereNull('chain_id')
            ->whereNotNull('b2brouter_invoice_id')
            ->get(['id']);

        $settled = true;
        foreach ($rows()->where('state', VerifactuRecordState::Unconfirmed->value)->whereNull('b2brouter_invoice_id')->get() as $row) {
            $name = sprintf('B2Brouter import of invoice %s of %s (row %d)', $row->number, $row->issue_date, $row->id);
            try {
                $retried = $verifactu->retryB2BrouterImport($row);
            } catch (TransportException|ConfigurationException|InvalidArgumentException $exception) {
                $this->components->error(sprintf('%s: %s', $name, $exception->getMessage()));
                $settled = false;

                continue;
            }

            if ($retried->state === VerifactuRecordState::Pending) {
                $this->line(sprintf('  %s: B2Brouter invoice %s; its tax report is polled.', self::escape($name), self::escape((string) $retried->b2brouter_invoice_id)));
            } else {
                $this->components->warn(sprintf('%s is still unconfirmed: %s', $name, (string) $retried->error_message));
                $settled = false;
            }
        }

        foreach ($pending as $row) {
            PollVerifactuTaxReport::schedule($row->id);
        }
        if ($pending->isNotEmpty()) {
            $this->line(sprintf('  %d pending B2Brouter %s polled again.', $pending->count(), $pending->count() === 1 ? 'record is' : 'records are'));
        }

        return $settled;
    }

    /**
     * @return Builder<VerifactuChain>
     */
    private function openAeatChains(VerifactuEnvironment $environment, ?Nif $issuer): Builder
    {
        return VerifactuChain::query()
            ->where('driver', IssuerDriver::Aeat->value)
            ->where('environment', $environment->value)
            ->whereNull('closed_at')
            ->when($issuer !== null, static fn (Builder $query) => $query->where('issuer_nif', $issuer?->value))
            ->orderBy('id');
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
