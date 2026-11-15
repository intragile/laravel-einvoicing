<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function settings(): iterable
    {
        yield 'validation stays on' => ['validation.enabled', true];
        yield 'webhook route stays off' => ['webhooks.enabled', false];
        yield 'acknowledgements stay on' => ['inbound.acknowledge', true];
        yield 'status polls stay on' => ['status.poll', true];
    }

    #[DataProvider('settings')]
    public function test_settings_fall_back_to_their_safe_value(string $key, bool $whenUnreadable): void
    {
        config()->set("einvoicing.{$key}", null);
        self::assertTrue(ConfigFlag::enabled($key));

        foreach (['false', '0', 'off', ''] as $off) {
            config()->set("einvoicing.{$key}", $off);
            self::assertFalse(ConfigFlag::enabled($key), $off);
        }
        config()->set("einvoicing.{$key}", 'true');
        self::assertTrue(ConfigFlag::enabled($key));

        foreach (['ture', 2, ['on']] as $unreadable) {
            config()->set("einvoicing.{$key}", $unreadable);
            self::assertSame($whenUnreadable, ConfigFlag::enabled($key));
        }
    }

    public function test_null_gives_the_default(): void
    {
        self::assertTrue(ConfigFlag::read(null, true));
        self::assertFalse(ConfigFlag::read(null, false));
    }
}
