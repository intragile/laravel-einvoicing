<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

/**
 * Reads on/off settings out of raw configuration values.
 *
 * Values set through environment variables arrive as strings, where a plain (bool) cast would
 * take "false", "0 " or "off" for true. Every place that reads a flag goes through this reader,
 * so the same value means the same thing everywhere.
 */
final class ConfigFlag
{
    private const ON = ['1', 'true', 'on', 'yes'];

    private const OFF = ['0', 'false', 'off', 'no', ''];

    /**
     * The value as a flag: true for true, 1, "1", "true", "on" and "yes"; false for false, 0,
     * "0", "false", "off", "no" and ""; case and surrounding whitespace are ignored. Null gives
     * the default.
     *
     * @return bool|null null when the value is set but cannot be read as a flag; callers treat
     *                   that as off
     */
    public static function read(mixed $value, bool $default): ?bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if (in_array($value, self::ON, true)) {
            return true;
        }
        if (in_array($value, self::OFF, true)) {
            return false;
        }

        return null;
    }
}
