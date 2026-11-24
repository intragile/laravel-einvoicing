<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use DateTimeImmutable;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Nif;
use InvalidArgumentException;

/**
 * Option parsing shared by the VERI*FACTU commands.
 */
trait InteractsWithVerifactu
{
    /**
     * The issuer named by --issuer, or null when the option is not given.
     *
     * @throws InvalidArgumentException when the option is not a valid Spanish NIF
     */
    private function issuerFilter(): ?Nif
    {
        $issuer = $this->option('issuer');
        if (! is_string($issuer) || $issuer === '') {
            return null;
        }

        return self::nif($issuer, '--issuer');
    }

    /**
     * The issuer named by --issuer; without the option, the only issuer the resolver enumerates.
     *
     * @throws InvalidArgumentException when the option is not a valid NIF, or it is missing while
     *                                  the resolver enumerates no issuer or several
     * @throws ConfigurationException when einvoicing.verifactu.issuers holds an invalid key
     */
    private function requiredIssuer(VerifactuManager $verifactu): Nif
    {
        $issuer = $this->issuerFilter();
        if ($issuer !== null) {
            return $issuer;
        }

        $issuers = $verifactu->resolver()->issuers();
        if (count($issuers) === 1) {
            return $issuers[0];
        }

        throw new InvalidArgumentException(sprintf(
            '%s; pass --issuer=<NIF>.',
            $issuers === [] ? 'No VERI*FACTU issuer is configured' : sprintf('%d VERI*FACTU issuers are configured', count($issuers)),
        ));
    }

    /** A string argument; an empty string when the argument holds none. */
    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @throws InvalidArgumentException naming the argument or option
     */
    private static function nif(string $value, string $name): Nif
    {
        try {
            return Nif::parse(strtoupper(trim($value)));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(sprintf('%s "%s" is not a valid Spanish NIF: %s', $name, $value, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * A calendar date given as Y-m-d.
     *
     * @throws InvalidArgumentException naming the argument or option
     */
    private static function date(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('%s "%s" is not a date in the format Y-m-d (for example 2026-01-31).', $name, $value));
        }

        return $date;
    }
}
