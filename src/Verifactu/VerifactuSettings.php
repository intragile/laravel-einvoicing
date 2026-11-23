<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use DateTimeZone;
use Exception;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\EInvoicing\CodeList\CountryCode;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Spain\ForeignIdType;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\ComputerSystem;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\ForeignId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckMode;

/**
 * The checked settings of einvoicing.verifactu that carry no credentials: the environment, the
 * default driver, the schema folder, the submission shape and the B2Brouter polling.
 *
 * The environment defaults to "test"; "production" is used only when it is set explicitly. The
 * declaration of the billing software is checked on demand by system(), so an installation that
 * has not declared one can still run the commands that do not need it. Errors name the setting,
 * never its value.
 */
final readonly class VerifactuSettings
{
    private const SYSTEM = 'einvoicing.verifactu.system.';

    /**
     * @param  array<array-key, mixed>  $systemConfig  the einvoicing.verifactu.system array
     */
    public function __construct(
        public VerifactuEnvironment $environment,
        public IssuerDriver $driver,
        public DateTimeZone $timezone,
        public string $schemasDir,
        public ThrottleScope $throttleScope,
        public int $maxBatch,
        public int $initialWait,
        public CrossCheckMode $crossCheck,
        public int $stuckAfterMinutes,
        public ?string $b2brouterTransport,
        public int $pollAttempts,
        public int $pollDelay,
        public ?string $queue,
        public ?string $connection,
        private array $systemConfig = [],
    ) {}

    /**
     * @param  string  $defaultSchemasDir  the schema folder used when none is configured
     *
     * @throws ConfigurationException when a setting is invalid
     */
    public static function fromConfig(Repository $config, string $defaultSchemasDir): self
    {
        $verifactu = $config->get('einvoicing.verifactu', []);
        $verifactu = is_array($verifactu) ? $verifactu : [];
        $submission = is_array($verifactu['submission'] ?? null) ? $verifactu['submission'] : [];
        $b2brouter = is_array($verifactu['b2brouter'] ?? null) ? $verifactu['b2brouter'] : [];
        $system = is_array($verifactu['system'] ?? null) ? $verifactu['system'] : [];

        return new self(
            self::environmentFrom($verifactu['environment'] ?? null),
            self::driverFrom($verifactu['driver'] ?? null, 'einvoicing.verifactu.driver'),
            self::timezone($verifactu['timezone'] ?? null),
            self::text($verifactu['schemas_dir'] ?? null, 'einvoicing.verifactu.schemas_dir') ?? $defaultSchemasDir,
            self::throttleScope($submission['throttle_scope'] ?? null),
            ConfigValue::integer($submission['max_batch'] ?? 1000, 'einvoicing.verifactu.submission.max_batch', 1, 1000),
            ConfigValue::integer($submission['initial_wait'] ?? 60, 'einvoicing.verifactu.submission.initial_wait', 0, 3600),
            self::crossCheck($verifactu['cross_check'] ?? null),
            ConfigValue::integer($verifactu['stuck_after_minutes'] ?? 60, 'einvoicing.verifactu.stuck_after_minutes', 1, 525_600),
            self::text($b2brouter['transport'] ?? null, 'einvoicing.verifactu.b2brouter.transport'),
            ConfigValue::integer($b2brouter['poll_attempts'] ?? 10, 'einvoicing.verifactu.b2brouter.poll_attempts', 1, 1000),
            ConfigValue::integer($b2brouter['poll_delay'] ?? 30, 'einvoicing.verifactu.b2brouter.poll_delay', 0, 3600),
            self::text($submission['queue'] ?? null, 'einvoicing.verifactu.submission.queue'),
            self::text($submission['connection'] ?? null, 'einvoicing.verifactu.submission.connection'),
            $system,
        );
    }

    /**
     * The environment named by a setting: "test" when it is null or the empty string of an unset
     * `KEY=` line in `.env`.
     *
     * @throws ConfigurationException when it names no environment
     */
    public static function environmentFrom(mixed $value): VerifactuEnvironment
    {
        if ($value === null || $value === '') {
            return VerifactuEnvironment::Test;
        }

        return (is_string($value) ? VerifactuEnvironment::tryFrom($value) : null) ?? throw new ConfigurationException(
            'einvoicing.verifactu.environment must be "test" or "production".',
        );
    }

    /**
     * The driver named by a setting: "aeat" when it is null or the empty string.
     *
     * @throws ConfigurationException when it names no driver
     */
    public static function driverFrom(mixed $value, string $key): IssuerDriver
    {
        if ($value === null || $value === '') {
            return IssuerDriver::Aeat;
        }

        return (is_string($value) ? IssuerDriver::tryFrom($value) : null) ?? throw new ConfigurationException(
            sprintf('%s must be "aeat" or "b2brouter".', $key),
        );
    }

    /**
     * The declared billing software (SistemaInformatico).
     *
     * @throws ConfigurationException listing every missing or invalid setting, by key
     */
    public function system(): ComputerSystem
    {
        $config = $this->systemConfig;
        $missing = [];
        $text = static function (string $key) use ($config, &$missing): string {
            $value = $config[$key] ?? null;
            if (! is_string($value) && ! is_int($value) || trim((string) $value) === '' && $key !== 'system_name' && $key !== 'version') {
                $missing[] = self::SYSTEM.$key;

                return '';
            }

            return (string) $value;
        };
        $flag = static function (string $key) use ($config, &$missing): bool {
            $value = ConfigFlag::read($config[$key] ?? null, false);
            if (($config[$key] ?? null) === null || $value === null) {
                $missing[] = self::SYSTEM.$key;

                return false;
            }

            return $value;
        };

        $name = $text('name');
        $systemName = $text('system_name');
        $systemId = $text('system_id');
        $version = $text('version');
        $installationNumber = $text('installation_number');
        $verifactuOnly = $flag('verifactu_only');
        $multiplePossible = $flag('multiple_taxpayers_possible');
        $multipleIndicator = $flag('multiple_taxpayers_indicator');

        $nifValue = $config['nif'] ?? null;
        $foreign = is_array($config['foreign_id'] ?? null) ? $config['foreign_id'] : [];
        $hasNif = is_string($nifValue) && trim($nifValue) !== '';
        $hasForeign = (($foreign['type'] ?? null) ?? '') !== '' || (($foreign['id'] ?? null) ?? '') !== '';
        if ($hasNif && $hasForeign) {
            throw new ConfigurationException('Set exactly one of einvoicing.verifactu.system.nif and einvoicing.verifactu.system.foreign_id, not both.');
        }
        if (! $hasNif && ! $hasForeign) {
            $missing[] = self::SYSTEM.'nif or '.self::SYSTEM.'foreign_id';
        }
        if ($missing !== []) {
            throw new ConfigurationException('The VERI*FACTU billing software is not declared; set '.implode(', ', $missing).'.');
        }

        $nif = null;
        $foreignId = null;
        if ($hasNif) {
            try {
                $nif = Nif::parse((string) $nifValue);
            } catch (\InvalidArgumentException) {
                throw new ConfigurationException(self::SYSTEM.'nif is not a valid Spanish NIF.');
            }
        } else {
            $foreignId = self::foreignId($foreign);
        }

        try {
            return new ComputerSystem($name, $nif, $foreignId, $systemName, $systemId, $version, $installationNumber, $verifactuOnly, $multiplePossible, $multipleIndicator);
        } catch (InvalidRecord $exception) {
            throw new ConfigurationException('The einvoicing.verifactu.system settings are invalid: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<array-key, mixed>  $foreign
     */
    private static function foreignId(array $foreign): ForeignId
    {
        $type = is_string($foreign['type'] ?? null) ? ForeignIdType::tryFrom($foreign['type']) : null;
        if ($type === null) {
            throw new ConfigurationException(self::SYSTEM.'foreign_id.type must be a PersonaFisicaJuridicaIDType code from "02" to "07".');
        }
        $id = $foreign['id'] ?? null;
        if (! is_string($id) && ! is_int($id)) {
            throw new ConfigurationException(self::SYSTEM.'foreign_id.id must be set.');
        }
        $countryValue = $foreign['country'] ?? null;
        $country = null;
        if ($countryValue !== null && $countryValue !== '') {
            $country = is_string($countryValue) ? CountryCode::tryFrom(strtoupper($countryValue)) : null;
            if ($country === null) {
                throw new ConfigurationException(self::SYSTEM.'foreign_id.country must be an ISO 3166-1 alpha-2 code.');
            }
        }

        try {
            return new ForeignId($type, (string) $id, $country);
        } catch (InvalidRecord) {
            throw new ConfigurationException(self::SYSTEM.'foreign_id.id must be 1 to 20 characters.');
        }
    }

    private static function timezone(mixed $value): DateTimeZone
    {
        if ($value === null || $value === '') {
            return new DateTimeZone('Europe/Madrid');
        }
        try {
            return new DateTimeZone(is_string($value) ? $value : '');
        } catch (Exception) {
            throw new ConfigurationException('einvoicing.verifactu.timezone must be a PHP time zone identifier.');
        }
    }

    private static function throttleScope(mixed $value): ThrottleScope
    {
        if ($value === null || $value === '') {
            return ThrottleScope::Installation;
        }

        return (is_string($value) ? ThrottleScope::tryFrom($value) : null) ?? throw new ConfigurationException(
            'einvoicing.verifactu.submission.throttle_scope must be "installation" or "issuer".',
        );
    }

    private static function crossCheck(mixed $value): CrossCheckMode
    {
        if ($value === null || $value === '') {
            return CrossCheckMode::Strict;
        }

        return (is_string($value) ? CrossCheckMode::tryFrom($value) : null) ?? throw new ConfigurationException(
            'einvoicing.verifactu.cross_check must be "strict" or "report".',
        );
    }

    /**
     * A non-empty string setting, or null when it is unset or the empty string of a `KEY=` line.
     */
    private static function text(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new ConfigurationException(sprintf('%s must be a string.', $key));
        }

        return $value;
    }
}
