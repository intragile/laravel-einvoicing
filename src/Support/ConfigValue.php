<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Support;

use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;

/**
 * Reads typed values out of raw configuration values.
 *
 * Values set through environment variables arrive as strings, so a numeric string is accepted
 * wherever a number is expected. Anything else is refused with a message that names the key and
 * never the value.
 */
final class ConfigValue
{
    /**
     * The value as a number: an int or a float as is, a numeric string cast to an int or a float.
     *
     * @throws ConfigurationException when the value is not a number or a numeric string
     */
    public static function number(mixed $value, string $key): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        throw new ConfigurationException(sprintf('%s must be a number.', $key));
    }

    /**
     * The value as a whole number within the given bounds, read like number().
     *
     * @throws ConfigurationException when the value is not a whole number from $min to $max
     */
    public static function integer(mixed $value, string $key, int $min, int $max): int
    {
        $number = self::number($value, $key);
        if ($number != floor($number) || $number < $min || $number > $max) {
            throw new ConfigurationException(sprintf('%s must be a whole number from %d to %d.', $key, $min, $max));
        }

        return (int) $number;
    }
}
