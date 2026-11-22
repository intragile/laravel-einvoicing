<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Transport\Exception\DocumentRejected;
use IntrAgile\EInvoicing\Transport\Ksef\Certificates\EnrolledCertificate;
use IntrAgile\EInvoicing\Transport\Ksef\Certificates\KeyAlgorithm;
use IntrAgile\EInvoicing\Transport\Ksef\Certificates\KsefCertificateType;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SensitiveParameter;

/**
 * Enrols a KSeF certificate for the configured context and writes the certificate and its private
 * key, each readable by the owner only (mode 0600), to the paths configured for that type:
 * einvoicing.ksef.offline_certificate for an Offline certificate and einvoicing.ksef.credentials
 * for an Authentication certificate, unless --certificate and --private-key name other paths.
 *
 * KSeF enrols certificates only for a context authenticated with a certificate (XAdES), so the
 * configured credentials must be a certificate, not a KSeF token. The command refuses to overwrite
 * an existing file and checks the paths before it enrols. The private key exists only in memory
 * until it is written; it is never printed. When the type's passphrase setting (offline_certificate.
 * passphrase or credentials.passphrase) is set, the key is written encrypted with it.
 */
final class KsefCertificateCommand extends Command
{
    protected $signature = 'einvoicing:ksef-certificate
        {--type=offline : The certificate type: offline or authentication}
        {--algorithm=ec : The key algorithm: ec (P-256) or rsa (2048 bits)}
        {--name= : The certificate name in KSeF; the type with the date and time by default}
        {--certificate= : Where to write the certificate; the configured path by default}
        {--private-key= : Where to write the private key; the configured path by default}';

    protected $description = 'Enrol a KSeF Offline or Authentication certificate and write it with its private key';

    public function handle(KsefManager $ksef): int
    {
        $type = match ($this->option('type')) {
            'offline' => KsefCertificateType::Offline,
            'authentication' => KsefCertificateType::Authentication,
            default => null,
        };
        $algorithm = match ($this->option('algorithm')) {
            'ec' => KeyAlgorithm::Ec,
            'rsa' => KeyAlgorithm::Rsa,
            default => null,
        };
        if ($type === null) {
            $this->components->error('--type must be "offline" or "authentication".');

            return self::FAILURE;
        }
        if ($algorithm === null) {
            $this->components->error('--algorithm must be "ec" or "rsa".');

            return self::FAILURE;
        }
        $name = $this->option('name');
        $name = is_string($name) && $name !== '' ? $name : null;

        $group = $type === KsefCertificateType::Offline ? 'einvoicing.ksef.offline_certificate' : 'einvoicing.ksef.credentials';
        $certificatePath = $this->path('certificate', "{$group}.certificate");
        $keyPath = $this->path('private-key', "{$group}.private_key");
        $problem = match (true) {
            $certificatePath === null => sprintf('Set %s.certificate or pass --certificate with the path to write the certificate to.', $group),
            $keyPath === null => sprintf('Set %s.private_key or pass --private-key with the path to write the private key to.', $group),
            $certificatePath === $keyPath => 'The certificate and the private key need two different paths.',
            default => self::pathProblem($certificatePath) ?? self::pathProblem($keyPath),
        };
        if ($problem !== null || $certificatePath === null || $keyPath === null) {
            $this->components->error((string) $problem);

            return self::FAILURE;
        }

        try {
            $enrolled = $ksef->transport()->certificates()->enroll($type, $algorithm, $name);
        } catch (DocumentRejected $exception) {
            // Without a previous exception KSeF rejected the enrolment request itself. A rejection
            // wrapping another one comes from reading back an issued certificate, and its message
            // names the serial number to revoke.
            $this->components->error($exception->getPrevious() === null
                ? sprintf('%s KSeF rejected the request, so no certificate was issued.', rtrim($exception->getMessage(), '.').'.')
                : $exception->getMessage());

            return self::FAILURE;
        } catch (ConfigurationException|LogicException|InvalidArgumentException $exception) {
            // Usually refused before the enrolment request. An InvalidArgumentException raised
            // after issuance names the issued serial number and says to revoke it.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            // A transport failure may come after KSeF accepted the enrolment. None of these
            // messages holds key material.
            $this->components->error(sprintf(
                '%s KSeF may have issued the certificate even so, and its private key is lost: look for it with KsefCertificates::query() (for example by its name) and revoke it if it is there.',
                rtrim($exception->getMessage(), '.').'.',
            ));

            return self::FAILURE;
        }

        $passphrase = config("{$group}.passphrase");

        return $this->store($enrolled, $certificatePath, $keyPath, is_string($passphrase) && $passphrase !== '' ? $passphrase : null);
    }

    /**
     * Writes the private key, encrypted with the passphrase configured for the type when there is
     * one, then the certificate.
     */
    private function store(EnrolledCertificate $enrolled, string $certificatePath, string $keyPath, #[SensitiveParameter] ?string $passphrase): int
    {
        $certificate = $enrolled->certificate;
        try {
            self::writeExclusive($keyPath, self::keyPem($enrolled, $passphrase));
        } catch (RuntimeException $exception) {
            $this->components->error(sprintf(
                'KSeF issued the certificate %s, but its private key could not be written: %s The key is gone, so revoke the certificate and enrol again.',
                $certificate->serialNumber,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }
        try {
            self::writeExclusive($certificatePath, $certificate->certificatePem);
        } catch (RuntimeException $exception) {
            $this->components->error(sprintf(
                'The private key of the certificate %s was written to %s, but the certificate could not be written: %s Retrieve it from KSeF by its serial number.',
                $certificate->serialNumber,
                $keyPath,
                $exception->getMessage(),
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'KSeF issued the %s certificate %s, valid from %s to %s.',
            $certificate->type->value,
            $certificate->serialNumber,
            $certificate->validFrom->format('Y-m-d H:i:s T'),
            $certificate->validTo->format('Y-m-d H:i:s T'),
        ));
        $this->components->twoColumnDetail('Certificate', $certificatePath);
        $this->components->twoColumnDetail('Private key (mode 0600)', $keyPath);

        return self::SUCCESS;
    }

    /**
     * The private key as PEM: unencrypted PKCS#8, or encrypted PKCS#8 (AES-256-CBC) with the
     * passphrase.
     *
     * @throws RuntimeException when OpenSSL cannot encrypt the key
     */
    private static function keyPem(EnrolledCertificate $enrolled, #[SensitiveParameter] ?string $passphrase): string
    {
        if ($passphrase === null) {
            return $enrolled->privateKeyPem();
        }
        $key = openssl_pkey_get_private($enrolled->privateKeyPem());
        if ($key === false || ! openssl_pkey_export($key, $pem, $passphrase, ['encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC]) || ! is_string($pem)) {
            while (openssl_error_string() !== false) {
                // Drain OpenSSL's error queue.
            }

            throw new RuntimeException('OpenSSL could not encrypt the private key with the configured passphrase.');
        }

        return $pem;
    }

    /**
     * The path given as an option, else the configured one, or null when neither is set.
     */
    private function path(string $option, string $key): ?string
    {
        $value = $this->option($option);
        if (! is_string($value) || trim($value) === '') {
            $value = config($key);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Why a file cannot be written at the path, or null when it can.
     */
    private static function pathProblem(string $path): ?string
    {
        if (file_exists($path) || is_link($path)) {
            return sprintf('The file "%s" exists; it is never overwritten. Move it away or choose another path.', $path);
        }
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            return sprintf('The directory "%s" does not exist or is not writable.', $directory);
        }

        return null;
    }

    /**
     * Creates the file, failing when it exists, readable and writable by the owner only, and
     * writes the contents.
     *
     * @throws RuntimeException when the file exists or cannot be created or written
     */
    private static function writeExclusive(string $path, #[SensitiveParameter] string $contents): void
    {
        $umask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($umask);
        }
        if ($handle === false) {
            throw new RuntimeException(sprintf('The file "%s" exists or cannot be created.', $path));
        }
        $written = chmod($path, 0600) && fwrite($handle, $contents) === strlen($contents) && fflush($handle);
        fclose($handle);
        if (! $written) {
            // A partly written key is not left behind.
            @unlink($path);

            throw new RuntimeException(sprintf('The file "%s" could not be written.', $path));
        }
    }
}
