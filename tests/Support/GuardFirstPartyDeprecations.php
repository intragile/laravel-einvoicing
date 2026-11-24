<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Wraps the bootstrapper that installs Laravel's error handler, and puts FirstPartyDeprecations
 * in front of it right away, so service provider registration and boot are covered too.
 */
final class GuardFirstPartyDeprecations
{
    public function __construct(private readonly object $inner) {}

    public function bootstrap(Application $app): void
    {
        if (method_exists($this->inner, 'bootstrap')) {
            $this->inner->bootstrap($app);
        }

        FirstPartyDeprecations::install();
    }
}
