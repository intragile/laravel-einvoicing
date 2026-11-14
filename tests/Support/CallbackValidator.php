<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use Closure;
use IntrAgile\EInvoicing\Syntax\Syntax;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\Finding;
use IntrAgile\EInvoicing\Validation\Severity;
use IntrAgile\EInvoicing\Validation\ValidationReport;

/**
 * A document validator that answers with a callback and counts its calls.
 */
final class CallbackValidator implements DocumentValidator
{
    public int $calls = 0;

    /**
     * @param  Closure(string): ValidationReport  $validate
     */
    public function __construct(private readonly Closure $validate) {}

    public function validate(string $xml): ValidationReport
    {
        $this->calls++;

        return ($this->validate)($xml);
    }

    public static function valid(): ValidationReport
    {
        return new ValidationReport(['test 1.0'], Syntax::Ubl, [], []);
    }

    public static function invalid(): ValidationReport
    {
        return new ValidationReport(
            ['test 1.0'],
            Syntax::Ubl,
            [new Finding('PEPPOL-EN16931-R001', Severity::Fatal, '/Invoice', 'Business process MUST be provided.', 'cbc:ProfileID')],
            [],
        );
    }
}
