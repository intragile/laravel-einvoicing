<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\Verifactu\ChainKey;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\ChainConflict;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\InvalidRecord;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\InvoiceId;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use InvalidArgumentException;

/**
 * Starts the AEAT chain of an issuer from the last record that the software used before this one
 * registered with AEAT, so the first record written here links to it (RegistroAnterior).
 *
 * The chain is that of the issuer, the installation (einvoicing.verifactu.system
 * .installation_number unless --installation is given) and the configured environment. The given
 * invoice and Huella are trusted as stated: take them from the previous software or from an AEAT
 * lookup. A chain that holds a record or a seed already is refused, as is an issuer on the
 * B2Brouter driver, whose chain B2Brouter starts itself.
 */
final class VerifactuSeedChainCommand extends Command
{
    use InteractsWithVerifactu;

    protected $signature = 'einvoicing:verifactu-seed-chain
        {issuer : The issuer NIF}
        {number : The invoice number of the last record the previous software registered}
        {date : Its issue date, Y-m-d}
        {hash : Its Huella: 64 upper-case hexadecimal characters}
        {--installation= : The installation number of the chain; defaults to einvoicing.verifactu.system.installation_number}';

    protected $description = 'Seed a VERI*FACTU chain with the last record registered by the previous software';

    public function handle(VerifactuManager $verifactu): int
    {
        try {
            $issuer = self::nif($this->stringArgument('issuer'), 'The issuer');
            $date = self::date($this->stringArgument('date'), 'The date');
            $hash = $this->stringArgument('hash');
            if (preg_match('/\A[0-9A-F]{64}\z/', $hash) !== 1) {
                throw new InvalidArgumentException(sprintf('The hash "%s" must be 64 upper-case hexadecimal characters (a SHA-256 Huella).', $hash));
            }
            $last = new InvoiceId($issuer, $this->stringArgument('number'), $date);

            $settings = $verifactu->settings();
            $context = $verifactu->resolver()->resolve($issuer);
            if ($context->driver === IssuerDriver::B2Brouter) {
                throw new InvalidArgumentException(sprintf('Issuer %s uses the B2Brouter driver; B2Brouter starts its chain from the first record it writes, so it is not seeded here.', $issuer->value));
            }
            if ($context->environment !== $settings->environment) {
                throw new ConfigurationException(sprintf(
                    'The issuer resolver returned issuer %s for the %s environment, but einvoicing.verifactu.environment is %s.',
                    $issuer->value,
                    $context->environment->value,
                    $settings->environment->value,
                ));
            }

            $installation = $this->option('installation');
            $installation = is_string($installation) && $installation !== '' ? $installation : $settings->system()->installationNumber;
            $key = new ChainKey($issuer, $installation, $settings->environment);

            $verifactu->store()->seed($key, $last, $hash);
        } catch (InvalidArgumentException|InvalidRecord|ConfigurationException|UnknownIssuer|ChainConflict $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Chain %s is seeded: its first record will link to invoice %s of %s (Huella %s).',
            $key->toString(),
            $last->number,
            $date->format('Y-m-d'),
            $hash,
        ));

        return self::SUCCESS;
    }
}
