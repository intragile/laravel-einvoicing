<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Poland\KsefEnvironment;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\CertificateCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\TokenCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Latarnia\Latarnia;

final class KsefManagerTest extends TestCase
{
    use KsefFiles;

    private const TOKEN = 'token-SECRETMARK-0123456789';

    private function ksef(): KsefManager
    {
        // A fresh manager per call, so a changed setting is read again.
        $this->app->forgetInstance(KsefManager::class);

        return $this->app->make(KsefManager::class);
    }

    private function assertConfigurationError(string $key, ?string $path = null): ConfigurationException
    {
        try {
            $this->ksef()->ksefConfig();
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($key, $exception->getMessage());
            if ($path !== null) {
                self::assertStringContainsString($path, $exception->getMessage());
            }
            self::assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $exception->getMessage());
            self::assertStringNotContainsString('CERTIFICATE-----', $exception->getMessage());

            return $exception;
        }
        self::fail("A configuration error naming {$key} was expected.");
    }

    public function test_default_environment_is_test(): void
    {
        self::assertSame('test', config('einvoicing.ksef.environment'));
        self::assertSame(KsefEnvironment::Test, $this->ksef()->settings()->environment);

        config()->set('einvoicing.ksef.environment', null);
        self::assertSame(KsefEnvironment::Test, $this->ksef()->settings()->environment);
        config()->set('einvoicing.ksef.environment', '');
        self::assertSame(KsefEnvironment::Test, $this->ksef()->settings()->environment);

        self::assertSame(15, $this->ksef()->settings()->inboundMinIntervalMinutes);
        self::assertSame(24, $this->ksef()->settings()->deadlineWarningHours);
    }

    public function test_production_requires_explicit_setting(): void
    {
        config()->set('einvoicing.ksef.environment', 'production');
        self::assertSame(KsefEnvironment::Production, $this->ksef()->settings()->environment);
        config()->set('einvoicing.ksef.environment', 'demo');
        self::assertSame(KsefEnvironment::Demo, $this->ksef()->settings()->environment);

        foreach (['prod', 'PROD', 'live', 'Production ', 1] as $value) {
            config()->set('einvoicing.ksef.environment', $value);
            try {
                $this->ksef()->settings();
                self::fail('An environment other than test, demo or production was accepted.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('einvoicing.ksef.environment', $exception->getMessage());
            }
        }
    }

    public function test_certificate_credentials_from_files(): void
    {
        $files = $this->configureKsefCertificate();

        $config = $this->ksef()->ksefConfig();
        self::assertInstanceOf(CertificateCredentials::class, $config->credentials);
        self::assertSame(file_get_contents($files['certificate']), $config->credentials->certificatePem);
        self::assertSame(['type' => 'Nip', 'value' => self::KSEF_NIP], $config->context->json());
        self::assertSame(KsefEnvironment::Test, $config->environment);

        $manager = $this->ksef();
        self::assertInstanceOf(KsefTransport::class, $manager->transport());
        self::assertSame($manager->transport(), $manager->transport(), 'The transport is built once.');

        // A key without a passphrase is read as well.
        $plain = $this->ksefCertificateFiles(passphrase: null);
        config()->set('einvoicing.ksef.credentials', $plain + ['passphrase' => null]);
        self::assertInstanceOf(CertificateCredentials::class, $this->ksef()->ksefConfig()->credentials);
    }

    public function test_token_credentials(): void
    {
        config()->set('einvoicing.ksef.nip', self::KSEF_NIP);
        config()->set('einvoicing.ksef.credentials', ['token' => ' '.self::TOKEN."\n"]);

        $credentials = $this->ksef()->ksefConfig()->credentials;
        self::assertInstanceOf(TokenCredentials::class, $credentials);
        self::assertSame(self::TOKEN, $credentials->token());

        $files = $this->ksefCertificateFiles();
        config()->set('einvoicing.ksef.credentials', $files + ['token' => self::TOKEN]);
        $this->assertConfigurationError('einvoicing.ksef.credentials');

        config()->set('einvoicing.ksef.credentials', ['token' => '', 'certificate' => null]);
        $this->assertConfigurationError('einvoicing.ksef.credentials');

        config()->set('einvoicing.ksef.credentials', ['token' => self::TOKEN]);
        config()->set('einvoicing.ksef.nip', '1234567890');
        $this->assertConfigurationError('einvoicing.ksef.nip');
        config()->set('einvoicing.ksef.nip', null);
        $this->assertConfigurationError('einvoicing.ksef.nip');
    }

    public function test_missing_key_file_fails_clearly_without_path_contents(): void
    {
        $files = $this->configureKsefCertificate();
        $missing = dirname($files['private_key']).'/absent-key.pem';

        config()->set('einvoicing.ksef.credentials.private_key', $missing);
        $this->assertConfigurationError('einvoicing.ksef.credentials.private_key', $missing);

        // A file that is not a key: its content is never quoted.
        $notKey = dirname($files['private_key']).'/not-a-key.pem';
        file_put_contents($notKey, "-----BEGIN PRIVATE KEY-----\n".self::SECRET_MARK."\n-----END PRIVATE KEY-----\n");
        config()->set('einvoicing.ksef.credentials.private_key', $notKey);
        $this->assertConfigurationError('einvoicing.ksef.credentials.private_key', $notKey);

        // The wrong passphrase is reported without the passphrase.
        config()->set('einvoicing.ksef.credentials.private_key', $files['private_key']);
        config()->set('einvoicing.ksef.credentials.passphrase', 'wrong-'.self::SECRET_MARK);
        $this->assertConfigurationError('einvoicing.ksef.credentials.passphrase');

        // A certificate path that is a directory, and a missing certificate setting.
        config()->set('einvoicing.ksef.credentials.passphrase', self::KSEF_PASSPHRASE);
        config()->set('einvoicing.ksef.credentials.certificate', dirname($files['certificate']));
        $this->assertConfigurationError('einvoicing.ksef.credentials.certificate', dirname($files['certificate']));
        config()->set('einvoicing.ksef.credentials.certificate', null);
        $this->assertConfigurationError('einvoicing.ksef.credentials.certificate');

        // The offline certificate is read the same way.
        config()->set('einvoicing.ksef.credentials.certificate', $files['certificate']);
        config()->set('einvoicing.ksef.offline_certificate', ['certificate' => $files['certificate'], 'private_key' => $missing, 'passphrase' => self::KSEF_PASSPHRASE]);
        try {
            $this->ksef()->offlineCertificate();
            self::fail('A missing offline key file was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.ksef.offline_certificate.private_key', $exception->getMessage());
            self::assertStringContainsString($missing, $exception->getMessage());
        }
    }

    public function test_ksef_http_client_has_64mib_cap(): void
    {
        self::assertSame(67_108_864, config('einvoicing.ksef.max_response_bytes'));
        self::assertSame(67_108_864, $this->ksef()->httpClient()->maxResponseBytes());
        self::assertSame(20_000_000, $this->app->make(EInvoicingManager::class)->httpClient()->maxResponseBytes(), 'The Peppol cap is unchanged.');

        config()->set('einvoicing.ksef.max_response_bytes', '80000000');
        self::assertSame(80_000_000, $this->ksef()->httpClient()->maxResponseBytes());

        foreach ([52_428_815, 'plenty', '1.5'] as $value) {
            config()->set('einvoicing.ksef.max_response_bytes', $value);
            try {
                $this->ksef()->httpClient();
                self::fail('A response cap below the largest KSeF answer was accepted.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('einvoicing.ksef.max_response_bytes', $exception->getMessage());
            }
        }
    }

    public function test_inbound_ksef_returns_transport(): void
    {
        config()->set('einvoicing.ksef.nip', self::KSEF_NIP);
        config()->set('einvoicing.ksef.credentials', ['token' => self::TOKEN]);

        $inbound = EInvoicing::inbound('ksef');
        self::assertInstanceOf(KsefTransport::class, $inbound);
        self::assertSame(EInvoicing::ksef()->transport(), $inbound);
        self::assertSame($this->app->make(KsefManager::class), EInvoicing::ksef());
    }

    public function test_offline_certificate_and_latarnia(): void
    {
        self::assertNull($this->ksef()->offlineCertificate());

        $files = $this->ksefCertificateFiles(30);
        config()->set('einvoicing.ksef.offline_certificate', $files + ['passphrase' => self::KSEF_PASSPHRASE]);
        $offline = $this->ksef()->offlineCertificate();
        self::assertInstanceOf(CertificateCredentials::class, $offline);
        self::assertSame(file_get_contents($files['certificate']), $offline->certificatePem);

        self::assertInstanceOf(Latarnia::class, $this->ksef()->latarnia());
        config()->set('einvoicing.ksef.environment', 'demo');
        self::assertNull($this->ksef()->latarnia(), 'DEMO has no Latarnia.');
    }

    public function test_manager_output_hides_credentials(): void
    {
        $this->configureKsefCertificate();
        $manager = $this->ksef();
        $manager->transport();

        ob_start();
        var_dump($manager);
        $dump = print_r($manager, true).ob_get_clean();
        self::assertStringNotContainsString(self::SECRET_MARK, $dump);
        self::assertStringNotContainsString('PRIVATE KEY', $dump);
    }
}
