<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use ErrorException;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FirstPartyDeprecations;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;

/**
 * Guards the handler that makes a deprecation caused by src/ fail the suite.
 */
final class FirstPartyDeprecationsTest extends TestCase
{
    public function test_the_handler_is_active_in_the_test_application(): void
    {
        self::assertNotNull(FirstPartyDeprecations::active(), 'FirstPartyDeprecations is not installed; check TestCase::ERROR_HANDLER_BOOTSTRAPPERS.');
    }

    public function test_a_deprecation_raised_in_src_throws(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('raised in src');

        $this->handler()(E_DEPRECATED, 'raised in src', FirstPartyDeprecations::sourceDir().'EInvoicingServiceProvider.php', 1);
    }

    public function test_a_library_deprecation_reached_from_src_throws(): void
    {
        $trace = [
            ['function' => 'trigger_error', 'file' => $this->libraryFile()],
            ['function' => 'deprecatedApi', 'file' => FirstPartyDeprecations::sourceDir().'EInvoicingServiceProvider.php'],
        ];

        $this->expectException(ErrorException::class);

        $this->handler()(E_USER_DEPRECATED, 'deprecated API', $this->libraryFile(), 1, $trace);
    }

    public function test_a_symfony_style_deprecation_reached_from_src_throws(): void
    {
        $trace = [
            ['function' => 'trigger_error', 'file' => $this->libraryFile()],
            ['function' => 'trigger_deprecation', 'file' => $this->libraryFile()],
            ['function' => 'deprecatedApi', 'file' => FirstPartyDeprecations::sourceDir().'EInvoicingServiceProvider.php'],
        ];

        $this->expectException(ErrorException::class);

        $this->handler()(E_USER_DEPRECATED, 'deprecated API', $this->libraryFile(), 1, $trace);
    }

    public function test_a_deprecation_between_libraries_is_passed_on(): void
    {
        $trace = [
            ['function' => 'trigger_error', 'file' => $this->libraryFile()],
            ['function' => 'deprecatedApi', 'file' => $this->libraryFile()],
        ];

        self::assertTrue($this->handler()(E_USER_DEPRECATED, 'between libraries', $this->libraryFile(), 1, $trace));
        self::assertTrue($this->handler()(E_DEPRECATED, 'engine deprecation in a library', $this->libraryFile(), 1));
    }

    private function handler(): FirstPartyDeprecations
    {
        $handler = FirstPartyDeprecations::active();
        self::assertNotNull($handler);

        return $handler;
    }

    private function libraryFile(): string
    {
        return (string) realpath(dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Container/Container.php');
    }
}
