<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use PHPUnit\Framework\Attributes\Group;

final class VerifactuInstallSchemasCommandTest extends TestCase
{
    #[Group('verifactu-official')]
    public function test_install_schemas_from_local_folder(): void
    {
        $from = VerifactuFiles::schemasDir().'/'.VerifactuSchemas::DIR;
        $target = $this->emptyDir();
        config()->set('einvoicing.verifactu.schemas_dir', $target);

        $code = Artisan::call('einvoicing:verifactu-install-schemas', ['--from' => $from]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertSame(8, substr_count($output, $target.'/'.VerifactuSchemas::DIR.'/'));
        $this->assertStringContainsString('AEAT publishes no licence for these files, do not redistribute them.', $output);
        $this->assertInstanceOf(VerifactuSchemas::class, $this->app->make(VerifactuManager::class)->schemas());
    }

    public function test_install_reports_a_bad_source(): void
    {
        $target = $this->emptyDir();
        config()->set('einvoicing.verifactu.schemas_dir', $target);

        $code = Artisan::call('einvoicing:verifactu-install-schemas', ['--from' => '/nonexistent']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('/nonexistent', Artisan::output());
        $this->assertSame([], array_values(array_diff((array) scandir($target), ['.', '..'])));
    }

    public function test_schemas_not_installed_names_the_command(): void
    {
        config()->set('einvoicing.verifactu.schemas_dir', $this->emptyDir());

        try {
            $this->app->make(VerifactuManager::class)->schemas();
            $this->fail('Missing schemas must be refused.');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('php artisan einvoicing:verifactu-install-schemas', $exception->getMessage());
        }
    }
}
