<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use IntrAgile\EInvoicing\Laravel\EInvoicingServiceProvider;
use IntrAgile\EInvoicing\Laravel\Tests\Support\GuardFirstPartyDeprecations;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Marker carried by every test credential, so a leak can be detected with one search. */
    protected const SECRET_MARK = 'SECRETMARK';

    /** @var list<string> */
    private array $tempDirs = [];

    /**
     * Bootstrappers that install Laravel's error handler in a test application. The guard test
     * fails when none of them runs any more, for example after a Testbench rename.
     */
    public const ERROR_HANDLER_BOOTSTRAPPERS = [
        'Orchestra\Testbench\Bootstrap\HandleExceptions',
        HandleExceptions::class,
    ];

    protected function getPackageProviders($app): array
    {
        return [EInvoicingServiceProvider::class];
    }

    /**
     * Runs before the application is bootstrapped; used to make a deprecation caused by src/
     * fail the test (see FirstPartyDeprecations).
     */
    protected function resolveApplicationExceptionHandler($app)
    {
        parent::resolveApplicationExceptionHandler($app);

        foreach (self::ERROR_HANDLER_BOOTSTRAPPERS as $bootstrapper) {
            $app->extend($bootstrapper, static fn (object $inner): object => new GuardFirstPartyDeprecations($inner));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * Removes a directory with everything in it; symbolic links are removed, never followed.
     */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path.'/'.$entry);
        }
        rmdir($path);
    }

    /**
     * Fills the built-in Recommand transport with test credentials that carry SECRET_MARK.
     */
    protected function configureRecommand(): void
    {
        config()->set('einvoicing.transports.recommand', [
            'driver' => 'recommand',
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c_test',
            'base_url' => 'https://app.recommand.eu',
            'webhook_secret' => 'wh-'.self::SECRET_MARK,
        ]);
    }

    /**
     * An empty directory inside the package's build folder, removed after the test.
     */
    protected function emptyDir(): string
    {
        $dir = dirname(__DIR__).'/build/tmp/'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
