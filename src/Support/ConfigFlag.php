<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

/**
 * Reads on/off settings out of raw configuration values.
 *
 * Values set through environment variables arrive as strings, where a plain (bool) cast would
 * take "false" or "off" for true. Every place that reads a flag goes through this reader, so the
 * same value means the same thing everywhere.
 */
final class ConfigFlag
{
    /**
     * The on/off settings of this package, under "einvoicing.", with the value used when a
     * setting is absent and the value used when it cannot be read as a flag. An unreadable value
     * falls back to the safe side: rule checks and acknowledgements stay on, the webhook route
     * stays off.
     *
     * @var array<string, array{bool, bool}> key => [default, when unparseable]
     */
    public const SETTINGS = [
        'validation.enabled' => [true, true],
        'webhooks.enabled' => [true, false],
        'inbound.acknowledge' => [true, true],
        'status.poll' => [true, true],
    ];

    private const ON = ['1', 'true', 'on', 'yes'];

    private const OFF = ['0', 'false', 'off', 'no', ''];

    /**
     * Whether one of the package's on/off settings is on, read from the configuration: its
     * default when absent, and its safe value from SETTINGS when it cannot be read as a flag.
     *
     * @param  key-of<self::SETTINGS>  $key  the setting below "einvoicing."
     */
    public static function enabled(string $key): bool
    {
        [$default, $unparseable] = self::SETTINGS[$key];

        return self::read(config("einvoicing.{$key}"), $default) ?? $unparseable;
    }

    /**
     * The value as a flag: true for true, 1, "1", "true", "on" and "yes"; false for false, 0,
     * "0", "false", "off", "no" and ""; case and surrounding whitespace are ignored. Null gives
     * the default.
     *
     * @return bool|null null when the value is set but cannot be read as a flag
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
