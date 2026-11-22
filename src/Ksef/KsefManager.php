<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Ksef;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Exception\UnsafeXml;
use IntrAgile\EInvoicing\Exception\UnsupportedDocument;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\SendKsefInvoice;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Poland\KsefEnvironment;
use IntrAgile\EInvoicing\Poland\Nip;
use IntrAgile\EInvoicing\Syntax\Fa3\Fa3Writer;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\CertificateCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\ContextIdentifier;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\Credentials;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\TokenCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use IntrAgile\EInvoicing\Transport\Ksef\KsefConfig;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Latarnia\Latarnia;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Builds the KSeF clients of einvoicing.ksef: the KSeF transport with its database cursor store,
 * the Latarnia availability client and the offline certificate that signs KOD II links.
 *
 * Certificates and private keys are read from the files the configuration points to. Errors name
 * the setting and the file path, never the file contents, the passphrase or the token. The KSeF
 * HTTP client has the timeouts of einvoicing.http and its own response size cap,
 * einvoicing.ksef.max_response_bytes, since KSeF export parts exceed the Peppol cap.
 *
 * The transport is built on first use and cached, so configuration changed at run time affects it
 * only in a new process. It makes real requests: EInvoicing::fake() does not replace it. The
 * application is held inside a closure, so the configuration and its secrets stay out of
 * var_export(), print_r() and var_dump() output.
 */
final class KsefManager
{
    /** @var Closure(): Application */
    private readonly Closure $app;

    private ?KsefTransport $transport = null;

    public function __construct(Application $app)
    {
        $this->app = static fn (): Application => $app;
    }

    /**
     * The KSeF transport of the configured context, environment and credentials, reading its
     * inbox through the bound KsefCursorStore (the database store unless the application binds
     * another). A KsefTransport instance bound in the container is used instead.
     *
     * @throws ConfigurationException when a setting is missing or invalid, or a file cannot be read
     */
    public function transport(): KsefTransport
    {
        $app = ($this->app)();
        if ($app->bound(KsefTransport::class)) {
            return $app->make(KsefTransport::class);
        }
        if ($this->transport !== null) {
            return $this->transport;
        }
        $config = $this->ksefConfig();

        return $this->transport = KsefTransport::create(
            $config,
            $this->httpClient($config->secrets()),
            ($this->app)()->make(KsefCursorStore::class),
        );
    }

    /**
     * Stores an FA(3) invoice as a queued KSeF row and dispatches the job that sends it once the
     * open database transaction, if any, commits. The row keeps the exact bytes that are sent, so
     * a resend after an unknown outcome is answered by KSeF as a duplicate of the first copy.
     *
     * The row's sender and receiver are the seller NIP and the buyer's identifier, its document
     * type "FA (3)" and its process "ksef". Its invoicing_mode is the requested mode ("Offline" or
     * "Online") until KSeF reports its own classification; an offline invoice carries its offline24
     * deadline at once, computed without announced outages (see einvoicing:ksef-deadlines). Nothing
     * is sent to KSeF here and the invoice is not validated: the send job validates it and fails
     * the row when it is invalid.
     *
     * @param  Invoice|string  $invoice  an FA(3) file, or an invoice to write as one
     * @param  bool  $offline  true for an invoice issued in offline mode
     * @param  string|null  $reference  the application's own reference, e.g. the invoice number
     *
     * @throws UnsafeXml when the file carries a DOCTYPE declaration or declares entities
     * @throws UnsupportedDocument when it is not well-formed XML or not an FA(3) invoice
     * @throws InvalidArgumentException when the invoice cannot be written as FA(3), or it lacks the
     *                                  seller NIP or a valid issue date (P_1)
     * @throws ConfigurationException when a numeric job setting is invalid
     */
    public function queue(Invoice|string $invoice, bool $offline = false, ?string $reference = null): OutboundMessage
    {
        $xml = is_string($invoice) ? $invoice : (new Fa3Writer)->write($invoice);
        $facts = KsefInvoiceFacts::read($xml);
        // Before the row is written, so an invalid setting leaves no queued row without a job.
        SendKsefInvoice::assertSettings();

        $message = new OutboundMessage([
            'transport' => EInvoicingManager::KSEF,
            'reference' => $reference,
            'sender' => $facts->sellerNip,
            'receiver' => $facts->buyer,
            'document_type' => SendKsefInvoice::DOCUMENT_TYPE,
            'process' => EInvoicingManager::KSEF,
            'xml' => $xml,
            'invoicing_mode' => $offline ? SendKsefInvoice::MODE_OFFLINE : SendKsefInvoice::MODE_ONLINE,
            'offline_deadline' => $offline ? $facts->offlineDeadline()->deadline : null,
        ]);
        $message->state = OutboundState::Queued;
        $message->save();

        SendKsefInvoice::schedule($message->id);

        return $message;
    }

    /**
     * The Latarnia client of the configured environment, or null for DEMO, which has none. A
     * Latarnia instance bound in the container is used instead, for any environment.
     *
     * @param  int|float|null  $timeout  seconds the connection and the whole request may take at
     *                                   most, below the einvoicing.http timeouts; null keeps those
     *
     * @throws ConfigurationException when a setting is invalid
     */
    public function latarnia(int|float|null $timeout = null): ?Latarnia
    {
        $app = ($this->app)();
        if ($app->bound(Latarnia::class)) {
            return $app->make(Latarnia::class);
        }
        $environment = $this->settings()->environment;
        if ($environment === KsefEnvironment::Demo) {
            return null;
        }

        $http = ($this->app)()->make(EInvoicingManager::class)->httpClient($this->settings()->maxResponseBytes, [], $timeout);

        return Latarnia::forEnvironment($environment, $http);
    }

    /**
     * The KSeF Offline certificate and its private key, or null when neither path is set.
     *
     * @throws ConfigurationException when only one path is set, a file cannot be read, or the key
     *                                does not open or does not belong to the certificate
     */
    public function offlineCertificate(): ?CertificateCredentials
    {
        $config = $this->section('offline_certificate');
        if (! self::filled($config['certificate'] ?? null) && ! self::filled($config['private_key'] ?? null)) {
            return null;
        }

        return $this->certificate('einvoicing.ksef.offline_certificate', $config);
    }

    /**
     * The checked settings that carry no credentials.
     *
     * @throws ConfigurationException when a setting is invalid
     */
    public function settings(): KsefSettings
    {
        $config = $this->config()->get('einvoicing.ksef');

        return KsefSettings::fromConfig(is_array($config) ? $config : []);
    }

    /**
     * The core KSeF configuration: environment, NIP context and credentials, read and checked
     * without making a request.
     *
     * @internal used by einvoicing:doctor
     *
     * @throws ConfigurationException when a setting is missing or invalid, or a file cannot be read
     */
    public function ksefConfig(): KsefConfig
    {
        return new KsefConfig($this->settings()->environment, $this->context(), $this->credentials());
    }

    /**
     * The HTTP client of KSeF and Latarnia: the einvoicing.http timeouts and the
     * einvoicing.ksef.max_response_bytes cap.
     *
     * @internal
     *
     * @param  list<string>  $secrets  values kept out of exception messages and body excerpts
     *
     * @throws ConfigurationException when an einvoicing.http or einvoicing.ksef setting is invalid
     */
    public function httpClient(#[SensitiveParameter] array $secrets = []): HttpClient
    {
        return ($this->app)()->make(EInvoicingManager::class)->httpClient($this->settings()->maxResponseBytes, $secrets);
    }

    /**
     * Whether einvoicing.ksef names a NIP, credentials or an offline certificate, so KSeF is in use.
     */
    public function isConfigured(): bool
    {
        $credentials = $this->section('credentials');
        $offline = $this->section('offline_certificate');

        return self::filled($this->config()->get('einvoicing.ksef.nip'))
            || self::filled($credentials['certificate'] ?? null)
            || self::filled($credentials['private_key'] ?? null)
            || self::filled($credentials['token'] ?? null)
            || self::filled($offline['certificate'] ?? null)
            || self::filled($offline['private_key'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['built' => $this->transport !== null];
    }

    /**
     * @throws ConfigurationException when the NIP is missing or not a valid NIP
     */
    private function context(): ContextIdentifier
    {
        $nip = $this->config()->get('einvoicing.ksef.nip');
        if (is_int($nip)) {
            $nip = (string) $nip;
        }
        if (! is_string($nip) || ! Nip::isValid(trim($nip))) {
            throw new ConfigurationException('einvoicing.ksef.nip must be the ten-digit NIP of the KSeF context, with a valid check digit.');
        }

        return ContextIdentifier::nip(trim($nip));
    }

    /**
     * @throws ConfigurationException when neither or both of a certificate and a token are set,
     *                                or the certificate cannot be read
     */
    private function credentials(): Credentials
    {
        $config = $this->section('credentials');
        $token = $config['token'] ?? null;
        $hasCertificate = self::filled($config['certificate'] ?? null) || self::filled($config['private_key'] ?? null);

        if (self::filled($token)) {
            if ($hasCertificate) {
                throw new ConfigurationException('einvoicing.ksef.credentials must hold either a certificate with its private key or a token, not both.');
            }

            return new TokenCredentials($token);
        }
        if (! $hasCertificate) {
            throw new ConfigurationException(
                'einvoicing.ksef.credentials must hold a certificate and its private key (certificate, private_key) or a KSeF token (token).',
            );
        }

        return $this->certificate('einvoicing.ksef.credentials', $config);
    }

    /**
     * A certificate and its private key read from the files of a settings group.
     *
     * @param  string  $prefix  the full key of the settings group
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException
     */
    private function certificate(string $prefix, #[SensitiveParameter] array $config): CertificateCredentials
    {
        [$certificatePath, $certificatePem] = self::readFile("{$prefix}.certificate", $config['certificate'] ?? null);
        [$keyPath, $keyPem] = self::readFile("{$prefix}.private_key", $config['private_key'] ?? null);
        $passphrase = $config['passphrase'] ?? null;
        $passphrase = self::filled($passphrase) ? $passphrase : null;

        if (@openssl_x509_read($certificatePem) === false) {
            self::clearOpenSslErrors();

            throw new ConfigurationException(sprintf('%s: the file "%s" is not a PEM-encoded X.509 certificate.', "{$prefix}.certificate", $certificatePath));
        }
        if (@openssl_pkey_get_private($keyPem, $passphrase) === false) {
            self::clearOpenSslErrors();
            if (self::isEncryptedKey($keyPem)) {
                throw new ConfigurationException(sprintf(
                    '%s: the encrypted private key in "%s" does not open with %s; the passphrase is missing or wrong.',
                    "{$prefix}.private_key",
                    $keyPath,
                    "{$prefix}.passphrase",
                ));
            }

            throw new ConfigurationException(sprintf('%s: the file "%s" is not a PEM-encoded private key.', "{$prefix}.private_key", $keyPath));
        }

        try {
            return new CertificateCredentials($certificatePem, $keyPem, $passphrase);
        } catch (InvalidArgumentException) {
            // The certificate and the key were read above, so only the pairing is left; the core
            // message is not repeated in any case.
            throw new ConfigurationException(sprintf(
                '%s: the private key in "%s" does not belong to the certificate in "%s".',
                "{$prefix}.private_key",
                $keyPath,
                $certificatePath,
            ));
        }
    }

    /**
     * Reads the file a setting points to.
     *
     * @return array{string, string} the path and the file contents
     *
     * @throws ConfigurationException when the setting is not set, or the file does not exist or
     *                                cannot be read; naming the setting and the path only
     */
    private static function readFile(string $key, mixed $path): array
    {
        if (! self::filled($path)) {
            throw new ConfigurationException(sprintf('%s is missing or empty; set it to the path of the file.', $key));
        }
        $contents = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;
        if ($contents === false || $contents === '') {
            throw new ConfigurationException(sprintf('%s: the file "%s" does not exist, is empty or cannot be read.', $key, $path));
        }

        return [$path, $contents];
    }

    /**
     * Whether a PEM private key is encrypted, in the PKCS#8 or the traditional OpenSSL form.
     */
    private static function isEncryptedKey(#[SensitiveParameter] string $pem): bool
    {
        return str_contains($pem, '-----BEGIN ENCRYPTED PRIVATE KEY-----') || str_contains($pem, 'Proc-Type: 4,ENCRYPTED');
    }

    /**
     * A settings group of einvoicing.ksef, or an empty array when it is not an array.
     *
     * @return array<array-key, mixed>
     */
    private function section(string $name): array
    {
        $section = $this->config()->get("einvoicing.ksef.{$name}");

        return is_array($section) ? $section : [];
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function filled(#[SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Drains OpenSSL's error queue, so a failure here is not reported later by an unrelated call.
     */
    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Discard.
        }
    }

    private function config(): Repository
    {
        return ($this->app)()->make(Repository::class);
    }
}
