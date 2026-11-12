<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use IntrAgile\EInvoicing\Laravel\Console\ResolveCommand;

final class EInvoicingServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../config/einvoicing.php';

    private const MIGRATION = __DIR__.'/../database/migrations/create_einvoicing_tables.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'einvoicing');

        $this->app->singleton(EInvoicingManager::class, fn (Application $app): EInvoicingManager => new EInvoicingManager($app));
        $this->app->alias(EInvoicingManager::class, 'einvoicing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(self::MIGRATION);

        if ($this->app->runningInConsole()) {
            $this->commands([ResolveCommand::class]);
            $this->publishes([self::CONFIG => $this->app->configPath('einvoicing.php')], 'einvoicing-config');
            $this->publishes([
                self::MIGRATION => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_einvoicing_tables.php'),
            ], 'einvoicing-migrations');
        }
    }
}
