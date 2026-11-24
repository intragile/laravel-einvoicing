<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use Illuminate\Contracts\Config\Repository;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\Representative;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use InvalidArgumentException;

/**
 * Resolves issuers from einvoicing.verifactu.issuers.
 *
 * An issuer's own "driver" and "aeat" settings override the global ones key by key; the
 * environment is global. An issuer on the B2Brouter driver keeps the AEAT credentials still
 * configured for it, so the records left on its AEAT chain after a driver switch can be sent. The configuration is read on every call, so a change made at run time
 * is seen, and it is held inside a closure so that the AEAT password stays out of var_export(),
 * print_r() and var_dump() output of the resolver.
 */
final class ConfigIssuerResolver implements VerifactuIssuerResolver
{
    /** @var Closure(): Repository */
    private readonly Closure $config;

    public function __construct(Repository $config)
    {
        $this->config = static fn (): Repository => $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    public function resolve(Nif $issuer): IssuerContext
    {
        $entry = $this->issuerConfig()[$issuer->value] ?? null;
        if ($entry === null) {
            throw new UnknownIssuer(sprintf('No issuer data for %s.', $issuer->value));
        }
        $entry = is_array($entry) ? $entry : [];
        $prefix = 'einvoicing.verifactu.issuers.'.$issuer->value;

        $name = $entry['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new ConfigurationException($prefix.'.name must be set.');
        }
        $driver = VerifactuSettings::driverFrom(
            ($entry['driver'] ?? null) ?? $this->setting('driver'),
            ($entry['driver'] ?? null) !== null ? $prefix.'.driver' : 'einvoicing.verifactu.driver',
        );
        $environment = VerifactuSettings::environmentFrom($this->setting('environment'));

        try {
            return new IssuerContext(
                $issuer,
                $name,
                $driver,
                $environment,
                $driver === IssuerDriver::Aeat ? $this->credentials($entry, $prefix) : $this->leftoverCredentials($entry, $prefix),
                $this->representative($entry, $prefix),
            );
        } catch (InvalidArgumentException|InvalidRecord $exception) {
            throw new ConfigurationException($prefix.' is invalid: '.$exception->getMessage());
        }
    }

    public function issuers(): array
    {
        $issuers = [];
        foreach (array_keys($this->issuerConfig()) as $key) {
            try {
                $issuers[] = Nif::parse((string) $key);
            } catch (InvalidArgumentException) {
                throw new ConfigurationException(sprintf('The key "%s" of einvoicing.verifactu.issuers is not a valid Spanish NIF.', $key));
            }
        }

        return $issuers;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function issuerConfig(): array
    {
        $issuers = $this->setting('issuers');

        return is_array($issuers) ? $issuers : [];
    }

    private function setting(string $key): mixed
    {
        return ($this->config)()->get('einvoicing.verifactu.'.$key);
    }

    /**
     * The AEAT credentials of an issuer on the B2Brouter driver, while they are still configured:
     * the records left on its AEAT chain are sent with them. Null when none are configured or
     * they are invalid, since the B2Brouter driver does not need them.
     *
     * @param  array<array-key, mixed>  $entry
     */
    private function leftoverCredentials(array $entry, string $prefix): ?AeatCredentials
    {
        try {
            return $this->credentials($entry, $prefix);
        } catch (ConfigurationException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function credentials(array $entry, string $prefix): AeatCredentials
    {
        $global = $this->setting('aeat');
        $global = is_array($global) ? $global : [];
        $override = is_array($entry['aeat'] ?? null) ? $entry['aeat'] : [];
        // Per key: the issuer's own value when it has one, else the global value.
        $aeat = [];
        foreach (['certificate', 'password', 'private_key', 'port'] as $key) {
            $value = $override[$key] ?? null;
            $aeat[$key] = $value !== null && $value !== '' ? $value : ($global[$key] ?? null);
        }

        $certificate = $aeat['certificate'] ?? null;
        if (! is_string($certificate)) {
            throw new ConfigurationException(sprintf('einvoicing.verifactu.aeat.certificate (or %s.aeat.certificate) must be set for the "aeat" driver.', $prefix));
        }
        $port = $aeat['port'] ?? 'personal';
        $kind = is_string($port) ? CertificateKind::tryFrom($port) : null;
        if ($kind === null) {
            throw new ConfigurationException('einvoicing.verifactu.aeat.port must be "personal" or "seal".');
        }
        $password = $aeat['password'] ?? null;
        $privateKey = $aeat['private_key'] ?? null;

        return new AeatCredentials(
            $certificate,
            is_string($password) ? $password : null,
            is_string($privateKey) ? $privateKey : null,
            $kind,
        );
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function representative(array $entry, string $prefix): ?Representative
    {
        $representative = $entry['representative'] ?? null;
        if (! is_array($representative)) {
            return null;
        }
        $name = $representative['name'] ?? null;
        $nif = $representative['nif'] ?? null;
        if (! is_string($name) || ! is_string($nif)) {
            throw new ConfigurationException($prefix.'.representative needs a name and a nif.');
        }
        try {
            return new Representative($name, Nif::parse($nif));
        } catch (InvalidArgumentException) {
            throw new ConfigurationException($prefix.'.representative.nif is not a valid Spanish NIF.');
        }
    }
}
