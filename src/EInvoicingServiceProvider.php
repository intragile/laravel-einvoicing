<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class EInvoicingServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../config/einvoicing.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'einvoicing');

        $this->app->singleton(EInvoicingManager::class, fn (Application $app): EInvoicingManager => new EInvoicingManager($app));
        $this->app->alias(EInvoicingManager::class, 'einvoicing');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG => $this->app->configPath('einvoicing.php')], 'einvoicing-config');
        }
    }
}
