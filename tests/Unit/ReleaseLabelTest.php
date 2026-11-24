<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documented limit of the VERI*FACTU release is quoted word for word wherever it is stated.
 */
final class ReleaseLabelTest extends TestCase
{
    public const LABEL = 'Verifactu live-verified on the B2Brouter sandbox (registered; hash and QR cross-checked); direct AEAT submission verified offline only — it needs a Spanish NIF and a qualified certificate';

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        foreach (['README.md', 'README.hu.md', 'CHANGELOG.md'] as $document) {
            yield $document => [$document];
        }
    }

    #[DataProvider('documents')]
    public function test_document_quotes_the_release_label_verbatim(string $document): void
    {
        $content = file_get_contents(__DIR__.'/../../'.$document);

        self::assertIsString($content);
        self::assertStringContainsString(self::LABEL, $content);
    }
}
