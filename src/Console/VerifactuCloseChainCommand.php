<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Closes the VERI*FACTU chains of an issuer on one driver in the configured environment, the
 * documented cut-over from one driver to the other: register() refuses to write while a chain of
 * the other driver is open.
 *
 * --driver defaults to the driver the issuer does not use (from the issuer resolver). The chain of
 * the driver the issuer uses is never closed: a closed chain takes no more records, so the issuer
 * could not register anything. Move the issuer to the other driver first.
 *
 * A chain is closed only while none of its records waits for AEAT or B2Brouter (generated,
 * sending, unconfirmed or pending; for B2Brouter, also the imports not attached to a chain yet).
 * Settle those first: einvoicing:verifactu-submit, einvoicing:verifactu-status --lookup. A chain
 * row that never held a record or a seed is no chain and is left as it is. The exit code is 1 when
 * a chain is refused.
 */
final class VerifactuCloseChainCommand extends Command
{
    use InteractsWithVerifactu;

    protected $signature = 'einvoicing:verifactu-close-chain
        {--issuer= : The issuer NIF; defaults to the only configured issuer}
        {--driver= : The driver of the chains to close (aeat or b2brouter); defaults to the one the issuer does not use}';

    protected $description = 'Close the VERI*FACTU chains of an issuer on one driver (driver cut-over)';

    /** The states of a record that AEAT or B2Brouter has not settled yet. */
    private const OPEN_STATES = [VerifactuRecordState::Generated, VerifactuRecordState::Sending, VerifactuRecordState::Unconfirmed, VerifactuRecordState::Pending];

    /** The open records a refusal lists at most. */
    private const LIST_LIMIT = 5;

    public function handle(VerifactuManager $verifactu): int
    {
        try {
            $environment = $verifactu->settings()->environment;
            $issuer = $this->requiredIssuer($verifactu);
            $driver = $this->driver($verifactu, $issuer);
        } catch (ConfigurationException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $chains = VerifactuChain::query()
            ->where('issuer_nif', $issuer->value)
            ->where('environment', $environment->value)
            ->where('driver', $driver->value)
            ->whereNull('closed_at')
            ->orderBy('id')
            ->get();
        if ($chains->isEmpty()) {
            $this->components->info(sprintf('Issuer %s has no open %s chain in the %s environment.', $issuer->value, $driver->value, $environment->value));

            return self::SUCCESS;
        }

        $refused = false;
        foreach ($chains as $chain) {
            $name = $chain->key()->toString();
            if ($chain->sequence === 0 && $chain->head_hash === null) {
                $this->line(sprintf('  %s: never held a record or a seed; left as it is.', OutputFormatter::escape($name)));

                continue;
            }

            $closed = DB::transaction(function () use ($chain): ?array {
                // The lock serialises the check with the writers of the chain, which lock it too.
                $locked = VerifactuChain::query()->whereKey($chain->id)->whereNull('closed_at')->lockForUpdate()->first();
                if ($locked === null) {
                    return null;
                }
                $open = $this->openRecords($locked)->limit(self::LIST_LIMIT)->get();
                if ($open->isNotEmpty()) {
                    return ['open' => $open, 'count' => $this->openRecords($locked)->count()];
                }
                VerifactuChain::query()->whereKey($locked->id)->update(['closed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

                return [];
            });

            if ($closed === null) {
                $this->line(sprintf('  %s: closed by another process meanwhile.', OutputFormatter::escape($name)));

                continue;
            }
            if ($closed !== []) {
                $refused = true;
                $this->components->error(sprintf('Chain %s is not closed: %d %s not settled yet.', $name, $closed['count'], $closed['count'] === 1 ? 'record is' : 'records are'));
                foreach ($closed['open'] as $row) {
                    $this->line(sprintf(
                        '  row %d: invoice %s of %s, %s%s',
                        $row->id,
                        OutputFormatter::escape($row->number),
                        $row->issue_date,
                        $row->state->value,
                        $row->sequence === null ? '' : ', sequence '.$row->sequence,
                    ));
                }

                continue;
            }
            $this->components->info(sprintf('Chain %s is closed.', $name));
        }

        return $refused ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException when the option is not a driver, names the issuer's own
     *                                  driver, or is missing for an issuer the resolver does not know
     */
    private function driver(VerifactuManager $verifactu, Nif $issuer): IssuerDriver
    {
        try {
            $current = $verifactu->resolver()->resolve($issuer)->driver;
        } catch (UnknownIssuer) {
            $current = null;
        }

        $option = $this->option('driver');
        if (! is_string($option) || $option === '') {
            if ($current === null) {
                throw new InvalidArgumentException(sprintf('The issuer resolver does not know issuer %s; pass --driver=aeat or --driver=b2brouter.', $issuer->value));
            }

            return $current === IssuerDriver::Aeat ? IssuerDriver::B2Brouter : IssuerDriver::Aeat;
        }

        $driver = IssuerDriver::tryFrom(strtolower($option))
            ?? throw new InvalidArgumentException(sprintf('--driver "%s" is not a driver; use aeat or b2brouter.', $option));
        if ($driver === $current) {
            throw new InvalidArgumentException(sprintf(
                'Issuer %s uses the %s driver, so its %s chain is the one it writes to and is not closed. Move the issuer to the other driver first.',
                $issuer->value,
                $driver->value,
                $driver->value,
            ));
        }

        return $driver;
    }

    /**
     * The records of the chain that AEAT or B2Brouter has not settled yet; for a B2Brouter chain,
     * also the issuer's imports that are not attached to a chain yet.
     *
     * @return Builder<VerifactuRecord>
     */
    private function openRecords(VerifactuChain $chain): Builder
    {
        $states = array_map(static fn (VerifactuRecordState $state): string => $state->value, self::OPEN_STATES);

        return VerifactuRecord::query()
            ->whereIn('state', $states)
            ->where(static function (Builder $query) use ($chain): void {
                $query->where('chain_id', $chain->id);
                if ($chain->driver === IssuerDriver::B2Brouter) {
                    $query->orWhere(static fn (Builder $foreign) => $foreign
                        ->whereNull('chain_id')
                        ->where('driver', IssuerDriver::B2Brouter->value)
                        ->where('issuer_nif', $chain->issuer_nif)
                        ->where('environment', $chain->environment->value));
                }
            })
            ->orderBy('id');
    }
}
