<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Ksef;

use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Poland\KsefEnvironment;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefExport;

/**
 * The checked KSeF settings of einvoicing.ksef that carry no credentials: the environment, the
 * response size cap, the inbox poll interval and the deadline warning window.
 *
 * The environment defaults to TE ("test"); "production" is used only when it is set explicitly.
 */
final readonly class KsefSettings
{
    /** The response size cap when none is configured: 64 MiB. */
    public const DEFAULT_MAX_RESPONSE_BYTES = 67_108_864;

    /** The smallest response size cap: the largest encrypted export part KSeF sends. */
    public const MIN_MAX_RESPONSE_BYTES = KsefExport::MAX_PART_BYTES;

    public const DEFAULT_INBOUND_MIN_INTERVAL_MINUTES = 15;

    public const DEFAULT_DEADLINE_WARNING_HOURS = 24;

    private const MAX_INBOUND_MIN_INTERVAL_MINUTES = 1440;

    private const MAX_DEADLINE_WARNING_HOURS = 720;

    public function __construct(
        public KsefEnvironment $environment,
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        public int $inboundMinIntervalMinutes = self::DEFAULT_INBOUND_MIN_INTERVAL_MINUTES,
        public int $deadlineWarningHours = self::DEFAULT_DEADLINE_WARNING_HOURS,
    ) {}

    /**
     * Reads the settings from the einvoicing.ksef array; a missing or null value takes its default.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException when a setting is invalid, naming its key and never its value
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::environment($config['environment'] ?? null),
            ConfigValue::integer(
                $config['max_response_bytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES,
                'einvoicing.ksef.max_response_bytes',
                self::MIN_MAX_RESPONSE_BYTES,
                PHP_INT_MAX,
            ),
            ConfigValue::integer(
                $config['inbound_min_interval_minutes'] ?? self::DEFAULT_INBOUND_MIN_INTERVAL_MINUTES,
                'einvoicing.ksef.inbound_min_interval_minutes',
                0,
                self::MAX_INBOUND_MIN_INTERVAL_MINUTES,
            ),
            ConfigValue::integer(
                $config['deadline_warning_hours'] ?? self::DEFAULT_DEADLINE_WARNING_HOURS,
                'einvoicing.ksef.deadline_warning_hours',
                1,
                self::MAX_DEADLINE_WARNING_HOURS,
            ),
        );
    }

    /**
     * The environment named by the setting: TE when it is null or the empty string of an unset
     * `KEY=` line in `.env`.
     *
     * @throws ConfigurationException when it names no environment
     */
    private static function environment(mixed $value): KsefEnvironment
    {
        if ($value === null || $value === '') {
            return KsefEnvironment::Test;
        }

        return (is_string($value) ? KsefEnvironment::tryFrom($value) : null) ?? throw new ConfigurationException(
            'einvoicing.ksef.environment must be "test", "demo" or "production".',
        );
    }
}
