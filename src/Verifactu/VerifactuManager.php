<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordGenerated;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordRejected;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollVerifactuTaxReport;
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
use IntrAgile\EInvoicing\Syntax\Ubl\UblWriter;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordWriter;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleKey;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\IssuedInvoice;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Exception\OutcomeUnknown;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use IntrAgile\EInvoicing\Transport\Exception\UnexpectedResponse;
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
 * For an issuer on the B2Brouter driver, register() imports the invoice into B2Brouter, which
 * generates, chains and submits the record; PollVerifactuTaxReport attaches that record to its
 * B2Brouter chain once AEAT registered it. Cancellations and corrections are managed in B2Brouter.
 * The installation number of a B2Brouter chain is the one B2Brouter writes, derived from the
 * account id; chain rows, error messages and logs that name the chain carry it.
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
     * For an issuer on the B2Brouter driver, the invoice is imported into B2Brouter instead, which
     * generates and submits the record; the returned row is pending, without a QR URL, until
     * PollVerifactuTaxReport attaches B2Brouter's record (see registerWithB2Brouter()).
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
     *                                  (on the B2Brouter driver: was imported, or its import is
     *                                  unconfirmed)
     * @throws OutcomeUnknown on the B2Brouter driver, when the import ended without an answer; the
     *                        row is stored unconfirmed
     * @throws TransportException on the B2Brouter driver, when B2Brouter refused the import or the
     *                            request; nothing is stored
     * @throws InvalidRecord when the written record fails the schema
     * @throws ChainConflict when the chain is closed or the head moved during the write
     */
    public function register(Invoice $invoice, ?string $reference = null): VerifactuRecord
    {
        $settings = $this->settings();
        $schemas = $this->schemasFor($settings);
        $draft = (new RecordMapper)->map($invoice);
        $context = $this->contextFor($draft->id->issuer, $settings);
        // Runs outside the chain lock: a driver switch racing this call is still refused by the
        // store, which takes no record into a chain of the other driver.
        $this->assertNoOpenChainOfOtherDriver($context);

        if ($draft->operation !== AltaOperation::Initial) {
            throw new InvalidArgumentException(sprintf(
                'Invoice %s carries the operation AltaOperation::%s; register() takes only AltaOperation::Initial. Use correct() for a correction or a new submission after a rejection.',
                $draft->id->number,
                $draft->operation->name,
            ));
        }

        if ($context->driver === IssuerDriver::B2Brouter) {
            return $this->registerWithB2Brouter($invoice, $draft, $context, $reference);
        }

        return $this->appendAeat($settings, $schemas, $context, $draft, $reference, function (ChainKey $key) use ($draft): void {
            if ($this->chainRows($key, $draft->id)->contains(static fn (VerifactuRecord $row): bool => $row->kind === VerifactuRecordKind::Registration)) {
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
     * The stored records of the invoice in the chain are checked where they settle the answer;
     * records AEAT has not answered yet (generated, sending, unconfirmed) never refuse a call:
     * - a cancellation of a record AEAT holds (Normal, AfterRejection) needs a registration record
     *   that AEAT has not rejected; for an invoice AEAT never received, pass
     *   AnulacionOperation::WithoutPriorRecord;
     * - a cancellation of a record AEAT never received is refused while AEAT accepted one;
     * - nothing is cancelled again once AEAT accepted a cancellation after the latest registration.
     *
     * An invoice registered by the software used before the chain was seeded has no local record:
     * pass $registeredByPreviousSystem = true to cancel it (Normal or AfterRejection), which is
     * accepted only on a seeded chain.
     *
     * @param  string|null  $reference  the application's own reference, stored on the row
     * @param  bool  $registeredByPreviousSystem  the invoice was registered by the previous software
     *                                            of a seeded chain, so no local registration exists
     *
     * @throws ConfigurationException as register()
     * @throws UnknownIssuer when the resolver has no data for the issuer
     * @throws InvalidArgumentException when the stored records refuse the operation (see above)
     * @throws LogicException for an issuer on the B2Brouter driver
     * @throws ChainConflict when the chain is closed or the head moved during the write
     */
    public function cancel(
        InvoiceId $invoice,
        ?string $reference = null,
        AnulacionOperation $operation = AnulacionOperation::Normal,
        bool $registeredByPreviousSystem = false,
    ): VerifactuRecord {
        $settings = $this->settings();
        $schemas = $this->schemasFor($settings);
        $context = $this->contextFor($invoice->issuer, $settings);
        $this->assertNoOpenChainOfOtherDriver($context);
        $this->assertAeatDriver($context, 'Cancellations');

        $draft = CancellationDraft::of($invoice, $operation);

        return $this->appendAeat($settings, $schemas, $context, $draft, $reference, function (ChainKey $key) use ($invoice, $operation, $registeredByPreviousSystem): void {
            $this->assertCancellable($key, $invoice, $operation, $registeredByPreviousSystem);
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
     * @throws InvalidArgumentException when the operation is Initial, the IDFactura has no earlier
     *                                  registration record in the chain, or the latest one settles
     *                                  the operation otherwise (a new submission after a rejection
     *                                  while AEAT accepted it, a correction while AEAT rejected it)
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
            $this->assertCorrectable($key, $draft);
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
     * Imports the invoice into B2Brouter, which generates, chains and submits its record, and
     * stores a pending row whose tax report PollVerifactuTaxReport then polls.
     *
     * Nothing is stored when the import fails before or with an answer: the invoice cannot be
     * written as UBL, the client is not configured, or B2Brouter refused it. An import without an
     * answer (OutcomeUnknown) may have gone through, so the row is stored unconfirmed, without a
     * B2Brouter invoice id, and the exception is rethrown; retryB2BrouterImport() settles it.
     *
     * @throws InvalidArgumentException when the invoice was imported already, or its import is unconfirmed
     * @throws ConfigurationException when no B2Brouter transport is configured
     * @throws OutcomeUnknown when the import ended without an answer; the row is stored unconfirmed
     * @throws TransportException when B2Brouter refused the import (DocumentRejected, for a taken
     *                            number too) or the request; nothing is stored
     */
    private function registerWithB2Brouter(Invoice $invoice, RegistrationDraft $draft, IssuerContext $context, ?string $reference): VerifactuRecord
    {
        $this->assertNotImported($draft->id, $context);
        $ubl = (new UblWriter)->write($invoice);
        $client = $this->clients()->b2brouter();

        $columns = [
            'kind' => VerifactuRecordKind::Registration,
            'issuer_nif' => $draft->id->issuer->value,
            'number' => $draft->id->number,
            'issue_date' => $draft->id->issueDate->format('Y-m-d'),
            'environment' => $context->environment,
            'driver' => IssuerDriver::B2Brouter,
            'reference' => $reference,
            'source' => $ubl,
            'source_extension' => SourceInvoice::extensionOf($invoice),
            'attempts' => 1,
            'submitted_at' => Carbon::now(),
        ];

        try {
            $issued = $client->issue($ubl);
        } catch (OutcomeUnknown $exception) {
            VerifactuRecord::query()->forceCreate([
                ...$columns,
                'state' => VerifactuRecordState::Unconfirmed,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $row = VerifactuRecord::query()->forceCreate([
            ...$columns,
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => $issued->invoiceId,
            'b2brouter_report_id' => $issued->latestTaxReportId(),
        ]);
        PollVerifactuTaxReport::schedule($row->id);

        return $row;
    }

    /**
     * Settles a B2Brouter record whose import ended without an answer (state unconfirmed, no
     * B2Brouter invoice id), without importing the invoice twice.
     *
     * The row is claimed first with a conditional update (its attempts move on by one), so two
     * concurrent retries cannot both import: the one that loses the claim is refused. The invoice is
     * then looked up by its number:
     * - B2Brouter holds an invoice with the number: it is adopted only when the record of its
     *   current tax report names the same IDFactura (issuer, number, issue date); its ids are
     *   stored, the row becomes pending and its tax report is polled. Without a tax report yet, or
     *   with another IDFactura, the row stays unconfirmed with the reason in error_message;
     * - B2Brouter holds none: the stored source is imported once more;
     * - the lookup cannot decide: the row stays unconfirmed with the reason, nothing is imported.
     *
     * @throws InvalidArgumentException when the row is not an unconfirmed B2Brouter import, or
     *                                  another retry claimed it first
     * @throws ConfigurationException when no B2Brouter transport is configured
     * @throws OutcomeUnknown when the new import ended without an answer; the row stays unconfirmed
     * @throws DocumentRejected when B2Brouter refused the new import; the row is failed
     * @throws TransportException when a request failed otherwise; the row stays unconfirmed
     */
    public function retryB2BrouterImport(VerifactuRecord $row): VerifactuRecord
    {
        if ($row->driver !== IssuerDriver::B2Brouter || $row->state !== VerifactuRecordState::Unconfirmed || $row->b2brouter_invoice_id !== null) {
            throw new InvalidArgumentException(sprintf('The VERI*FACTU record row %d is not a B2Brouter import whose outcome is unknown.', $row->id));
        }
        $client = $this->clients()->b2brouter();

        $claimed = VerifactuRecord::query()
            ->whereKey($row->id)
            ->where('state', VerifactuRecordState::Unconfirmed->value)
            ->whereNull('b2brouter_invoice_id')
            ->where('attempts', $row->attempts)
            ->toBase()
            ->update(['attempts' => $row->attempts + 1, 'updated_at' => Carbon::now()]);
        if ($claimed !== 1) {
            throw new InvalidArgumentException(sprintf('The VERI*FACTU record row %d was settled or claimed by another retry meanwhile.', $row->id));
        }
        $row->refresh();

        try {
            $issued = $client->findByNumber($row->number);
        } catch (UnexpectedResponse $exception) {
            $row->forceFill(['error_message' => $exception->getMessage()])->save();

            return $row;
        }

        if ($issued !== null) {
            $mismatch = $this->importedMismatch($client, $issued, $row);
            if ($mismatch !== null) {
                $row->forceFill(['error_message' => $mismatch])->save();

                return $row;
            }
        } else {
            try {
                $issued = $client->issue((string) $row->source);
            } catch (OutcomeUnknown $exception) {
                $row->forceFill(['error_message' => $exception->getMessage(), 'submitted_at' => Carbon::now()])->save();

                throw $exception;
            } catch (DocumentRejected $exception) {
                $row->forceFill(['state' => VerifactuRecordState::Failed, 'error_code' => 'rejected', 'error_message' => $exception->getMessage()])->save();
                VerifactuRecordRejected::dispatch($row, 'rejected', $exception->getMessage());

                throw $exception;
            }
            $row->forceFill(['submitted_at' => Carbon::now()]);
        }

        $row->forceFill([
            'state' => VerifactuRecordState::Pending,
            'b2brouter_invoice_id' => $issued->invoiceId,
            'b2brouter_report_id' => $issued->latestTaxReportId(),
            'error_message' => null,
        ])->save();
        PollVerifactuTaxReport::schedule($row->id);

        return $row;
    }

    /**
     * Why the invoice B2Brouter holds under the row's number cannot be taken as the row's import,
     * or null when the record of its current tax report names the row's IDFactura.
     *
     * @throws TransportException when B2Brouter refused the request
     */
    private function importedMismatch(B2BrouterVerifactu $client, IssuedInvoice $issued, VerifactuRecord $row): ?string
    {
        $reportId = $issued->latestTaxReportId();
        if ($reportId === null) {
            return sprintf(
                'B2Brouter holds invoice %s with number %s, but lists no tax report for it yet, so its issuer and issue date cannot be checked. Retry later.',
                $issued->invoiceId,
                $row->number,
            );
        }

        try {
            $theirs = $client->taxReport($reportId)->record()->invoiceId();
        } catch (UnexpectedResponse|UnsupportedDocument|UnsafeXml|InvalidRecord $exception) {
            return sprintf('The tax report %s of B2Brouter invoice %s cannot be read, so the invoice cannot be checked: %s', $reportId, $issued->invoiceId, $exception->getMessage());
        }

        $ours = $row->invoiceId();
        if ($theirs->equals($ours)) {
            return null;
        }

        return sprintf(
            'B2Brouter holds invoice %s with number %s, but its record names issuer %s, number %s, issue date %s instead of issuer %s, issue date %s; it is not adopted.',
            $issued->invoiceId,
            $row->number,
            $theirs->issuer->value,
            $theirs->number,
            $theirs->dateText(),
            $ours->issuer->value,
            $ours->dateText(),
        );
    }

    /**
     * Refuses a second import of an invoice: B2Brouter holds it already, or an import of it ended
     * without an answer and may have gone through.
     *
     * @throws InvalidArgumentException
     */
    private function assertNotImported(InvoiceId $invoice, IssuerContext $context): void
    {
        $existing = self::invoiceRows($invoice, $context->environment->value)
            ->where('driver', IssuerDriver::B2Brouter->value)
            ->where(static fn (Builder $query) => $query
                ->whereNotNull('b2brouter_invoice_id')
                ->orWhere('state', VerifactuRecordState::Unconfirmed->value))
            ->orderByDesc('id')
            ->first();

        if ($existing === null) {
            return;
        }
        $name = sprintf('Invoice %s of %s', $invoice->number, $invoice->dateText());
        if ($existing->b2brouter_invoice_id !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s was imported into B2Brouter already (B2Brouter invoice %s, record row %d); B2Brouter does not import it twice.',
                $name,
                $existing->b2brouter_invoice_id,
                $existing->id,
            ));
        }

        throw new InvalidArgumentException(sprintf(
            '%s has a B2Brouter import whose outcome is unknown (record row %d), so it may have been imported. Settle it with VerifactuManager::retryB2BrouterImport(), which looks it up before importing again.',
            $name,
            $existing->id,
        ));
    }

    private function clients(): VerifactuClients
    {
        return ($this->app)()->make(VerifactuClients::class);
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

    /**
     * Refuses a cancellation the stored records of the invoice settle against.
     *
     * @throws InvalidArgumentException
     */
    private function assertCancellable(ChainKey $key, InvoiceId $invoice, AnulacionOperation $operation, bool $registeredByPreviousSystem): void
    {
        $rows = $this->chainRows($key, $invoice);
        $registrations = $rows->filter(static fn (VerifactuRecord $row): bool => $row->kind === VerifactuRecordKind::Registration);
        $latest = $registrations->last()->sequence ?? 0;
        $name = sprintf('Invoice %s of %s', $invoice->number, $invoice->dateText());

        $cancelled = $rows->contains(static fn (VerifactuRecord $row): bool => $row->kind === VerifactuRecordKind::Cancellation
            && $row->sequence > $latest
            && self::isAccepted($row));
        if ($cancelled) {
            throw new InvalidArgumentException(sprintf('%s is already cancelled: AEAT accepted its cancellation record in chain %s.', $name, $key->toString()));
        }

        if ($operation->sinRegistroPrevio() !== null) {
            if ($registrations->contains(self::isAccepted(...))) {
                throw new InvalidArgumentException(sprintf(
                    '%s has a registration record AEAT accepted in chain %s; cancel it with AnulacionOperation::Normal, not AnulacionOperation::%s.',
                    $name,
                    $key->toString(),
                    $operation->name,
                ));
            }

            return;
        }

        if ($registrations->isEmpty()) {
            if (! $registeredByPreviousSystem) {
                throw new InvalidArgumentException(sprintf(
                    '%s has no registration record in chain %s. To cancel an invoice AEAT never received, pass AnulacionOperation::WithoutPriorRecord; for an invoice the previous software registered before the chain was seeded, pass registeredByPreviousSystem: true.',
                    $name,
                    $key->toString(),
                ));
            }
            if (! $this->isSeeded($key)) {
                throw new InvalidArgumentException(sprintf(
                    '%s has no registration record in chain %s, and the chain is not seeded, so no previous software registered it: registeredByPreviousSystem applies only to a seeded chain. To cancel an invoice AEAT never received, pass AnulacionOperation::WithoutPriorRecord.',
                    $name,
                    $key->toString(),
                ));
            }

            return;
        }

        if ($registrations->every(static fn (VerifactuRecord $row): bool => $row->state === VerifactuRecordState::Rejected)) {
            throw new InvalidArgumentException(sprintf(
                '%s has only registration records AEAT rejected in chain %s, so AEAT holds none. Pass AnulacionOperation::WithoutPriorRecord.',
                $name,
                $key->toString(),
            ));
        }
    }

    /**
     * Refuses a correction without an earlier registration record, or one its latest registration
     * record settles against.
     *
     * @throws InvalidArgumentException
     */
    private function assertCorrectable(ChainKey $key, RegistrationDraft $draft): void
    {
        $latest = $this->chainRows($key, $draft->id)
            ->filter(static fn (VerifactuRecord $row): bool => $row->kind === VerifactuRecordKind::Registration)
            ->last();
        $name = sprintf('Invoice %s of %s', $draft->id->number, $draft->id->dateText());

        if ($latest === null) {
            throw new InvalidArgumentException(sprintf(
                '%s has no earlier registration record in chain %s to correct. Use register() for a first submission.',
                $name,
                $key->toString(),
            ));
        }

        $afterRejection = $draft->operation->rechazoPrevio() !== null;
        if ($afterRejection && self::isAccepted($latest)) {
            throw new InvalidArgumentException(sprintf(
                '%s: AEAT accepted its latest registration record, so AltaOperation::%s does not apply. Use AltaOperation::Correction.',
                $name,
                $draft->operation->name,
            ));
        }
        if (! $afterRejection && $latest->state === VerifactuRecordState::Rejected) {
            throw new InvalidArgumentException(sprintf(
                '%s: AEAT rejected its latest registration record, so AltaOperation::%s does not apply. Use AltaOperation::AfterRejection (or CorrectionAfterRejection after a rejected correction).',
                $name,
                $draft->operation->name,
            ));
        }
    }

    /**
     * The records of the invoice in the AEAT chain of the key, in chain order.
     *
     * @return Collection<int, VerifactuRecord>
     */
    private function chainRows(ChainKey $key, InvoiceId $invoice): Collection
    {
        return self::invoiceRows($invoice, $key->environment->value)
            ->whereHas('chain', static fn (Builder $query) => $query
                ->where('installation_number', $key->installationNumber)
                ->where('driver', IssuerDriver::Aeat->value))
            ->orderBy('sequence')
            ->get();
    }

    /** Whether the chain of the key was started from a seed (read under the chain lock). */
    private function isSeeded(ChainKey $key): bool
    {
        return VerifactuChain::query()
            ->where('issuer_nif', $key->issuer->value)
            ->where('installation_number', $key->installationNumber)
            ->where('environment', $key->environment->value)
            ->where('seeded', true)
            ->exists();
    }

    private static function isAccepted(VerifactuRecord $row): bool
    {
        return $row->state === VerifactuRecordState::Accepted || $row->state === VerifactuRecordState::AcceptedWithErrors;
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
