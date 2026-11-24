<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Verifactu;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Spain\Verifactu\AeatCredentials;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\Aeat\AeatConfig;
use IntrAgile\EInvoicing\Transport\Aeat\ClientCertificate;
use IntrAgile\EInvoicing\Transport\Aeat\MaterializedCertificate;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\VerifactuPolling;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use InvalidArgumentException;
use RuntimeException;

/**
 * The clients that reach AEAT directly and through B2Brouter, built from einvoicing.verifactu.
 *
 * AEAT clients present the issuer's certificate over mutual TLS. The certificate is written to a
 * private folder below storage_path() because Guzzle takes only file paths; the files live as
 * long as the client is cached and are deleted by forget() and when this object is destroyed.
 * Clients are cached per credential set: the certificate and key paths with the modification time
 * and size of each file, the port and the environment. A certificate renewed in place therefore
 * gets a fresh client, and the files of the old one are deleted. Certificate files older than a
 * day left in a folder by a worker that was killed are removed before new ones are written.
 *
 * An AeatClient or B2BrouterVerifactu instance bound in the container is used instead. Bind this
 * class as a singleton, so a process keeps one cache. The server certificate is always verified,
 * against einvoicing.verifactu.aeat.ca_bundle or else the system store.
 */
final class VerifactuClients
{
    /** The folder below storage_path() that holds the written certificates. */
    public const TLS_PATH = 'einvoicing/verifactu/tls';

    /** B2Brouter tax report polls per call, and the wait between them: about 45 s in all. */
    public const POLL_ATTEMPTS = 15;

    public const POLL_WAIT_MS = 3000;

    /** Seconds after which a written certificate file is taken as left over by a killed worker. */
    private const STALE_AFTER = 86400;

    /** @var Closure(): Application */
    private readonly Closure $app;

    /**
     * The cached clients by credential set (paths, port, environment); the version is the
     * modification time and size of the files they were built from.
     *
     * @var array<string, array{version: string, certificate: MaterializedCertificate, http: HttpClient, aeat: ?AeatClient}>
     */
    private array $entries = [];

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    public function __destruct()
    {
        $this->forget();
    }

    /**
     * The AEAT client of an issuer that submits directly to AEAT.
     *
     * @throws ConfigurationException when the issuer has no usable AEAT credentials, its
     *                                certificate cannot be loaded, a setting is invalid or the
     *                                schemas are not installed
     * @throws TransportUnavailable when the certificate cannot be written for the HTTP client
     */
    public function aeat(IssuerContext $issuer): AeatClient
    {
        $app = ($this->app)();
        if ($app->bound(AeatClient::class)) {
            return $app->make(AeatClient::class);
        }

        $config = $this->config($issuer, $this->manager()->schemas());
        $slot = $this->entry($issuer, $config);

        return $this->entries[$slot]['aeat'] ??= new AeatClient($config, $this->entries[$slot]['http']);
    }

    /**
     * The HTTP client that presents the issuer's certificate to AEAT, with the response size cap
     * of the AEAT client and the certificate password kept out of its messages.
     *
     * @throws ConfigurationException when the issuer has no usable AEAT credentials, its
     *                                certificate cannot be loaded or a setting is invalid
     * @throws TransportUnavailable when the certificate cannot be written
     */
    public function aeatHttpClient(IssuerContext $issuer): HttpClient
    {
        $config = $this->config($issuer, new VerifactuSchemas($this->manager()->settings()->schemasDir));

        return $this->entries[$this->entry($issuer, $config)]['http'];
    }

    /**
     * The B2Brouter VERI*FACTU client of the transport named by
     * einvoicing.verifactu.b2brouter.transport. One call polls a tax report for about 45 seconds,
     * which fits a queue worker's default timeout.
     *
     * @throws ConfigurationException when the setting is missing, names no transport, or names one
     *                                that is not a B2Brouter transport
     */
    public function b2brouter(): B2BrouterVerifactu
    {
        $app = ($this->app)();
        if ($app->bound(B2BrouterVerifactu::class)) {
            return $app->make(B2BrouterVerifactu::class);
        }

        $name = $this->manager()->settings()->b2brouterTransport ?? throw new ConfigurationException(
            'Set einvoicing.verifactu.b2brouter.transport to the name of a B2Brouter transport of einvoicing.transports.',
        );
        $einvoicing = $app->make(EInvoicingManager::class);
        $config = $einvoicing->driverConfig($name);
        if (! $config instanceof B2BrouterConfig) {
            throw new ConfigurationException(sprintf('einvoicing.verifactu.b2brouter.transport names "%s", which is not a B2Brouter transport.', $name));
        }

        return new B2BrouterVerifactu($config, $einvoicing->httpClient(), new VerifactuPolling(self::POLL_ATTEMPTS, self::POLL_WAIT_MS));
    }

    /**
     * Drops every cached AEAT client and deletes its certificate files.
     */
    public function forget(): void
    {
        foreach ($this->entries as $entry) {
            $entry['certificate']->dispose();
        }
        $this->entries = [];
    }

    /**
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['cachedAeatClients' => count($this->entries)];
    }

    private function manager(): VerifactuManager
    {
        return ($this->app)()->make(VerifactuManager::class);
    }

    /**
     * @throws ConfigurationException when the issuer submits through B2Brouter or has no credentials
     */
    private function config(IssuerContext $issuer, VerifactuSchemas $schemas): AeatConfig
    {
        try {
            return AeatConfig::forIssuer($issuer, $schemas);
        } catch (InvalidArgumentException $exception) {
            throw new ConfigurationException(sprintf('Issuer %s cannot submit to AEAT: %s', $issuer->nif->value, $exception->getMessage()), 0, $exception);
        }
    }

    /**
     * The cache slot of the issuer's credential set, built when missing or when a file changed.
     */
    private function entry(IssuerContext $issuer, AeatConfig $config): string
    {
        /** @var AeatCredentials $credentials checked by AeatConfig::forIssuer() */
        $credentials = $issuer->credentials;
        $slot = implode("\0", [$credentials->certificatePath, $credentials->privateKeyPath ?? '', $credentials->kind->value, $issuer->environment->value]);
        $version = self::version($credentials->certificatePath).'|'.($credentials->privateKeyPath === null ? '' : self::version($credentials->privateKeyPath));

        $cached = $this->entries[$slot] ?? null;
        if ($cached !== null && $cached['version'] === $version) {
            return $slot;
        }
        if ($cached !== null) {
            $cached['certificate']->dispose();
            unset($this->entries[$slot]);
        }

        try {
            $certificate = ClientCertificate::fromCredentials($credentials);
        } catch (InvalidArgumentException $exception) {
            throw new ConfigurationException(sprintf('The AEAT client certificate of issuer %s cannot be loaded: %s', $issuer->nif->value, $exception->getMessage()), 0, $exception);
        }

        $directory = $this->directory(sha1($slot."\0".$version));
        try {
            $materialized = $certificate->materialize($directory);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw new TransportUnavailable(sprintf('The AEAT client certificate cannot be written to %s: %s', $directory, $exception->getMessage()), $exception);
        }

        $this->entries[$slot] = [
            'version' => $version,
            'certificate' => $materialized,
            'http' => $this->httpClient($materialized, $config, $credentials->password()),
            'aeat' => null,
        ];

        return $slot;
    }

    /**
     * A private folder for the certificate files of one credential set, cleared of files left by
     * a killed worker.
     *
     * @throws TransportUnavailable when the folder cannot be created
     */
    private function directory(string $name): string
    {
        $directory = ($this->app)()->storagePath(self::TLS_PATH.'/'.$name);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new TransportUnavailable(sprintf('The folder %s for the AEAT client certificate cannot be created.', $directory));
        }
        @chmod($directory, 0700);

        $cutoff = time() - self::STALE_AFTER;
        foreach (glob($directory.'/aeat-*.pem') ?: [] as $file) {
            if (preg_match('~/aeat-[0-9a-f]+-(?:certificate|key)\.pem\z~', $file) === 1 && is_file($file) && (int) @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }

        return $directory;
    }

    /**
     * @throws ConfigurationException when a timeout setting is invalid
     */
    private function httpClient(MaterializedCertificate $certificate, AeatConfig $config, ?string $password): HttpClient
    {
        $settings = ($this->app)()->make('config');
        $caBundle = $settings->get('einvoicing.verifactu.aeat.ca_bundle');
        $caBundle = is_string($caBundle) && $caBundle !== '' ? $caBundle : null;
        $timeout = self::seconds($settings->get('einvoicing.verifactu.aeat.timeout') ?? 30, 'einvoicing.verifactu.aeat.timeout');
        $connectTimeout = self::seconds($settings->get('einvoicing.verifactu.aeat.connect_timeout') ?? 10, 'einvoicing.verifactu.aeat.connect_timeout');

        $options = $certificate->guzzleOptions($caBundle, $timeout);
        // The core fixes the connect timeout at min(10, timeout); the setting replaces it.
        $options['connect_timeout'] = min($connectTimeout, $timeout);
        $options['http_errors'] = false;
        $factory = new HttpFactory;

        return new HttpClient(new Client($options), $factory, $factory, $config->maxResponseBytes, $password === null ? [] : [$password]);
    }

    /**
     * @throws ConfigurationException when the value is not a positive number of seconds
     */
    private static function seconds(mixed $value, string $key): float
    {
        $seconds = (float) ConfigValue::number($value, $key);
        if (! ($seconds > 0.0)) {
            throw new ConfigurationException(sprintf('%s must be a positive number of seconds.', $key));
        }

        return $seconds;
    }

    /** The modification time and size of a file, or a marker when it cannot be read. */
    private static function version(string $path): string
    {
        clearstatcache(true, $path);
        $modified = @filemtime($path);
        $size = @filesize($path);

        return $modified === false || $size === false ? 'unreadable' : $modified.':'.$size;
    }
}
