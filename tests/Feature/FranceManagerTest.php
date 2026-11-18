<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Application;
use IntrAgile\EInvoicing\France\Routing\Annuaire;
use IntrAgile\EInvoicing\France\Routing\Router;
use IntrAgile\EInvoicing\France\Routing\StaticAnnuaire;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\France\FranceManager;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterAnnuaire;
use IntrAgile\EInvoicing\Transport\B2Brouter\France\B2BrouterFrance;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

final class FranceManagerTest extends TestCase
{
    /** The einvoicing.france.transport value; the environment attributes run before defineEnvironment() and change it. */
    private ?string $franceTransport = 'b2brouter';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('einvoicing.transports.b2brouter', [
            'driver' => 'b2brouter',
            'api_key' => 'test_'.self::SECRET_MARK,
            'account_id' => '78234',
            'base_url' => 'https://api.b2brouter.net',
        ]);
        $app['config']->set('einvoicing.transports.b2b-second', [
            'driver' => 'b2brouter',
            'api_key' => 'test_second-'.self::SECRET_MARK,
            'account_id' => 91,
        ]);
        $app['config']->set('einvoicing.transports.recommand', [
            'driver' => 'recommand',
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c_test',
        ]);
        $app['config']->set('einvoicing.france.transport', $this->franceTransport);
        $app['config']->set('einvoicing.france.annuaire_attempts', 2);
        $app['config']->set('einvoicing.france.annuaire_retry_delay', '1');
    }

    /**
     * @param  Application  $app
     */
    protected function withoutFranceTransport($app): void
    {
        $this->franceTransport = null;
    }

    /**
     * @param  Application  $app
     */
    protected function withBlankFranceTransport($app): void
    {
        $this->franceTransport = '';
    }

    private static function read(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }

    public function test_france_resolves_b2brouter_client(): void
    {
        $france = EInvoicing::france();

        self::assertInstanceOf(B2BrouterFrance::class, $france);
        self::assertSame($france, EInvoicing::france('b2brouter'), 'Clients are cached per transport.');
        self::assertSame($france, $this->app->make(FranceManager::class)->france());
        self::assertSame($this->app->make(FranceManager::class), $this->app->make(FranceManager::class));

        $config = self::read($france, 'config');
        self::assertInstanceOf(B2BrouterConfig::class, $config);
        self::assertSame(78234, $config->accountId);
        self::assertTrue($config->isSandboxKey());

        $second = EInvoicing::france('b2b-second');
        self::assertNotSame($france, $second);
        $secondConfig = self::read($second, 'config');
        self::assertInstanceOf(B2BrouterConfig::class, $secondConfig);
        self::assertSame(91, $secondConfig->accountId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTransports(): iterable
    {
        yield 'another driver' => ['recommand'];
        yield 'not configured' => ['nowhere'];
    }

    #[DataProvider('refusedTransports')]
    public function test_france_refuses_non_b2brouter_transport(string $transport): void
    {
        foreach ([
            static fn (): B2BrouterFrance => EInvoicing::france($transport),
            static fn (): B2BrouterAnnuaire => EInvoicing::annuaire($transport),
        ] as $resolve) {
            try {
                $resolve();
                self::fail('A transport that is not B2Brouter must be refused.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('einvoicing.france.transport', $exception->getMessage());
                self::assertStringContainsString($transport, $exception->getMessage());
                self::assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());
            }
        }
    }

    public function test_france_refuses_a_france_transport_naming_another_driver(): void
    {
        config()->set('einvoicing.france.transport', 'recommand');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.france.transport');

        $this->app->make(EInvoicingManager::class)->france();
    }

    #[DefineEnvironment('withoutFranceTransport')]
    public function test_france_without_a_transport_names_the_setting(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.france.transport');

        EInvoicing::france();
    }

    public function test_france_reports_an_invalid_b2brouter_setting_by_key(): void
    {
        config()->set('einvoicing.transports.b2brouter.account_id', 'abc');

        try {
            EInvoicing::france();
            self::fail('An invalid account id must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.transports.b2brouter.account_id', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());
        }
    }

    public function test_annuaire_binding_uses_france_transport(): void
    {
        $annuaire = $this->app->make(Annuaire::class);

        self::assertInstanceOf(B2BrouterAnnuaire::class, $annuaire);
        self::assertSame($annuaire, $this->app->make(Annuaire::class), 'The binding is a singleton.');
        self::assertSame($annuaire, EInvoicing::annuaire());
        self::assertSame(2, self::read($annuaire, 'attempts'));
        self::assertSame(1, self::read($annuaire, 'retryDelaySeconds'));
        $config = self::read($annuaire, 'config');
        self::assertInstanceOf(B2BrouterConfig::class, $config);
        self::assertSame(78234, $config->accountId);
    }

    public function test_router_resolves_from_container(): void
    {
        $router = $this->app->make(Router::class);

        self::assertSame($this->app->make(Annuaire::class), self::read($router, 'annuaire'));
    }

    #[DefineEnvironment('withoutFranceTransport')]
    public function test_no_annuaire_binding_without_france_transport(): void
    {
        self::assertFalse($this->app->bound(Annuaire::class));
        // A named transport can still be used explicitly.
        self::assertInstanceOf(B2BrouterAnnuaire::class, EInvoicing::annuaire('b2brouter'));
    }

    #[DefineEnvironment('withBlankFranceTransport')]
    public function test_no_annuaire_binding_for_an_empty_france_transport(): void
    {
        self::assertFalse($this->app->bound(Annuaire::class));
    }

    #[DefineEnvironment('withOwnAnnuaire')]
    public function test_an_annuaire_bound_by_the_application_is_kept(): void
    {
        self::assertInstanceOf(StaticAnnuaire::class, $this->app->make(Annuaire::class));
    }

    /**
     * @param  Application  $app
     */
    protected function withOwnAnnuaire($app): void
    {
        $app->singleton(Annuaire::class, static fn (): Annuaire => new StaticAnnuaire([]));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidAnnuaireSettings(): iterable
    {
        yield 'no attempt' => ['annuaire_attempts', 0];
        yield 'attempts not a number' => ['annuaire_attempts', 'many'];
        yield 'too many attempts' => ['annuaire_attempts', 11];
        yield 'negative delay' => ['annuaire_retry_delay', -1];
        yield 'fractional delay' => ['annuaire_retry_delay', 1.5];
        yield 'delay too long' => ['annuaire_retry_delay', 31];
    }

    #[DataProvider('invalidAnnuaireSettings')]
    public function test_annuaire_refuses_invalid_retry_settings(string $key, mixed $value): void
    {
        config()->set("einvoicing.france.{$key}", $value);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("einvoicing.france.{$key}");

        EInvoicing::annuaire('b2b-second');
    }

    public function test_unset_retry_settings_use_the_defaults(): void
    {
        config()->set('einvoicing.france.annuaire_attempts', null);
        config()->set('einvoicing.france.annuaire_retry_delay', null);

        $annuaire = EInvoicing::annuaire('b2b-second');

        self::assertSame(4, self::read($annuaire, 'attempts'));
        self::assertSame(3, self::read($annuaire, 'retryDelaySeconds'));
    }

    public function test_france_clients_keep_secrets_out_of_debug_output(): void
    {
        $manager = $this->app->make(FranceManager::class);
        $manager->france();
        $manager->annuaire();

        self::assertStringNotContainsString(self::SECRET_MARK, print_r($manager, true));
        self::assertStringNotContainsString(self::SECRET_MARK, var_export($manager->__debugInfo(), true));
    }
}
