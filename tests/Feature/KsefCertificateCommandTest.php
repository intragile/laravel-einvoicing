<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\CertificateCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class KsefCertificateCommandTest extends TestCase
{
    use KsefFiles;

    private const SERIAL = '1122334455667788';

    private FakeKsefApi $api;

    /** The CSR KSeF received, PEM-encoded. */
    private ?string $csr = null;

    /**
     * Binds a transport authenticating with a generated certificate, over canned enrolment
     * answers that issue a certificate for the CSR sent.
     */
    private function bindEnrolment(): void
    {
        $files = $this->configureKsefCertificate();
        $credentials = new CertificateCredentials(
            (string) file_get_contents($files['certificate']),
            (string) file_get_contents($files['private_key']),
            self::KSEF_PASSPHRASE,
        );
        $this->api = (new FakeKsefApi)->withCertificateAuthentication()
            ->on('GET', '#\A/certificates/enrollments/data\z#', FakeKsefApi::json(200, [
                'commonName' => 'Test Seal',
                'countryName' => 'PL',
                'organizationName' => 'Test Seal',
                'organizationIdentifier' => 'VATPL-'.FakeKsefApi::NIP,
            ]))
            ->on('POST', '#\A/certificates/enrollments\z#', function (RequestInterface $request): ResponseInterface {
                $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $this->csr = "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split((string) $body['csr'], 64, "\n")."-----END CERTIFICATE REQUEST-----\n";

                return FakeKsefApi::json(202, ['referenceNumber' => '20261121-EH-1B2C3D4E5F-6A7B8C9D0E-11', 'timestamp' => '2026-11-21T10:00:00+00:00']);
            })
            ->on('GET', '#\A/certificates/enrollments/[A-Za-z0-9-]+\z#', FakeKsefApi::json(200, [
                'requestDate' => '2026-11-21T10:00:00+00:00',
                'status' => ['code' => 200, 'description' => 'Wniosek obsłużony'],
                'certificateSerialNumber' => self::SERIAL,
            ]))
            ->on('POST', '#\A/certificates/retrieve\z#', fn (): ResponseInterface => FakeKsefApi::json(200, [
                'certificates' => [[
                    'certificate' => $this->issue(),
                    'certificateName' => 'Offline Test',
                    'certificateSerialNumber' => self::SERIAL,
                    'certificateType' => 'Offline',
                ]],
            ]));
        $this->app->instance(KsefTransport::class, $this->api->transport(credentials: $credentials));
    }

    /**
     * Signs the received CSR with a throwaway issuer and returns the certificate as Base64 DER.
     */
    private function issue(): string
    {
        $dir = $this->emptyDir();
        $config = $dir.'/openssl.cnf';
        file_put_contents($config, "[ req ]\ndefault_bits = 2048\ndistinguished_name = dn\n[ dn ]\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $issuerKey = openssl_pkey_new($options);
        if ($issuerKey === false || $this->csr === null) {
            throw new RuntimeException('The test issuer could not be set up.');
        }
        $certificate = openssl_csr_sign($this->csr, null, $issuerKey, 365, $options, (int) hexdec(self::SERIAL));
        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('The test certificate could not be issued.');
        }

        return (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    /**
     * @return array{certificate: string, private_key: string}
     */
    private function offlinePaths(): array
    {
        $dir = $this->emptyDir();
        $paths = ['certificate' => $dir.'/offline-certificate.pem', 'private_key' => $dir.'/offline-key.pem'];
        config()->set('einvoicing.ksef.offline_certificate', $paths + ['passphrase' => null]);

        return $paths;
    }

    public function test_certificate_command_writes_0600_and_prints_no_key(): void
    {
        $this->bindEnrolment();
        $paths = $this->offlinePaths();

        $exitCode = Artisan::call('einvoicing:ksef-certificate', ['--type' => 'offline', '--algorithm' => 'ec', '--name' => 'Offline Test']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString(self::SERIAL, $output);
        $this->assertStringContainsString($paths['certificate'], $output);
        $this->assertStringContainsString($paths['private_key'], $output);
        $this->assertStringNotContainsString('PRIVATE KEY', $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);

        $key = (string) file_get_contents($paths['private_key']);
        $certificate = (string) file_get_contents($paths['certificate']);
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $key);
        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $certificate);
        $this->assertTrue(openssl_x509_check_private_key($certificate, $key));
        $this->assertStringNotContainsString(substr(preg_replace('/-----[^-]+-----|\s+/', '', $key) ?? '', 0, 40), $output);
        $this->assertSame(0600, fileperms($paths['private_key']) & 0777);
        $this->assertSame(0600, fileperms($paths['certificate']) & 0777);

        // The written pair is the configured offline certificate now.
        $this->app->forgetInstance(KsefManager::class);
        $this->assertNotNull($this->app->make(KsefManager::class)->offlineCertificate());
    }

    public function test_certificate_command_encrypts_the_key_with_the_configured_passphrase(): void
    {
        $this->bindEnrolment();
        $paths = $this->offlinePaths();
        config()->set('einvoicing.ksef.offline_certificate.passphrase', 'offline-'.self::SECRET_MARK);

        $exitCode = Artisan::call('einvoicing:ksef-certificate', ['--type' => 'offline']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        $key = (string) file_get_contents($paths['private_key']);
        $this->assertStringContainsString('-----BEGIN ENCRYPTED PRIVATE KEY-----', $key);
        $this->assertFalse(@openssl_pkey_get_private($key));
        $this->assertNotFalse(openssl_pkey_get_private($key, 'offline-'.self::SECRET_MARK));
        $this->app->forgetInstance(KsefManager::class);
        $this->assertNotNull($this->app->make(KsefManager::class)->offlineCertificate());
    }

    public function test_failed_enrolment_says_the_certificate_may_have_been_issued(): void
    {
        // Configures the certificate credentials; the canned answers are replaced below.
        $this->bindEnrolment();
        $paths = $this->offlinePaths();
        // The status reads after the accepted enrolment request fail with server errors.
        $credentials = new CertificateCredentials(
            (string) file_get_contents((string) config('einvoicing.ksef.credentials.certificate')),
            (string) file_get_contents((string) config('einvoicing.ksef.credentials.private_key')),
            self::KSEF_PASSPHRASE,
        );
        $api = (new FakeKsefApi)->withCertificateAuthentication()
            ->on('GET', '#\A/certificates/enrollments/data\z#', FakeKsefApi::json(200, ['commonName' => 'Test Seal', 'countryName' => 'PL']))
            ->on('POST', '#\A/certificates/enrollments\z#', FakeKsefApi::json(202, ['referenceNumber' => '20261121-EH-1B2C3D4E5F-6A7B8C9D0E-11']))
            ->on('GET', '#\A/certificates/enrollments/[A-Za-z0-9-]+\z#', FakeKsefApi::problem(500, 0, 'Internal error'), FakeKsefApi::problem(500, 0, 'Internal error'), FakeKsefApi::problem(500, 0, 'Internal error'), FakeKsefApi::problem(500, 0, 'Internal error'));
        $this->app->instance(KsefTransport::class, $api->transport(credentials: $credentials));

        $exitCode = Artisan::call('einvoicing:ksef-certificate', ['--type' => 'offline']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('may have issued the certificate', $output);
        $this->assertStringContainsString('revoke', $output);
        $this->assertFileDoesNotExist($paths['private_key']);
    }

    public function test_certificate_command_refuses_overwrite(): void
    {
        $this->bindEnrolment();
        $paths = $this->offlinePaths();
        file_put_contents($paths['certificate'], 'existing certificate');

        $exitCode = Artisan::call('einvoicing:ksef-certificate', ['--type' => 'offline']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($paths['certificate'], $output);
        $this->assertStringContainsString('exists', $output);
        $this->assertSame('existing certificate', file_get_contents($paths['certificate']));
        $this->assertFileDoesNotExist($paths['private_key']);
        // Nothing was enrolled.
        $this->assertSame([], $this->api->paths());
    }

    public function test_certificate_command_needs_certificate_authentication(): void
    {
        config()->set('einvoicing.ksef.nip', FakeKsefApi::NIP);
        config()->set('einvoicing.ksef.credentials', ['certificate' => null, 'private_key' => null, 'passphrase' => null, 'token' => FakeKsefApi::TOKEN]);
        $api = (new FakeKsefApi)->withTokenAuthentication();
        $this->app->instance(KsefTransport::class, $api->transport());
        $paths = $this->offlinePaths();

        $exitCode = Artisan::call('einvoicing:ksef-certificate', ['--type' => 'offline']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('certificate (XAdES)', $output);
        $this->assertStringNotContainsString(FakeKsefApi::TOKEN, $output);
        $this->assertFileDoesNotExist($paths['private_key']);
        $this->assertFileDoesNotExist($paths['certificate']);
    }

    public function test_certificate_command_refuses_an_unknown_type(): void
    {
        $this->offlinePaths();

        $this->artisan('einvoicing:ksef-certificate', ['--type' => 'seal'])
            ->expectsOutputToContain('--type')
            ->assertFailed();
    }
}
