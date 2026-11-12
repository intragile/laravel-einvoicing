<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use RuntimeException;

final class InstallRulesCommandTest extends TestCase
{
    public function test_install_rules_reports_bad_source(): void
    {
        $target = $this->emptyDir();
        config()->set('einvoicing.validation.rules_dir', $target);

        $code = Artisan::call('einvoicing:install-rules', ['--from' => '/nonexistent']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString(RuntimeException::class, $output);
        $this->assertStringContainsString('/nonexistent/', $output);
        $this->assertSame([], array_values(array_diff((array) scandir($target), ['.', '..'])));
    }
}
