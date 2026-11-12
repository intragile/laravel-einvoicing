<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use IntrAgile\EInvoicing\Laravel\Http\WebhookController;

// Only the configured middleware runs: never the "web" group, so no session or CSRF token.
Route::prefix(config()->string('einvoicing.webhooks.prefix', 'einvoicing/webhooks'))
    ->middleware(config()->array('einvoicing.webhooks.middleware', []))
    ->group(static function (): void {
        Route::post('{transport}', WebhookController::class)->name('einvoicing.webhook');
    });
