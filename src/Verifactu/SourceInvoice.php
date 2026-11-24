<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use DateTimeImmutable;
use IntrAgile\EInvoicing\CodeList\CountryCode;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Spain\BreakdownOverride;
use IntrAgile\EInvoicing\Spain\CorrectionType;
use IntrAgile\EInvoicing\Spain\EuroAmounts;
use IntrAgile\EInvoicing\Spain\ExemptOperation;
use IntrAgile\EInvoicing\Spain\ForeignIdType;
use IntrAgile\EInvoicing\Spain\InvoiceType;
use IntrAgile\EInvoicing\Spain\IssuedBy;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\OperationQualification;
use IntrAgile\EInvoicing\Spain\RegimeKey;
use IntrAgile\EInvoicing\Spain\SpanishInvoice;
use IntrAgile\EInvoicing\Spain\TaxType;
use IntrAgile\EInvoicing\Spain\Verifactu\AltaOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\Amount;
use IntrAgile\EInvoicing\Spain\Verifactu\CorrectionAmounts;
use IntrAgile\EInvoicing\Spain\Verifactu\ForeignId;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxParty;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxRate;
use IntrAgile\EInvoicing\Syntax\Ubl\UblReader;
use JsonException;
use Throwable;
use UnexpectedValueException;

/**
 * The invoice a B2Brouter record row was imported from, kept as the UBL sent (source) plus its
 * SpanishInvoice extension (source_extension), which UBL cannot carry: without it, an exempt line
 * loses its exemption cause and a corrective invoice its type, and our own mapping of the invoice
 * would refuse what B2Brouter registered.
 *
 * The extension is stored as explicit JSON: every field of SpanishInvoice by name, amounts and
 * rates as their literal text, enums as their values (AltaOperation, which has none, by its case
 * name) and dates as Y-m-d.
 */
final class SourceInvoice
{
    /** The version of the stored JSON layout. */
    private const VERSION = 1;

    /** The stored form of the invoice's SpanishInvoice extension, or null when it has none. */
    public static function extensionOf(Invoice $invoice): ?string
    {
        $spanish = $invoice->extensions()->get(SpanishInvoice::class);

        return $spanish instanceof SpanishInvoice
            ? json_encode(self::toArray($spanish), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }

    /**
     * The invoice of the row: its source read as UBL, with the stored SpanishInvoice extension.
     *
     * @throws UnsupportedDocument when the row has no source or the source is not a readable UBL invoice
     * @throws UnsafeXml when the source carries a DOCTYPE declaration
     * @throws UnexpectedValueException when the stored extension cannot be read back
     */
    public static function of(VerifactuRecord $row): Invoice
    {
        if ($row->source === null || $row->source === '') {
            throw new UnsupportedDocument(sprintf('The VERI*FACTU record row %d has no source invoice.', $row->id));
        }
        $invoice = (new UblReader)->read($row->source)->invoice;
        if ($row->source_extension === null || $row->source_extension === '') {
            return $invoice;
        }

        try {
            $data = json_decode($row->source_extension, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new UnexpectedValueException('it is not a JSON object');
            }
            $extension = self::fromArray($data);
        } catch (JsonException|UnexpectedValueException $exception) {
            throw new UnexpectedValueException(sprintf('The stored invoice extension of VERI*FACTU record row %d cannot be read: %s', $row->id, $exception->getMessage()), 0, $exception);
        }

        return $invoice->withExtension($extension);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(SpanishInvoice $spanish): array
    {
        $breakdown = [];
        foreach ($spanish->breakdown as $key => $override) {
            $breakdown[$key] = [
                'tax' => $override->tax?->value,
                'regime' => $override->regime?->value,
                'qualification' => $override->qualification?->value,
                'exemption' => $override->exemption?->value,
                'cost_base' => $override->costBase?->text,
                'equivalence_rate' => $override->equivalenceRate?->text,
                'equivalence_quota' => $override->equivalenceQuota?->text,
            ];
        }

        return [
            'version' => self::VERSION,
            'type' => $spanish->type?->value,
            'correction_type' => $spanish->correctionType?->value,
            'correction_amounts' => $spanish->correctionAmounts === null ? null : [
                'base' => $spanish->correctionAmounts->base->text,
                'quota' => $spanish->correctionAmounts->quota->text,
                'equivalence_quota' => $spanish->correctionAmounts->equivalenceQuota?->text,
            ],
            'substituted' => array_map(static fn (InvoiceId $id): array => [
                'issuer' => $id->issuer->value,
                'number' => $id->number,
                'issue_date' => $id->issueDate->format('Y-m-d'),
            ], $spanish->substituted),
            'description' => $spanish->description,
            'simplified_art_72_73' => $spanish->simplifiedArt7273,
            'without_recipient_art_61d' => $spanish->withoutRecipientArt61d,
            'issued_by' => $spanish->issuedBy?->value,
            'third_party' => $spanish->thirdParty === null ? null : self::partyToArray($spanish->thirdParty),
            'recipients' => array_map(self::partyToArray(...), $spanish->recipients),
            'coupon' => $spanish->coupon,
            'breakdown' => $breakdown,
            'euro_amounts' => $spanish->euroAmounts === null ? null : [
                'total_tax' => $spanish->euroAmounts->totalTax->text,
                'total' => $spanish->euroAmounts->total->text,
                'breakdown' => array_map(static fn (array $entry): array => [
                    'base' => $entry['base']->text,
                    'quota' => $entry['quota']?->text,
                ], $spanish->euroAmounts->breakdown),
            ],
            'external_reference' => $spanish->externalReference,
            'agreement_number' => $spanish->agreementNumber,
            'system_agreement_id' => $spanish->systemAgreementId,
            'operation' => $spanish->operation->name,
        ];
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws UnexpectedValueException when the data is not a stored SpanishInvoice
     */
    public static function fromArray(array $data): SpanishInvoice
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new UnexpectedValueException(sprintf('unknown layout version %s', var_export($data['version'] ?? null, true)));
        }

        try {
            $breakdown = [];
            foreach (self::list($data, 'breakdown', true) as $key => $entry) {
                $entry = self::object($entry, 'breakdown.'.$key);
                $breakdown[(string) $key] = new BreakdownOverride(
                    self::enum(TaxType::class, $entry['tax'] ?? null),
                    self::enum(RegimeKey::class, $entry['regime'] ?? null),
                    self::enum(OperationQualification::class, $entry['qualification'] ?? null),
                    self::enum(ExemptOperation::class, $entry['exemption'] ?? null),
                    self::amount($entry['cost_base'] ?? null),
                    self::rate($entry['equivalence_rate'] ?? null),
                    self::amount($entry['equivalence_quota'] ?? null),
                );
            }

            $correction = $data['correction_amounts'] ?? null;
            $euro = $data['euro_amounts'] ?? null;
            $thirdParty = $data['third_party'] ?? null;

            return new SpanishInvoice(
                type: self::enum(InvoiceType::class, $data['type'] ?? null),
                correctionType: self::enum(CorrectionType::class, $data['correction_type'] ?? null),
                correctionAmounts: $correction === null ? null : new CorrectionAmounts(
                    self::requiredAmount(self::object($correction, 'correction_amounts')['base'] ?? null),
                    self::requiredAmount(self::object($correction, 'correction_amounts')['quota'] ?? null),
                    self::amount(self::object($correction, 'correction_amounts')['equivalence_quota'] ?? null),
                ),
                substituted: array_values(array_map(static function (mixed $id): InvoiceId {
                    $id = self::object($id, 'substituted');

                    return new InvoiceId(Nif::parse(self::text($id['issuer'] ?? null)), self::text($id['number'] ?? null), self::date($id['issue_date'] ?? null));
                }, self::list($data, 'substituted'))),
                description: self::optionalText($data['description'] ?? null),
                simplifiedArt7273: ($data['simplified_art_72_73'] ?? false) === true,
                withoutRecipientArt61d: ($data['without_recipient_art_61d'] ?? false) === true,
                issuedBy: self::enum(IssuedBy::class, $data['issued_by'] ?? null),
                thirdParty: $thirdParty === null ? null : self::partyFromArray($thirdParty),
                recipients: array_values(array_map(self::partyFromArray(...), self::list($data, 'recipients'))),
                coupon: ($data['coupon'] ?? false) === true,
                breakdown: $breakdown,
                euroAmounts: $euro === null ? null : self::euroAmounts(self::object($euro, 'euro_amounts')),
                externalReference: self::optionalText($data['external_reference'] ?? null),
                agreementNumber: self::optionalText($data['agreement_number'] ?? null),
                systemAgreementId: self::optionalText($data['system_agreement_id'] ?? null),
                operation: self::operation($data['operation'] ?? null),
            );
        } catch (UnexpectedValueException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UnexpectedValueException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function partyToArray(TaxParty $party): array
    {
        return [
            'name' => $party->name,
            'nif' => $party->nif?->value,
            'foreign_id' => $party->foreignId === null ? null : [
                'type' => $party->foreignId->type->value,
                'id' => $party->foreignId->id,
                'country' => $party->foreignId->country?->value,
            ],
        ];
    }

    private static function partyFromArray(mixed $party): TaxParty
    {
        $party = self::object($party, 'party');
        $nif = self::optionalText($party['nif'] ?? null);
        $foreign = $party['foreign_id'] ?? null;
        if ($foreign !== null) {
            $foreign = self::object($foreign, 'foreign_id');
            $type = self::enum(ForeignIdType::class, $foreign['type'] ?? null) ?? throw new UnexpectedValueException('foreign_id.type is missing');
            $foreign = new ForeignId($type, self::text($foreign['id'] ?? null), self::enum(CountryCode::class, $foreign['country'] ?? null));
        }

        return new TaxParty(self::text($party['name'] ?? null), $nif === null ? null : Nif::parse($nif), $foreign);
    }

    /**
     * @param  array<mixed>  $euro
     */
    private static function euroAmounts(array $euro): EuroAmounts
    {
        $breakdown = [];
        foreach (self::list($euro, 'breakdown', true) as $key => $entry) {
            $entry = self::object($entry, 'euro_amounts.breakdown.'.$key);
            $breakdown[(string) $key] = ['base' => self::requiredAmount($entry['base'] ?? null), 'quota' => self::amount($entry['quota'] ?? null)];
        }

        return new EuroAmounts(self::requiredAmount($euro['total_tax'] ?? null), self::requiredAmount($euro['total'] ?? null), $breakdown);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private static function enum(string $class, mixed $value): ?\BackedEnum
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new UnexpectedValueException(sprintf('%s is not a value of %s', var_export($value, true), $class));
        }

        return $class::tryFrom($value) ?? throw new UnexpectedValueException(sprintf('"%s" is not a value of %s', $value, $class));
    }

    private static function operation(mixed $value): AltaOperation
    {
        foreach (AltaOperation::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new UnexpectedValueException(sprintf('%s is not an AltaOperation', var_export($value, true)));
    }

    private static function amount(mixed $value): ?Amount
    {
        return $value === null ? null : Amount::fromLiteral(self::text($value));
    }

    private static function requiredAmount(mixed $value): Amount
    {
        return self::amount($value) ?? throw new UnexpectedValueException('a required amount is missing');
    }

    private static function rate(mixed $value): ?TaxRate
    {
        return $value === null ? null : TaxRate::fromLiteral(self::text($value));
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $text = self::text($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        // An overflowing date (2026-02-31) is parsed as another day; only an exact round trip counts.
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new UnexpectedValueException(sprintf('"%s" is not a Y-m-d date', $text));
        }

        return $date;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new UnexpectedValueException('a text value is missing');
    }

    private static function optionalText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    /**
     * @return array<mixed>
     */
    private static function object(mixed $value, string $field): array
    {
        return is_array($value) ? $value : throw new UnexpectedValueException($field.' is not an object');
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function list(array $data, string $field, bool $keyed = false): array
    {
        $value = $data[$field] ?? [];
        if (! is_array($value) || (! $keyed && ! array_is_list($value))) {
            throw new UnexpectedValueException($field.' is not a list');
        }

        return $value;
    }
}
