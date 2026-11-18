<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\France;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterAnnuaire;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterFrance;

/**
 * Builds the French e-invoicing clients of a B2Brouter transport: B2BrouterFrance for sending,
 * lifecycle statuses, buyer responses, payments and e-reporting, and B2BrouterAnnuaire for live
 * directory lookups.
 *
 * Without a transport name the one in einvoicing.france.transport is used. The transport must be
 * configured in einvoicing.transports with the b2brouter driver; its credentials, base URL and
 * the HTTP settings are read exactly as for the B2Brouter Peppol transport. Clients are built on
 * first use and cached per transport name, so configuration changed at run time affects only
 * transports not built yet. These clients make real requests: EInvoicing::fake() does not replace
 * them. The application is held inside a closure, so the configuration and its secrets stay out of
 * var_export(), print_r() and var_dump() output.
 */
final class FranceManager
{
    /** Annuaire lookup attempts while B2Brouter answers HTTP 202, when none are configured. */
    public const DEFAULT_ANNUAIRE_ATTEMPTS = 4;

    /** Seconds between two Annuaire lookup attempts, when none are configured. */
    public const DEFAULT_ANNUAIRE_RETRY_DELAY = 3;

    private const MAX_ANNUAIRE_ATTEMPTS = 10;

    private const MAX_ANNUAIRE_RETRY_DELAY = 30;

    /** @var Closure(): Application */
    private readonly Closure $app;

    /** @var array<string, B2BrouterFrance> */
    private array $clients = [];

    /** @var array<string, B2BrouterAnnuaire> */
    private array $annuaires = [];

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * The French flow client of the B2Brouter transport with this name, or of the one in
     * einvoicing.france.transport.
     *
     * @throws ConfigurationException when no transport is named, the transport is not configured
     *                                or not B2Brouter, or one of its settings is invalid
     */
    public function france(?string $transport = null): B2BrouterFrance
    {
        $transport = $this->resolveName($transport);

        return $this->clients[$transport] ??= new B2BrouterFrance(
            $this->b2brouterConfig($transport),
            $this->manager()->httpClient(),
        );
    }

    /**
     * The live French directory of the B2Brouter transport with this name, or of the one in
     * einvoicing.france.transport, retrying as einvoicing.france.annuaire_attempts and
     * annuaire_retry_delay say.
     *
     * @throws ConfigurationException when no transport is named, the transport is not configured
     *                                or not B2Brouter, or one of its or the Annuaire settings is invalid
     */
    public function annuaire(?string $transport = null): B2BrouterAnnuaire
    {
        $transport = $this->resolveName($transport);
        if (isset($this->annuaires[$transport])) {
            return $this->annuaires[$transport];
        }
        [$attempts, $retryDelay] = $this->annuaireSettings();

        return $this->annuaires[$transport] = new B2BrouterAnnuaire(
            $this->b2brouterConfig($transport),
            $this->manager()->httpClient(),
            $attempts,
            $retryDelay,
        );
    }

    /**
     * The checked core configuration of the B2Brouter transport with this name, or of the one in
     * einvoicing.france.transport, without building a client or making a request.
     *
     * @throws ConfigurationException as france()
     */
    public function b2brouterConfig(?string $transport = null): B2BrouterConfig
    {
        $transport = $this->resolveName($transport);
        $transports = $this->config()->get('einvoicing.transports');
        $config = is_array($transports) && array_key_exists($transport, $transports) ? $transports[$transport] : null;
        if (! is_array($config)) {
            throw new ConfigurationException(sprintf(
                'The French flow needs a B2Brouter transport, but einvoicing.transports.%s is not configured; check einvoicing.france.transport.',
                $transport,
            ));
        }
        if (($config['driver'] ?? null) !== 'b2brouter') {
            throw new ConfigurationException(sprintf(
                'The French flow needs a B2Brouter transport, but einvoicing.transports.%s does not use the b2brouter driver; check einvoicing.france.transport.',
                $transport,
            ));
        }

        $driverConfig = $this->manager()->driverConfig($transport);
        // The b2brouter driver always yields a B2BrouterConfig; this narrows the union type.
        if (! $driverConfig instanceof B2BrouterConfig) {
            throw new ConfigurationException(sprintf('einvoicing.transports.%s is not a B2Brouter transport.', $transport));
        }

        return $driverConfig;
    }

    /**
     * The Annuaire lookup attempts and the seconds between them.
     *
     * @return array{int, int}
     *
     * @throws ConfigurationException when a setting is not a whole number within its range
     */
    public function annuaireSettings(): array
    {
        $config = $this->config();

        return [
            ConfigValue::integer(
                $config->get('einvoicing.france.annuaire_attempts') ?? self::DEFAULT_ANNUAIRE_ATTEMPTS,
                'einvoicing.france.annuaire_attempts',
                1,
                self::MAX_ANNUAIRE_ATTEMPTS,
            ),
            ConfigValue::integer(
                $config->get('einvoicing.france.annuaire_retry_delay') ?? self::DEFAULT_ANNUAIRE_RETRY_DELAY,
                'einvoicing.france.annuaire_retry_delay',
                0,
                self::MAX_ANNUAIRE_RETRY_DELAY,
            ),
        ];
    }

    /**
     * The transport name in einvoicing.france.transport, or null when it is not set.
     */
    public function configuredTransport(): ?string
    {
        return self::transportName($this->config()->get('einvoicing.france.transport'));
    }

    /**
     * The transport name an einvoicing.france.transport value gives, or null when it gives none:
     * null, the empty string of an unset `KEY=` line in `.env`, a blank string or no string.
     */
    public static function transportName(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'transport' => $this->configuredTransport(),
            'built' => array_keys($this->clients),
            'annuaires' => array_keys($this->annuaires),
        ];
    }

    /**
     * @throws ConfigurationException when no transport is named and einvoicing.france.transport is not set
     */
    private function resolveName(?string $transport): string
    {
        if ($transport !== null) {
            return $transport;
        }

        return $this->configuredTransport() ?? throw new ConfigurationException(
            'einvoicing.france.transport must name the B2Brouter transport of the French flow.',
        );
    }

    private function manager(): EInvoicingManager
    {
        return ($this->app)()->make(EInvoicingManager::class);
    }

    private function config(): Repository
    {
        return ($this->app)()->make(Repository::class);
    }
}
