<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use DateTimeImmutable;
use IntrAgile\EInvoicing\Calculation\TotalsCalculator;
use IntrAgile\EInvoicing\CodeList\CountryCode;
use IntrAgile\EInvoicing\CodeList\CurrencyCode;
use IntrAgile\EInvoicing\CodeList\ElectronicAddressScheme;
use IntrAgile\EInvoicing\CodeList\InvoiceTypeCode;
use IntrAgile\EInvoicing\CodeList\PaymentMeansCode;
use IntrAgile\EInvoicing\CodeList\UnitCode;
use IntrAgile\EInvoicing\CodeList\VatCategory;
use IntrAgile\EInvoicing\Model\Contact;
use IntrAgile\EInvoicing\Model\CreditTransfer;
use IntrAgile\EInvoicing\Model\ElectronicAddress;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Model\InvoiceLine;
use IntrAgile\EInvoicing\Model\Item;
use IntrAgile\EInvoicing\Model\LineVat;
use IntrAgile\EInvoicing\Model\Party;
use IntrAgile\EInvoicing\Model\PaymentInstructions;
use IntrAgile\EInvoicing\Model\PostalAddress;
use IntrAgile\EInvoicing\Model\Price;
use IntrAgile\EInvoicing\Model\ProcessControl;
use IntrAgile\EInvoicing\Syntax\XRechnung;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;

/**
 * Invoices for tests of the sending pipeline.
 */
final class Invoices
{
    public const BIS_CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    public const SENDER = '0208:0123456789';

    public const RECEIVER = '9930:DE123456789';

    /** Leitweg-ID of the XRechnung buyer, used as its electronic address and buyer reference. */
    public const XRECHNUNG_LEITWEG_ID = '04011000-12345-34';

    /**
     * A one-line Peppol BIS Billing 3.0 invoice from a Belgian seller to a German buyer, both
     * with an electronic address, totals completed.
     */
    public static function bis(): Invoice
    {
        return TotalsCalculator::complete(new Invoice(
            number: 'BIS-2026-0001',
            issueDate: new DateTimeImmutable('2026-11-12'),
            type: InvoiceTypeCode::C_380,
            currency: CurrencyCode::EUR,
            seller: new Party(
                name: 'Voorbeeld NV',
                vatIdentifier: 'BE0123456789',
                electronicAddress: new ElectronicAddress('0123456789', ElectronicAddressScheme::C_0208),
                address: new PostalAddress(country: CountryCode::BE, line1: 'Kerkstraat 1', city: 'Brussel', postCode: '1000'),
            ),
            buyer: new Party(
                name: 'Beispiel GmbH',
                vatIdentifier: 'DE123456789',
                electronicAddress: new ElectronicAddress('DE123456789', ElectronicAddressScheme::C_9930),
                address: new PostalAddress(country: CountryCode::DE, line1: 'Hauptstraße 5', city: 'München', postCode: '80331'),
            ),
            dueDate: new DateTimeImmutable('2026-12-12'),
            buyerReference: 'PO-4711',
            processControl: new ProcessControl(self::BIS_CUSTOMIZATION, PeppolBis3::PROCESS),
            lines: [
                new InvoiceLine(
                    id: '1',
                    quantity: '2',
                    unit: UnitCode::C62,
                    price: new Price(netPrice: '50.00'),
                    item: new Item(name: 'Consulting session'),
                    vat: new LineVat(category: VatCategory::StandardRate, rate: '21'),
                ),
            ],
        ));
    }

    /**
     * A one-line XRechnung 3.0 invoice from a German seller to a German public buyer, routed by
     * the seller's VAT number and the buyer's Leitweg-ID, totals completed.
     */
    public static function xrechnung(): Invoice
    {
        return TotalsCalculator::complete(new Invoice(
            number: 'RE-2026-0815',
            issueDate: new DateTimeImmutable('2026-11-13'),
            type: InvoiceTypeCode::C_380,
            currency: CurrencyCode::EUR,
            seller: new Party(
                name: 'Muster Software GmbH',
                vatIdentifier: 'DE123456789',
                electronicAddress: new ElectronicAddress('DE123456789', ElectronicAddressScheme::C_9930),
                address: new PostalAddress(country: CountryCode::DE, line1: 'Lindenstraße 12', city: 'Berlin', postCode: '10115'),
                contact: new Contact(name: 'Erika Mustermann', phone: '+49 30 1234567', email: 'erika.mustermann@muster-software.example'),
            ),
            buyer: new Party(
                name: 'Stadtverwaltung Köln',
                electronicAddress: new ElectronicAddress(self::XRECHNUNG_LEITWEG_ID, ElectronicAddressScheme::C_0204),
                address: new PostalAddress(country: CountryCode::DE, line1: 'Marktplatz 3', city: 'Köln', postCode: '50667'),
            ),
            lines: [
                new InvoiceLine(
                    id: '1',
                    quantity: '10',
                    unit: UnitCode::HUR,
                    price: new Price(netPrice: '95.00'),
                    item: new Item(name: 'Softwareentwicklung'),
                    vat: new LineVat(category: VatCategory::StandardRate, rate: '19'),
                ),
            ],
            dueDate: new DateTimeImmutable('2026-12-13'),
            buyerReference: self::XRECHNUNG_LEITWEG_ID,
            paymentTerms: 'Zahlbar innerhalb von 30 Tagen ohne Abzug.',
            processControl: XRechnung::processControl(),
            payment: new PaymentInstructions(
                meansCode: PaymentMeansCode::C_58,
                remittanceInformation: 'RE-2026-0815',
                creditTransfers: [new CreditTransfer(accountIdentifier: 'DE75512108001245126199', accountName: 'Muster Software GmbH')],
            ),
        ));
    }
}
