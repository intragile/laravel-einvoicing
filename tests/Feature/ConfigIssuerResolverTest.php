<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Config\Repository;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\ConfigIssuerResolver;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Spain\CertificateKind;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\Exception\UnknownIssuer;
use IntrAgile\EInvoicing\Spain\Verifactu\InMemoryIssuerResolver;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;

final class ConfigIssuerResolverTest extends TestCase
{
    private const ISSUER = 'B64829179';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => 'Example Issuer S.L.']]);
        config()->set('einvoicing.verifactu.aeat.certificate', '/certs/issuer.p12');
        config()->set('einvoicing.verifactu.aeat.password', 'pw-'.self::SECRET_MARK);
    }

    public function test_config_resolver_resolves_the_configured_issuer(): void
    {
        $resolver = new ConfigIssuerResolver($this->app->make('config'));

        $context = $resolver->resolve(Nif::parse(self::ISSUER));

        $this->assertSame('Example Issuer S.L.', $context->name);
        $this->assertSame(IssuerDriver::Aeat, $context->driver);
        $this->assertSame(VerifactuEnvironment::Test, $context->environment);
        $this->assertSame(CertificateKind::Personal, $context->credentials?->kind);
        $this->assertSame('/certs/issuer.p12', $context->credentials->certificatePath);
        $this->assertSame('pw-'.self::SECRET_MARK, $context->credentials->password());
        $this->assertNull($context->representative);
        $this->assertSame([self::ISSUER], array_map(strval(...), $resolver->issuers()));
    }

    public function test_issuer_override_of_driver_and_credentials(): void
    {
        config()->set('einvoicing.verifactu.issuers', [
            self::ISSUER => [
                'name' => 'Example Issuer S.L.',
                'aeat' => ['certificate' => '/certs/seal.pem', 'private_key' => '/certs/seal.key', 'password' => null, 'port' => 'seal'],
                'representative' => ['name' => 'Gestoria Example', 'nif' => '12345678Z'],
            ],
            '00000000T' => ['name' => 'Via B2Brouter', 'driver' => 'b2brouter'],
        ]);
        $resolver = new ConfigIssuerResolver($this->app->make('config'));

        $own = $resolver->resolve(Nif::parse(self::ISSUER));
        $this->assertSame('/certs/seal.pem', $own->credentials?->certificatePath);
        $this->assertSame('/certs/seal.key', $own->credentials->privateKeyPath);
        $this->assertSame(CertificateKind::Seal, $own->credentials->kind);
        $this->assertSame('pw-'.self::SECRET_MARK, $own->credentials->password());
        $this->assertSame('12345678Z', $own->representative?->nif->value);

        $routed = $resolver->resolve(Nif::parse('00000000T'));
        $this->assertSame(IssuerDriver::B2Brouter, $routed->driver);
        $this->assertNull($routed->credentials);
        $this->assertSame(VerifactuEnvironment::Test, $routed->environment);
        $this->assertCount(2, $resolver->issuers());
    }

    public function test_aeat_issuer_without_a_certificate_is_a_configuration_error(): void
    {
        config()->set('einvoicing.verifactu.aeat.certificate', null);
        $resolver = new ConfigIssuerResolver($this->app->make('config'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.verifactu.aeat.certificate');
        $resolver->resolve(Nif::parse(self::ISSUER));
    }

    public function test_issuer_without_a_name_and_bad_port_are_refused(): void
    {
        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => '']]);
        try {
            (new ConfigIssuerResolver($this->app->make('config')))->resolve(Nif::parse(self::ISSUER));
            $this->fail('A nameless issuer must be refused.');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('name', $exception->getMessage());
        }

        config()->set('einvoicing.verifactu.issuers', [self::ISSUER => ['name' => 'X']]);
        config()->set('einvoicing.verifactu.aeat.port', 'usb');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.verifactu.aeat.port');
        (new ConfigIssuerResolver($this->app->make('config')))->resolve(Nif::parse(self::ISSUER));
    }

    public function test_invalid_issuer_key_is_refused_when_enumerated(): void
    {
        config()->set('einvoicing.verifactu.issuers', ['B64829170' => ['name' => 'X']]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('B64829170');
        (new ConfigIssuerResolver($this->app->make('config')))->issuers();
    }

    public function test_env_shortcut_fills_one_issuer(): void
    {
        $_ENV['VERIFACTU_ISSUER_NIF'] = self::ISSUER;
        $_ENV['VERIFACTU_ISSUER_NAME'] = 'Env Issuer S.L.';
        try {
            $config = (static function (): array {
                return require dirname(__DIR__, 2).'/config/einvoicing.php';
            })();
        } finally {
            unset($_ENV['VERIFACTU_ISSUER_NIF'], $_ENV['VERIFACTU_ISSUER_NAME']);
        }

        $this->assertSame([self::ISSUER => ['name' => 'Env Issuer S.L.']], $config['verifactu']['issuers']);
        $this->assertSame([], $this->defaultIssuers());
    }

    /** @return array<string, mixed> */
    private function defaultIssuers(): array
    {
        $config = require dirname(__DIR__, 2).'/config/einvoicing.php';

        return $config['verifactu']['issuers'];
    }

    public function test_unknown_issuer_throws_naming_the_nif(): void
    {
        $this->expectException(UnknownIssuer::class);
        $this->expectExceptionMessage('12345678Z');
        (new ConfigIssuerResolver($this->app->make('config')))->resolve(Nif::parse('12345678Z'));
    }

    public function test_container_binds_the_config_resolver_by_default(): void
    {
        $this->assertInstanceOf(ConfigIssuerResolver::class, $this->app->make(VerifactuIssuerResolver::class));
        $this->assertSame($this->app->make(VerifactuIssuerResolver::class), $this->app->make(VerifactuManager::class)->resolver());
    }

    public function test_app_bound_resolver_wins(): void
    {
        $custom = new InMemoryIssuerResolver;
        $this->app->instance(VerifactuIssuerResolver::class, $custom);

        $this->assertSame($custom, $this->app->make(VerifactuManager::class)->resolver());
    }

    public function test_aeat_password_never_in_dumps(): void
    {
        $manager = $this->app->make(VerifactuManager::class);
        $resolver = $manager->resolver();
        $resolver->resolve(Nif::parse(self::ISSUER));
        $objects = [$manager, $manager->settings(), $resolver, $resolver->resolve(Nif::parse(self::ISSUER))];

        foreach ($objects as $object) {
            $this->assertStringNotContainsString(self::SECRET_MARK, var_export($object, true));
            $this->assertStringNotContainsString(self::SECRET_MARK, print_r($object, true));
            ob_start();
            var_dump($object);
            $this->assertStringNotContainsString(self::SECRET_MARK, (string) ob_get_clean());
        }
        $this->assertInstanceOf(Repository::class, new Repository([]));
    }
}
