<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use IntrAgile\EInvoicing\France\Routing\Annuaire;
use IntrAgile\EInvoicing\Laravel\Console\DoctorCommand;
use IntrAgile\EInvoicing\Laravel\Console\InstallRulesCommand;
use IntrAgile\EInvoicing\Laravel\Console\KsefCertificateCommand;
use IntrAgile\EInvoicing\Laravel\Console\KsefDeadlinesCommand;
use IntrAgile\EInvoicing\Laravel\Console\PollInboundCommand;
use IntrAgile\EInvoicing\Laravel\Console\ResolveCommand;
use IntrAgile\EInvoicing\Laravel\France\FranceManager;
use IntrAgile\EInvoicing\Laravel\Ksef\DatabaseKsefCursorStore;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Support\ConfigFlag;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;

final class EInvoicingServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../config/einvoicing.php';

    private const MIGRATION = __DIR__.'/../database/migrations/create_einvoicing_tables.php';

    private const KSEF_MIGRATION = __DIR__.'/../database/migrations/add_ksef_to_einvoicing_tables.php';

    private const ROUTES = __DIR__.'/../routes/webhooks.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'einvoicing');

        $this->app->singleton(EInvoicingManager::class, fn (Application $app): EInvoicingManager => new EInvoicingManager($app));
        $this->app->alias(EInvoicingManager::class, 'einvoicing');
        $this->app->singleton(FranceManager::class, fn (Application $app): FranceManager => new FranceManager($app));
        $this->app->singleton(KsefManager::class, fn (Application $app): KsefManager => new KsefManager($app));
        // An application may bind its own store; the database store is the default.
        $this->app->bindIf(KsefCursorStore::class, fn (Application $app): KsefCursorStore => new DatabaseKsefCursorStore($app->make('db')->connection()));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([self::MIGRATION, self::KSEF_MIGRATION]);

        // The live Annuaire is bound only for an application that names its French transport, so
        // a router resolved from the container never reaches the network unasked. The setting is
        // read once, at boot, like the webhook flag; the client itself is built on first use. An
        // Annuaire the application bound itself is kept.
        if (FranceManager::transportName(config('einvoicing.france.transport')) !== null && ! $this->app->bound(Annuaire::class)) {
            $this->app->singleton(Annuaire::class, fn (Application $app): Annuaire => $app->make(FranceManager::class)->annuaire());
        }

        // Registering the route reads configuration only; transports are built per request. The
        // setting may come from an environment variable; a value that is not a flag means off.
        if (ConfigFlag::enabled('webhooks.enabled')) {
            $this->loadRoutesFrom(self::ROUTES);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class, InstallRulesCommand::class, KsefCertificateCommand::class, KsefDeadlinesCommand::class, PollInboundCommand::class, ResolveCommand::class]);
            $this->publishes([self::CONFIG => $this->app->configPath('einvoicing.php')], 'einvoicing-config');
            $now = time();
            $this->publishes([
                self::MIGRATION => $this->app->databasePath('migrations/'.date('Y_m_d_His', $now).'_create_einvoicing_tables.php'),
                // One second later, so the published copies run in this order.
                self::KSEF_MIGRATION => $this->app->databasePath('migrations/'.date('Y_m_d_His', $now + 1).'_add_ksef_to_einvoicing_tables.php'),
            ], 'einvoicing-migrations');
        }
    }
}
