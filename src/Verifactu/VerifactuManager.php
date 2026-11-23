<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;

/**
 * The entry point to the Spanish VERI*FACTU support of einvoicing.verifactu: the checked
 * settings, the issuer resolver and the installed AEAT schemas.
 *
 * The application is held inside a closure, so the configuration and its secrets stay out of
 * var_export(), print_r() and var_dump() output.
 */
final class VerifactuManager
{
    /** The folder of the AEAT schemas below storage_path() when none is configured. */
    public const DEFAULT_SCHEMAS_PATH = 'einvoicing/verifactu';

    /** @var Closure(): Application */
    private readonly Closure $app;

    private ?VerifactuSchemas $schemas = null;

    private ?string $schemasDir = null;

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * @throws ConfigurationException when a setting is invalid
     */
    public function settings(): VerifactuSettings
    {
        $app = ($this->app)();

        return VerifactuSettings::fromConfig(
            $app->make('config'),
            $app->storagePath(self::DEFAULT_SCHEMAS_PATH),
        );
    }

    /**
     * The resolver bound in the container: the application's own, or the configuration resolver.
     */
    public function resolver(): VerifactuIssuerResolver
    {
        return ($this->app)()->make(VerifactuIssuerResolver::class);
    }

    /**
     * The installed AEAT schema set, checked against its pinned checksums.
     *
     * @throws ConfigurationException when the set is not installed or does not match
     */
    public function schemas(): VerifactuSchemas
    {
        $dir = $this->settings()->schemasDir;
        if ($this->schemas !== null && $this->schemasDir === $dir) {
            return $this->schemas;
        }

        $schemas = new VerifactuSchemas($dir);
        try {
            $schemas->check();
        } catch (RulesNotInstalled $exception) {
            throw new ConfigurationException(
                'The AEAT VERI*FACTU schemas are not installed in "'.$dir.'" ('.$exception->getMessage().'). Run: php artisan einvoicing:verifactu-install-schemas',
                0,
                $exception,
            );
        }
        $this->schemasDir = $dir;

        return $this->schemas = $schemas;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['schemasChecked' => $this->schemas !== null];
    }
}
