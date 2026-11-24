<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordGenerated;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\SubmitVerifactuRecords;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuChain;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AltaOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\AnulacionOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\BillingRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\CancellationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\Chainer;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainHead;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\ComputerSystem;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\MappingRefused;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RecordMapper;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;
use LogicException;

/**
 * The entry point to the Spanish VERI*FACTU support of einvoicing.verifactu: the checked
 * settings, the issuer resolver, the installed AEAT schemas, and the registration, cancellation
 * and correction of billing records.
 *
 * For an issuer on the AEAT driver, register(), cancel() and correct() build the record
 * synchronously under the chain lock: the record is chained to the stored head, written once,
 * and stored with that XML, its QR URL and the literal generation timestamp. The returned row is
 * in state generated; VerifactuRecordGenerated fires and the submission job is queued once the
 * surrounding database transaction commits, and neither happens when it rolls back.
 *
 * The chain key and the throttle key come from the resolved issuer's environment, which must be
 * einvoicing.verifactu.environment. The chain key is always derived from the computer system the
 * records are written with, and the issuer the resolver returns must be the invoice's.
 *
 * The application is held inside a closure, so the configuration and its secrets stay out of
 * var_export(), print_r() and var_dump() output.
 */
final class VerifactuManager
{
    /** The folder of the AEAT schemas below storage_path() when none is configured. */
    public const DEFAULT_SCHEMAS_PATH = 'einvoicing/verifactu';

    /** @var Closure(): Application */
    private readonly Closure $app;

    private ?VerifactuSchemas $schemas = null;

    private ?string $schemasDir = null;

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * @throws ConfigurationException when a setting is invalid
     */
    public function settings(): VerifactuSettings
    {
        $app = ($this->app)();

        return VerifactuSettings::fromConfig(
            $app->make('config'),
            $app->storagePath(self::DEFAULT_SCHEMAS_PATH),
        );
    }

    /**
     * The resolver bound in the container: the application's own, or the configuration resolver.
     */
    public function resolver(): VerifactuIssuerResolver
    {
        return ($this->app)()->make(VerifactuIssuerResolver::class);
    }

    /**
     * The installed AEAT schema set, checked against its pinned checksums.
     *
     * @throws ConfigurationException when the set is not installed or does not match
     */
    public function schemas(): VerifactuSchemas
    {
        return $this->schemasFor($this->settings());
    }

    /**
     * The database chain store, writing records validated against the installed schemas.
     *
     * @throws ConfigurationException when a setting is invalid, the computer system is not
     *                                declared, or the schemas are not installed
     */
    public function store(): DatabaseChainStore
    {
        $settings = $this->settings();

        return $this->storeFor($settings, $this->schemasFor($settings), $settings->system());
    }

    /**
     * The chainer of the declared computer system: the clock is the application's (now()), and
     * the generation timestamp is written in einvoicing.verifactu.timezone.
     *
     * @throws ConfigurationException when a setting is invalid or the computer system is not declared
     */
    public function chainer(): Chainer
    {
        $settings = $this->settings();

        return $this->chainerFor($settings, $settings->system());
    }

    /**
     * Generates, chains and stores the registration record (RegistroAlta) of an invoice.
     *
     * @param  string|null  $reference  the application's own reference, stored on the row
     *
     * @throws ConfigurationException when a setting is invalid, the schemas are not installed, the
     *                                issuer resolves to another issuer or environment, or an open
     *                                chain of the other driver exists for the issuer
     * @throws MappingRefused when the invoice cannot be mapped; nothing is stored
     * @throws UnknownIssuer when the resolver has no data for the invoice's issuer
     * @throws InvalidArgumentException when the invoice carries a correction operation, or its
     *                                  IDFactura already has a registration record in the chain
     * @throws InvalidRecord when the written record fails the schema
     * @throws ChainConflict when the chain is closed or the head moved during the write
     */
    public function register(Invoice $invoice, ?string $reference = null): VerifactuRecord
    {
        $settings = $this->settings();
        $schemas = $this->schemasFor($settings);
        $draft = (new RecordMapper)->map($invoice);
        $context = $this->contextFor($draft->id->issuer, $settings);
        $this->assertNoOpenChainOfOtherDriver($context);

        if ($draft->operation !== AltaOperation::Initial) {
            throw new InvalidArgumentException(sprintf(
                'Invoice %s carries the operation AltaOperation::%s; register() takes only AltaOperation::Initial. Use correct() for a correction or a new submission after a rejection.',
                $draft->id->number,
                $draft->operation->name,
            ));
        }

        if ($context->driver === IssuerDriver::B2Brouter) {
            return $this->registerWithB2Brouter($draft, $context, $reference);
        }

        return $this->appendAeat($settings, $schemas, $context, $draft, $reference, function (ChainKey $key) use ($draft): void {
            if ($this->hasRegistration($key, $draft->id)) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s of %s already has a registration record in chain %s. Use correct() to submit a correction or a new submission after a rejection.',
                    $draft->id->number,
                    $draft->id->dateText(),
                    $key->toString(),
                ));
            }
        });
    }

    /**
     * Generates, chains and stores a cancellation record (RegistroAnulacion) of an invoice.
     *
     * A Normal cancellation needs a stored registration record of the invoice in the chain; for an
     * invoice AEAT never received, pass AnulacionOperation::WithoutPriorRecord.
     *
     * @param  string|null  $reference  the application's own reference, stored on the row
     *
     * @throws ConfigurationException as register()
     * @throws UnknownIssuer when the resolver has no data for the issuer
     * @throws InvalidArgumentException when a Normal cancellation finds no registration record
     * @throws LogicException for an issuer on the B2Brouter driver
     * @throws ChainConflict when the chain is closed or the head moved during the write
     */
    public function cancel(InvoiceId $invoice, ?string $reference = null, AnulacionOperation $operation = AnulacionOperation::Normal): VerifactuRecord
    {
        $settings = $this->settings();
        $schemas = $this->schemasFor($settings);
        $context = $this->contextFor($invoice->issuer, $settings);
        $this->assertNoOpenChainOfOtherDriver($context);
        $this->assertAeatDriver($context, 'Cancellations');

        $draft = CancellationDraft::of($invoice, $operation);

        return $this->appendAeat($settings, $schemas, $context, $draft, $reference, function (ChainKey $key) use ($invoice, $operation): void {
            if ($operation === AnulacionOperation::Normal && ! $this->hasRegistration($key, $invoice)) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s of %s has no registration record in chain %s. To cancel an invoice AEAT never received, pass AnulacionOperation::WithoutPriorRecord.',
                    $invoice->number,
                    $invoice->dateText(),
                    $key->toString(),
                ));
            }
        });
    }

    /**
     * Generates, chains and stores a new registration record of an invoice that already has one:
     * a correction (Subsanacion) or a new submission after a rejection (RechazoPrevio). The
     * operation comes from SpanishInvoice::$operation and cannot be AltaOperation::Initial.
     *
     * @param  string|null  $reference  the application's own reference, stored on the row
     *
     * @throws ConfigurationException as register()
     * @throws MappingRefused when the invoice cannot be mapped; nothing is stored
     * @throws UnknownIssuer when the resolver has no data for the invoice's issuer
     * @throws InvalidArgumentException when the operation is Initial, or the IDFactura has no
     *                                  earlier registration record in the chain
     * @throws LogicException for an issuer on the B2Brouter driver
     * @throws InvalidRecord when the written record fails the schema
     * @throws ChainConflict when the chain is closed or the head moved during the write
     */
    public function correct(Invoice $invoice, ?string $reference = null): VerifactuRecord
    {
        $settings = $this->settings();
        $schemas = $this->schemasFor($settings);
        $draft = (new RecordMapper)->map($invoice);
        $context = $this->contextFor($draft->id->issuer, $settings);
        $this->assertNoOpenChainOfOtherDriver($context);

        if ($draft->operation === AltaOperation::Initial) {
            throw new InvalidArgumentException(sprintf(
                'Invoice %s carries AltaOperation::Initial; correct() needs the correction operation in SpanishInvoice::$operation. Use register() for a first submission.',
                $draft->id->number,
            ));
        }
        $this->assertAeatDriver($context, 'Corrections');

        return $this->appendAeat($settings, $schemas, $context, $draft, $reference, function (ChainKey $key) use ($draft): void {
            if (! $this->hasRegistration($key, $draft->id)) {
                throw new InvalidArgumentException(sprintf(
                    'Invoice %s of %s has no earlier registration record in chain %s to correct. Use register() for a first submission.',
                    $draft->id->number,
                    $draft->id->dateText(),
                    $key->toString(),
                ));
            }
        });
    }

    /**
     * The latest record row of an invoice in the configured environment, of either kind and
     * driver; null when there is none.
     *
     * @throws ConfigurationException when a setting is invalid
     */
    public function record(InvoiceId $invoice): ?VerifactuRecord
    {
        return self::invoiceRows($invoice, $this->settings()->environment->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['schemasChecked' => $this->schemas !== null];
    }

    /**
     * @throws ConfigurationException when the set is not installed or does not match
     */
    private function schemasFor(VerifactuSettings $settings): VerifactuSchemas
    {
        $dir = $settings->schemasDir;
        if ($this->schemas !== null && $this->schemasDir === $dir) {
            return $this->schemas;
        }

        $schemas = new VerifactuSchemas($dir);
        try {
            $schemas->check();
        } catch (RulesNotInstalled $exception) {
            throw new ConfigurationException(
                'The AEAT VERI*FACTU schemas are not installed in "'.$dir.'" ('.$exception->getMessage().'). Run: php artisan einvoicing:verifactu-install-schemas',
                0,
                $exception,
            );
        }
        $this->schemasDir = $dir;

        return $this->schemas = $schemas;
    }

    private function storeFor(VerifactuSettings $settings, VerifactuSchemas $schemas, ComputerSystem $system): DatabaseChainStore
    {
        $scope = $settings->throttleScope;

        return new DatabaseChainStore(
            ($this->app)()->make('db')->connection(),
            new RecordWriter($schemas),
            static fn (ChainKey $key): ThrottleKey => self::throttleKey($system, $key, $scope),
        );
    }

    private function chainerFor(VerifactuSettings $settings, ComputerSystem $system): Chainer
    {
        return new Chainer($system, self::now(...), $settings->timezone);
    }

    /**
     * Resolves the issuer and checks that the context is the invoice issuer's, for the configured
     * environment.
     *
     * @throws UnknownIssuer when the resolver has no data for the NIF
     * @throws ConfigurationException when the context names another issuer or environment
     */
    private function contextFor(Nif $issuer, VerifactuSettings $settings): IssuerContext
    {
        $context = $this->resolver()->resolve($issuer);

        if ($context->nif->value !== $issuer->value) {
            throw new ConfigurationException(sprintf(
                'The issuer resolver returned issuer %s for issuer %s.',
                $context->nif->value,
                $issuer->value,
            ));
        }
        if ($context->environment !== $settings->environment) {
            throw new ConfigurationException(sprintf(
                'The issuer resolver returned issuer %s for the %s environment, but einvoicing.verifactu.environment is %s.',
                $issuer->value,
                $context->environment->value,
                $settings->environment->value,
            ));
        }

        return $context;
    }

    /**
     * Refuses to write while the issuer has an open chain of the other driver in the environment.
     * A chain row that never held a record or a seed is no chain.
     *
     * @throws ConfigurationException naming the command that closes the other chain
     */
    private function assertNoOpenChainOfOtherDriver(IssuerContext $context): void
    {
        $open = VerifactuChain::query()
            ->where('issuer_nif', $context->nif->value)
            ->where('environment', $context->environment->value)
            ->where('driver', '!=', $context->driver->value)
            ->whereNull('closed_at')
            ->where(static fn (Builder $query) => $query->where('sequence', '>', 0)->orWhereNotNull('head_hash'))
            ->orderBy('id')
            ->first();

        if ($open !== null) {
            throw new ConfigurationException(sprintf(
                'Issuer %s uses the %s driver, but its %s chain of installation "%s" in the %s environment is still open. Close that chain first: php artisan einvoicing:verifactu-close-chain --issuer=%s --driver=%s',
                $context->nif->value,
                $context->driver->value,
                $open->driver->value,
                $open->installation_number,
                $context->environment->value,
                $context->nif->value,
                $open->driver->value,
            ));
        }
    }

    /**
     * @throws LogicException for an issuer on the B2Brouter driver
     */
    private function assertAeatDriver(IssuerContext $context, string $what): void
    {
        if ($context->driver === IssuerDriver::B2Brouter) {
            throw new LogicException(sprintf(
                '%s of issuer %s are not supported on the B2Brouter driver; manage them in B2Brouter.',
                $what,
                $context->nif->value,
            ));
        }
    }

    /**
     * @throws LogicException always: the B2Brouter path is not available in this build
     */
    private function registerWithB2Brouter(RegistrationDraft $draft, IssuerContext $context, ?string $reference): VerifactuRecord
    {
        throw new LogicException(sprintf('Registering invoice %s of issuer %s through the B2Brouter driver is not available.', $draft->id->number, $context->nif->value));
    }

    /**
     * Appends a record to the issuer's AEAT chain and queues its submission.
     *
     * $guard runs under the chain lock before the record is built, so its checks of the stored
     * records cannot race another writer of the chain.
     *
     * @param  Closure(ChainKey): void  $guard
     */
    private function appendAeat(
        VerifactuSettings $settings,
        VerifactuSchemas $schemas,
        IssuerContext $context,
        RegistrationDraft|CancellationDraft $draft,
        ?string $reference,
        Closure $guard,
    ): VerifactuRecord {
        $system = $settings->system();
        $key = $context->chainKey($system);
        $throttle = self::throttleKey($system, $key, $settings->throttleScope);
        $chainer = $this->chainerFor($settings, $system);

        $row = $this->storeFor($settings, $schemas, $system)->appendRow(
            $key,
            IssuerDriver::Aeat,
            $throttle,
            static function (?ChainHead $head) use ($guard, $key, $chainer, $draft): BillingRecord {
                $guard($key);

                return $chainer->append($draft, $head, self::now())[0];
            },
            ['reference' => $reference],
        );

        VerifactuRecordGenerated::dispatch($row);
        SubmitVerifactuRecords::schedule($throttle->toString());

        return $row;
    }

    /** Whether the chain holds a registration record of the invoice. */
    private function hasRegistration(ChainKey $key, InvoiceId $invoice): bool
    {
        return self::invoiceRows($invoice, $key->environment->value)
            ->where('kind', VerifactuRecordKind::Registration->value)
            ->whereHas('chain', static fn (Builder $query) => $query
                ->where('installation_number', $key->installationNumber)
                ->where('driver', IssuerDriver::Aeat->value))
            ->exists();
    }

    /**
     * @return Builder<VerifactuRecord>
     */
    private static function invoiceRows(InvoiceId $invoice, string $environment): Builder
    {
        return VerifactuRecord::query()
            ->where('issuer_nif', $invoice->issuer->value)
            ->where('number', $invoice->number)
            ->where('issue_date', $invoice->issueDate->format('Y-m-d'))
            ->where('environment', $environment);
    }

    private static function throttleKey(ComputerSystem $system, ChainKey $key, ThrottleScope $scope): ThrottleKey
    {
        return ThrottleKey::for($system, $key->environment, $scope, $scope === ThrottleScope::Issuer ? $key->issuer : null);
    }

    private static function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
