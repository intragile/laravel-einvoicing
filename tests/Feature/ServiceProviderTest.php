<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\EInvoicingServiceProvider;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class ServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // No credentials and a rule folder that does not exist: booting must still succeed.
        $app['config']->set('einvoicing.validation.rules_dir', dirname(__DIR__, 2).'/build/no-such-rules');
        // A database that cannot be opened: booting must not touch it.
        $app['config']->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => dirname(__DIR__, 2).'/build/no-such-dir/db.sqlite']);
        $app['config']->set('database.default', 'unreachable');
    }

    public function test_provider_boots_without_rules_or_credentials(): void
    {
        $manager = EInvoicing::getFacadeRoot();

        self::assertInstanceOf(EInvoicingManager::class, $manager);
        self::assertSame($manager, $this->app->make('einvoicing'));
        self::assertSame($manager, $this->app->make(EInvoicingManager::class));
    }

    public function test_boot_registers_the_webhook_route_without_building_anything(): void
    {
        $route = Route::getRoutes()->getByName('einvoicing.webhook');

        self::assertNotNull($route);
        self::assertSame('einvoicing/webhooks/{transport}', $route->uri());
        self::assertSame(['POST'], $route->methods());
        self::assertSame([], EInvoicing::getFacadeRoot()->__debugInfo()['built']);
        self::assertSame([], DB::getConnections(), 'No database connection is opened at boot.');
    }

    #[DefineEnvironment('customWebhookRoute')]
    public function test_webhook_prefix_and_middleware_come_from_config(): void
    {
        $route = Route::getRoutes()->getByName('einvoicing.webhook');

        self::assertNotNull($route);
        self::assertSame('hooks/peppol/{transport}', $route->uri());
        self::assertSame(['throttle:60,1'], $route->gatherMiddleware());
    }

    /**
     * @param  Application  $app
     */
    protected function customWebhookRoute($app): void
    {
        $app['config']->set('einvoicing.webhooks.prefix', 'hooks/peppol');
        $app['config']->set('einvoicing.webhooks.middleware', ['throttle:60,1']);
    }

    public function test_boot_stays_lazy_after_routes_and_commands(): void
    {
        $manager = EInvoicing::getFacadeRoot();
        $commands = array_keys(Artisan::all());

        // Routes and commands are registered, yet nothing is built and no database is opened.
        self::assertNotNull(Route::getRoutes()->getByName('einvoicing.webhook'));
        foreach (['einvoicing:doctor', 'einvoicing:install-rules', 'einvoicing:poll-inbound', 'einvoicing:resolve'] as $command) {
            self::assertContains($command, $commands);
        }
        self::assertSame([], $manager->__debugInfo()['built']);
        self::assertFalse($manager->__debugInfo()['faked']);
        self::assertSame([], DB::getConnections(), 'No database connection is opened at boot.');
    }

    #[DefineEnvironment('webhooksOffAsString')]
    public function test_webhooks_enabled_false_string_disables_routes(): void
    {
        self::assertNull(Route::getRoutes()->getByName('einvoicing.webhook'));
    }

    /**
     * @param  Application  $app
     */
    protected function webhooksOffAsString($app): void
    {
        // As read from an environment variable.
        $app['config']->set('einvoicing.webhooks.enabled', 'false');
    }

    public function test_config_is_merged_and_publishable(): void
    {
        self::assertSame('recommand', config('einvoicing.default'));
        self::assertSame('https://app.recommand.eu', config('einvoicing.transports.recommand.base_url'));
        self::assertTrue(config('einvoicing.validation.enabled'));
        self::assertSame([30, 120, 600, 1800], config('einvoicing.sending.backoff'));

        $published = ServiceProvider::pathsToPublish(EInvoicingServiceProvider::class, 'einvoicing-config');
        self::assertSame([config_path('einvoicing.php')], array_values($published));
        self::assertFileExists((string) array_key_first($published));
    }
}
