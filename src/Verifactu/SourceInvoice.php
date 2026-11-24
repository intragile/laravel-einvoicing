<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Spain\SpanishInvoice;
use IntrAgile\EInvoicing\Syntax\Ubl\UblReader;
use UnexpectedValueException;

/**
 * The invoice a B2Brouter record row was imported from, kept as the UBL sent (source) plus its
 * SpanishInvoice extension (source_extension), which UBL cannot carry: without it, an exempt line
 * loses its exemption cause and a corrective invoice its type, and our own mapping of the invoice
 * would refuse what B2Brouter registered.
 *
 * The extension is stored with serialize(). It is read back allowing only the value classes it is
 * made of (the Spain and CodeList namespaces, Brick\Math numbers and DateTimeImmutable), and must
 * then be a SpanishInvoice.
 */
final class SourceInvoice
{
    /** Namespace prefixes of the classes a SpanishInvoice is made of. */
    private const ALLOWED_PREFIXES = ['IntrAgile\\EInvoicing\\Spain\\', 'IntrAgile\\EInvoicing\\CodeList\\', 'Brick\\Math\\'];

    /** Classes outside those namespaces a SpanishInvoice may hold. */
    private const ALLOWED_CLASSES = ['DateTimeImmutable', 'DateTimeZone'];

    /** The stored form of the invoice's SpanishInvoice extension, or null when it has none. */
    public static function extensionOf(Invoice $invoice): ?string
    {
        $spanish = $invoice->extensions()->get(SpanishInvoice::class);

        return $spanish instanceof SpanishInvoice ? serialize($spanish) : null;
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

        return $invoice->withExtension(self::extension($row->source_extension, $row->id));
    }

    /**
     * @throws UnexpectedValueException
     */
    private static function extension(string $stored, int $id): SpanishInvoice
    {
        preg_match_all('/[OCE]:\d+:"([^":]+)/', $stored, $matches);
        $classes = array_values(array_unique($matches[1]));
        foreach ($classes as $class) {
            if (! in_array($class, self::ALLOWED_CLASSES, true) && ! self::hasAllowedPrefix($class)) {
                throw new UnexpectedValueException(sprintf('The stored invoice extension of VERI*FACTU record row %d names the class %s, which it may not hold.', $id, $class));
            }
        }

        $extension = @unserialize($stored, ['allowed_classes' => $classes]);
        if (! $extension instanceof SpanishInvoice) {
            throw new UnexpectedValueException(sprintf('The stored invoice extension of VERI*FACTU record row %d is not a SpanishInvoice.', $id));
        }

        return $extension;
    }

    private static function hasAllowedPrefix(string $class): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
