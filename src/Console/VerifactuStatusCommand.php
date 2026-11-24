<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordKind;
use IntrAgile\EInvoicing\Laravel\Enums\VerifactuRecordState;
use IntrAgile\EInvoicing\Laravel\Events\VerifactuRecordAccepted;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuClients;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuSettings;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationRecord;
use IntrAgile\EInvoicing\Syntax\Verifactu\RecordReader;
use IntrAgile\EInvoicing\Transport\Aeat\LookupRequest;
use IntrAgile\EInvoicing\Transport\Aeat\LookupState;
use IntrAgile\EInvoicing\Transport\Aeat\RecordStatus;
use IntrAgile\EInvoicing\Transport\Exception\TransportException;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Prints the stored VERI*FACTU records of one invoice of the configured environment: the state,
 * the CSV, the error code and message, the Huella and the QR URL, for each registration and
 * cancellation record.
 *
 * With --lookup, the latest record is checked against its service; nothing is ever submitted:
 * - AEAT driver: the invoice is queried (ConsultaFactuSistemaFacturacion). A record left
 *   unconfirmed is settled when AEAT holds the very same record (the same Huella; for a
 *   cancellation, the invoice is cancelled): it becomes accepted (accepted_with_errors when AEAT
 *   accepted it with errors) without a CSV, which AEAT cannot return later, and
 *   VerifactuRecordAccepted fires. Any other answer leaves the row as it is;
 * - B2Brouter driver: the record's tax report is read again and printed; the row is not changed
 *   (einvoicing:verifactu-submit --retry-unconfirmed polls it again).
 *
 * A failed lookup (no answer, an authentication fault, rate limiting, an invalid query) is printed
 * and leaves the row unchanged; the exit code is then 1, as it is when no record matches.
 */
final class VerifactuStatusCommand extends Command
{
    use InteractsWithVerifactu;

    protected $signature = 'einvoicing:verifactu-status
        {number : The invoice number (NumSerieFactura)}
        {--date= : The issue date, Y-m-d}
        {--issuer= : The issuer NIF; defaults to the only configured issuer}
        {--lookup : Check the latest record with AEAT or B2Brouter (never a submission)}';

    protected $description = 'Show the VERI*FACTU records of an invoice, optionally checking them with AEAT or B2Brouter';

    public function handle(VerifactuManager $verifactu, VerifactuClients $clients): int
    {
        try {
            $settings = $verifactu->settings();
            $issuer = $this->requiredIssuer($verifactu);
            $dateOption = $this->option('date');
            $date = is_string($dateOption) && $dateOption !== '' ? self::date($dateOption, '--date')->format('Y-m-d') : null;
        } catch (ConfigurationException|InvalidArgumentException $exception) {
            $this->components->error(self::escape($exception->getMessage()));

            return self::FAILURE;
        }
        $number = $this->stringArgument('number');

        $rows = VerifactuRecord::query()
            ->where('issuer_nif', $issuer->value)
            ->where('number', $number)
            ->where('environment', $settings->environment->value)
            ->when($date !== null, static fn ($query) => $query->where('issue_date', $date))
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->error(self::escape(sprintf(
                'No VERI*FACTU record of invoice %s%s of issuer %s in the %s environment.',
                $number,
                $date === null ? '' : ' of '.$date,
                $issuer->value,
                $settings->environment->value,
            )));

            return self::FAILURE;
        }
        $dates = $rows->pluck('issue_date')->unique()->values();
        if ($dates->count() > 1) {
            $this->components->error(self::escape(sprintf('Invoice %s of issuer %s has records for the issue dates %s; pass --date=<Y-m-d>.', $number, $issuer->value, $dates->implode(', '))));

            return self::FAILURE;
        }

        $this->printRows($rows);

        if ($this->option('lookup') !== true) {
            return self::SUCCESS;
        }

        $latest = $rows->last();
        if ($latest === null) {
            return self::SUCCESS;
        }

        return $latest->driver === IssuerDriver::B2Brouter
            ? $this->lookupB2Brouter($clients, $latest)
            : $this->lookupAeat($verifactu, $clients, $settings, $issuer, $latest);
    }

    /**
     * @param  Collection<int, VerifactuRecord>  $rows
     */
    private function printRows(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->newLine();
            $this->line(sprintf(
                '<info>Record row %d</info>: %s, %s driver%s',
                $row->id,
                $row->kind === VerifactuRecordKind::Cancellation ? 'cancellation (RegistroAnulacion)' : 'registration (RegistroAlta)',
                $row->driver->value,
                $row->sequence === null ? '' : ', chain sequence '.$row->sequence,
            ));
            $this->detail('Invoice', sprintf('%s of %s, issuer %s', $row->number, $row->issue_date, $row->issuer_nif));
            $this->detail('State', $row->state->value);
            $this->detail('CSV', $row->csv);
            $this->detail('Error code', $row->error_code);
            $this->detail('Error message', $row->error_message);
            $this->detail('Error action', $row->error_action);
            $this->detail('Attempts', (string) $row->attempts);
            $this->detail('Submitted at', $row->submitted_at?->toIso8601String());
            $this->detail('Generated at', $row->generated_at);
            $this->detail('Huella', $row->hash);
            $this->detail('Previous', $row->previous_hash);
            $this->detail('QR URL', $row->qr_url);
            $this->detail('Reference', $row->reference);
            if ($row->driver === IssuerDriver::B2Brouter) {
                $this->detail('B2Brouter invoice', $row->b2brouter_invoice_id);
                $this->detail('B2Brouter report', $row->b2brouter_report_id);
                $summary = $row->cross_check['summary'] ?? null;
                $this->detail('Cross-check', is_string($summary) ? $summary : null);
            }
        }
    }

    private function lookupAeat(VerifactuManager $verifactu, VerifactuClients $clients, VerifactuSettings $settings, Nif $issuer, VerifactuRecord $row): int
    {
        try {
            $context = $verifactu->resolver()->resolve($issuer);
            if ($context->environment !== $settings->environment || $context->environment !== $row->environment) {
                throw new ConfigurationException(sprintf(
                    'The issuer resolver returned issuer %s for the %s environment, but einvoicing.verifactu.environment is %s.',
                    $issuer->value,
                    $context->environment->value,
                    $settings->environment->value,
                ));
            }
            $status = $clients->aeat($context)->lookup(new LookupRequest($issuer, $context->name, $row->invoiceId()));
        } catch (TransportException|InvalidRecord|ConfigurationException|UnknownIssuer $exception) {
            $this->components->error(self::escape('The AEAT lookup failed; the record is unchanged. '.$exception->getMessage()));

            return self::FAILURE;
        }

        $this->newLine();
        if ($status === null) {
            $this->components->info('AEAT holds no record for the invoice; the record is unchanged.');

            return self::SUCCESS;
        }

        $this->line('<info>AEAT lookup</info>:');
        $this->detail('State', $status->state->value);
        $this->detail('Huella', $status->fingerprint);
        $this->detail('Error code', $status->errorCode === null ? null : (string) $status->errorCode);
        $this->detail('Error message', $status->errorDescription);
        $this->detail('Last change', $status->lastModified?->format(DATE_ATOM));

        if ($row->state !== VerifactuRecordState::Unconfirmed) {
            return self::SUCCESS;
        }

        return $this->reconcile($row, $status);
    }

    /**
     * Settles an unconfirmed record when AEAT holds that very record.
     */
    private function reconcile(VerifactuRecord $row, RecordStatus $status): int
    {
        try {
            $record = (new RecordReader)->read((string) $row->xml);
        } catch (InvalidRecord|UnsupportedDocument|UnsafeXml $exception) {
            $this->components->error(self::escape('The stored record cannot be read, so it cannot be compared: '.$exception->getMessage()));

            return self::FAILURE;
        }

        $held = $row->kind === VerifactuRecordKind::Cancellation
            ? $status->confirmsCancellation()
            : $record instanceof RegistrationRecord && $status->matches($record);
        if (! $held) {
            $this->components->warn(self::escape(sprintf(
                'AEAT holds another record for the invoice (Huella %s, state %s); the record stays unconfirmed.',
                $status->fingerprint ?? 'none',
                $status->state->value,
            )));

            return self::SUCCESS;
        }

        $withErrors = $row->kind === VerifactuRecordKind::Registration && $status->state === LookupState::AceptadoConErrores;
        $code = $withErrors && $status->errorCode !== null ? (string) $status->errorCode : null;
        $settled = VerifactuRecord::query()
            ->whereKey($row->id)
            ->where('state', VerifactuRecordState::Unconfirmed->value)
            ->update([
                'state' => ($withErrors ? VerifactuRecordState::AcceptedWithErrors : VerifactuRecordState::Accepted)->value,
                'error_code' => $code,
                'error_message' => $code === null ? null : $status->errorDescription,
                'error_action' => null,
                'updated_at' => Carbon::now(),
            ]);
        if ($settled !== 1) {
            $this->components->warn('The record was settled by another process meanwhile; it is unchanged.');

            return self::SUCCESS;
        }

        $row->refresh();
        VerifactuRecordAccepted::dispatch($row, $withErrors, $code);
        $this->components->info(self::escape(sprintf('AEAT holds this record: it is now %s (without a CSV, which AEAT does not return later).', $row->state->value)));

        return self::SUCCESS;
    }

    private function lookupB2Brouter(VerifactuClients $clients, VerifactuRecord $row): int
    {
        $this->newLine();
        if ($row->b2brouter_report_id === null) {
            $this->components->warn('The record has no B2Brouter tax report id yet; nothing to read.');

            return self::SUCCESS;
        }

        try {
            $report = $clients->b2brouter()->taxReport($row->b2brouter_report_id);
        } catch (TransportException|UnsupportedDocument|InvalidArgumentException|ConfigurationException $exception) {
            $this->components->error(self::escape('Reading the B2Brouter tax report failed; the record is unchanged. '.$exception->getMessage()));

            return self::FAILURE;
        }

        $this->line('<info>B2Brouter tax report</info>:');
        $this->detail('Report', $report->id);
        $this->detail('State', $report->state.($report->isFinal() ? ' (final)' : ''));
        $this->detail('Huella', $report->fingerprint);
        if ($report->fingerprint !== null && $row->hash !== null) {
            $this->detail('Stored Huella', $report->fingerprint === $row->hash ? 'the same' : 'differs: '.$row->hash);
        }

        return self::SUCCESS;
    }

    private function detail(string $label, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->line(sprintf('  %-18s %s', $label, OutputFormatter::escape($value)));
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
