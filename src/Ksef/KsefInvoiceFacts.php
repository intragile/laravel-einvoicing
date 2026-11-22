<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Ksef;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMXPath;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Poland\Offline\OfflineDeadline;
use IntrAgile\EInvoicing\Poland\Offline\OfflineDeadlines;
use IntrAgile\EInvoicing\Poland\Offline\OfflineMode;
use IntrAgile\EInvoicing\Poland\Offline\Outage;
use IntrAgile\EInvoicing\Syntax\Fa3\Fa3Format;
use IntrAgile\EInvoicing\Syntax\SafeXml;
use InvalidArgumentException;

/**
 * The parts of an FA(3) invoice the KSeF queue stores and its offline deadline needs: the seller
 * NIP, the buyer's identifier and the issue date (P_1).
 *
 * @internal
 */
final readonly class KsefInvoiceFacts
{
    private function __construct(
        public string $sellerNip,
        public string $buyer,
        public DateTimeImmutable $issueDate,
    ) {}

    /**
     * @throws UnsafeXml when the document carries a DOCTYPE declaration or declares entities
     * @throws UnsupportedDocument when it is not well-formed XML or not an FA(3) invoice
     * @throws InvalidArgumentException when the seller NIP or the issue date is missing or ill-formed
     */
    public static function read(string $xml): self
    {
        $document = SafeXml::load($xml);
        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== Fa3Format::NS || $root->localName !== 'Faktura') {
            throw new UnsupportedDocument(sprintf(
                'The document element {%s}%s is not an FA(3) invoice; expected {%s}Faktura.',
                $root->namespaceURI ?? '',
                $root->localName ?? '',
                Fa3Format::NS,
            ));
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('fa', Fa3Format::NS);

        $seller = self::text($xpath, '/fa:Faktura/fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP')
            ?? throw new InvalidArgumentException('The FA(3) invoice names no seller NIP (Podmiot1/DaneIdentyfikacyjne/NIP).');
        $p1 = self::text($xpath, '/fa:Faktura/fa:Fa/fa:P_1')
            ?? throw new InvalidArgumentException('The FA(3) invoice has no issue date (Fa/P_1).');
        $issueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $p1, new DateTimeZone('Europe/Warsaw'));
        if ($issueDate === false || $issueDate->format('Y-m-d') !== $p1) {
            throw new InvalidArgumentException(sprintf('The FA(3) issue date Fa/P_1 "%s" is not a YYYY-MM-DD date.', $p1));
        }

        return new self($seller, self::buyer($xpath), $issueDate);
    }

    /**
     * The offline24 sending deadline: the end of the first working day after the issue date,
     * moved by the announced failures among the given outages.
     *
     * @param  list<Outage>  $outages
     *
     * @throws InvalidArgumentException when the issue date lies outside the years the working-day
     *                                  calendar supports
     */
    public function offlineDeadline(array $outages = []): OfflineDeadline
    {
        return OfflineDeadlines::for($this->issueDate, OfflineMode::Offline24, $outages);
    }

    /**
     * The buyer's NIP; else the EU VAT number with its country code, or another identifier; an
     * empty string for a buyer without any (BrakID).
     */
    private static function buyer(DOMXPath $xpath): string
    {
        $base = '/fa:Faktura/fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:';

        $nip = self::text($xpath, $base.'NIP');
        if ($nip !== null) {
            return $nip;
        }
        $vat = self::text($xpath, $base.'NrVatUE');
        if ($vat !== null) {
            return (self::text($xpath, $base.'KodUE') ?? '').$vat;
        }

        return self::text($xpath, $base.'NrID') ?? '';
    }

    private static function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false || $nodes->length !== 1 ? null : $nodes->item(0);
        $text = $node instanceof DOMElement ? trim($node->textContent) : '';

        return $text === '' ? null : $text;
    }
}
