<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use DateTimeImmutable;
use IntrAgile\EInvoicing\Spain\InvoiceType;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\OperationQualification;
use IntrAgile\EInvoicing\Spain\RegimeKey;
use IntrAgile\EInvoicing\Spain\TaxType;
use IntrAgile\EInvoicing\Spain\Verifactu\AltaOperation;
use IntrAgile\EInvoicing\Spain\Verifactu\Amount;
use IntrAgile\EInvoicing\Spain\Verifactu\ComputerSystem;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\RegistrationDraft;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxBreakdownLine;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxParty;
use IntrAgile\EInvoicing\Spain\Verifactu\TaxRate;
use PHPUnit\Framework\Assert;

/**
 * Synthetic VERI*FACTU inputs for tests.
 *
 * The vector draft reproduces the hashed fields of the AEAT hash specification v0.1.2 §6
 * (issuer 89890001K, F1, CuotaTotal 12.35, ImporteTotal 123.45). The computer system is a
 * fictitious producer.
 */
final class VerifactuSamples
{
    public const ISSUER_NIF = '89890001K';

    public const RECIPIENT_NIF = '89890002E';

    /** The fictitious issuer of the B2Brouter sandbox fixtures. */
    public const SANDBOX_ISSUER_NIF = 'B64829179';

    public static function system(): ComputerSystem
    {
        return new ComputerSystem(
            'Test Producer S.L.',
            Nif::parse('A39200019'),
            null,
            'TestSIF',
            '77',
            '1.0.03',
            '383',
            true,
            false,
            false,
        );
    }

    /** The issue date is given as AEAT writes it (dd-mm-yyyy). */
    public static function invoiceId(string $number, string $date, string $issuer = self::ISSUER_NIF): InvoiceId
    {
        $issueDate = DateTimeImmutable::createFromFormat('!d-m-Y', $date);
        Assert::assertInstanceOf(DateTimeImmutable::class, $issueDate);

        return new InvoiceId(Nif::parse($issuer), $number, $issueDate);
    }

    public static function vectorDraft(string $number, string $date, string $issuer = self::ISSUER_NIF, AltaOperation $operation = AltaOperation::Initial): RegistrationDraft
    {
        return self::draft(self::invoiceId($number, $date, $issuer), $operation);
    }

    public static function draft(InvoiceId $id, AltaOperation $operation = AltaOperation::Initial): RegistrationDraft
    {
        return new RegistrationDraft(
            id: $id,
            issuerName: 'Test Issuer S.L.',
            type: InvoiceType::F1,
            correctionType: null,
            corrected: [],
            substituted: [],
            correctionAmounts: null,
            operationDate: null,
            description: 'Vector invoice',
            simplifiedArt7273: false,
            withoutRecipientArt61d: false,
            macrodato: false,
            issuedBy: null,
            thirdParty: null,
            recipients: [new TaxParty('Test Recipient S.L.', Nif::parse(self::RECIPIENT_NIF), null)],
            coupon: false,
            breakdown: [self::line()],
            totalTax: Amount::of('12.35'),
            total: Amount::of('123.45'),
            externalReference: null,
            agreementNumber: null,
            systemAgreementId: null,
            operation: $operation,
        );
    }

    public static function line(): TaxBreakdownLine
    {
        return new TaxBreakdownLine(
            TaxType::Iva,
            RegimeKey::Key01,
            OperationQualification::S1,
            null,
            TaxRate::of('10.00'),
            Amount::of('111.10'),
            null,
            Amount::of('12.35'),
            null,
            null,
        );
    }

    /** The bytes of a B2Brouter sandbox fixture, e.g. "record-1064490.xml". */
    public static function b2brouterFixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__).'/fixtures/verifactu/b2brouter/'.$name);
        Assert::assertIsString($contents);

        return $contents;
    }
}
