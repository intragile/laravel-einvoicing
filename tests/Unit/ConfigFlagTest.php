<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ConfigFlagTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?bool}>
     */
    public static function values(): iterable
    {
        foreach ([true, 1, '1', 'true', 'on', 'yes', ' TRUE ', 'Yes', "ON\n", "\tyes"] as $value) {
            yield 'on '.var_export($value, true) => [$value, true];
        }
        foreach ([false, 0, '0', 'false', 'off', 'no', '', ' Off ', 'FALSE', 'No', '   '] as $value) {
            yield 'off '.var_export($value, true) => [$value, false];
        }
        foreach (['ture', 2, -1, '2', 'enabled', 1.0, 0.0, [], ['true'], new stdClass] as $index => $value) {
            yield "unparseable #{$index} ".get_debug_type($value) => [$value, null];
        }
    }

    #[DataProvider('values')]
    public function test_reads_boolean_settings(mixed $value, ?bool $expected): void
    {
        self::assertSame($expected, ConfigFlag::read($value, true));
        self::assertSame($expected, ConfigFlag::read($value, false));
    }

    public function test_null_gives_the_default(): void
    {
        self::assertTrue(ConfigFlag::read(null, true));
        self::assertFalse(ConfigFlag::read(null, false));
    }
}
