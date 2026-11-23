<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Locates the installed AEAT VERI*FACTU schema set for tests that need the official files.
 */
final class VerifactuFiles
{
    /**
     * The folder the schemas were installed into (the parent of the "verifactu-tikeV1.0" folder):
     * EINVOICING_VERIFACTU_SCHEMAS, else build/verifactu, else the fixtures of the linked core
     * checkout. Skips the running test when none holds the set.
     */
    public static function schemasDir(): string
    {
        $root = dirname(__DIR__, 2);
        $candidates = [
            self::env('EINVOICING_VERIFACTU_SCHEMAS'),
            $root.'/build/verifactu',
            $root.'/vendor/intragile/php-einvoicing/tests/fixtures/verifactu',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate.'/verifactu-tikeV1.0/SuministroLR.xsd')) {
                return $candidate;
            }
        }

        Assert::markTestSkipped('Run vendor/bin/einvoicing-rules install spain --dir=build/verifactu');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
