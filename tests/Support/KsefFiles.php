<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use RuntimeException;

/**
 * Generates throwaway EC certificates and private keys for the KSeF configuration tests and
 * writes them, readable by the owner only, into a temporary directory removed after the test.
 *
 * @phpstan-require-extends TestCase
 */
trait KsefFiles
{
    /** A synthetic NIP with a valid check digit. */
    protected const KSEF_NIP = '5265877635';

    /** The passphrase of every generated private key; it carries the leak marker. */
    protected const KSEF_PASSPHRASE = 'pass-SECRETMARK';

    /**
     * Writes a self-signed certificate valid for the given number of days (0: expired at once) and its private key,
     * encrypted with KSEF_PASSPHRASE unless $passphrase is null.
     *
     * @return array{certificate: string, private_key: string}
     */
    protected function ksefCertificateFiles(int $days = 365, ?string $passphrase = self::KSEF_PASSPHRASE): array
    {
        $dir = $this->emptyDir();
        $config = $dir.'/openssl.cnf';
        file_put_contents($config, "[ req ]\ndefault_bits = 2048\ndistinguished_name = dn\n[ dn ]\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];

        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate a test key.');
        }
        $requestKey = $key;
        $csr = openssl_csr_new(['countryName' => 'PL', 'commonName' => 'Test Seal'], $requestKey, $options);
        if ($csr === false || $csr === true) {
            throw new RuntimeException('OpenSSL could not create a test certificate request.');
        }
        $certificate = openssl_csr_sign($csr, null, $key, $days, $options, random_int(1, PHP_INT_MAX));
        if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $keyPem, $passphrase, $options)) {
            throw new RuntimeException('OpenSSL could not export the test certificate.');
        }

        $paths = ['certificate' => $dir.'/certificate.pem', 'private_key' => $dir.'/private-key.pem'];
        file_put_contents($paths['certificate'], $certificatePem);
        file_put_contents($paths['private_key'], $keyPem);
        chmod($paths['private_key'], 0600);

        return $paths;
    }

    /**
     * Configures KSeF with certificate credentials read from generated files.
     *
     * @return array{certificate: string, private_key: string}
     */
    protected function configureKsefCertificate(int $days = 365): array
    {
        $files = $this->ksefCertificateFiles($days);
        config()->set('einvoicing.ksef.nip', self::KSEF_NIP);
        config()->set('einvoicing.ksef.credentials', $files + ['passphrase' => self::KSEF_PASSPHRASE, 'token' => null]);

        return $files;
    }
}
