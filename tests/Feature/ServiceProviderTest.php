<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\EInvoicingServiceProvider;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // No credentials and a rule folder that does not exist: booting must still succeed.
        $app['config']->set('einvoicing.validation.rules_dir', dirname(__DIR__, 2).'/build/no-such-rules');
    }

    public function test_provider_boots_without_rules_or_credentials(): void
    {
        $manager = EInvoicing::getFacadeRoot();

        self::assertInstanceOf(EInvoicingManager::class, $manager);
        self::assertSame($manager, $this->app->make('einvoicing'));
        self::assertSame($manager, $this->app->make(EInvoicingManager::class));
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
