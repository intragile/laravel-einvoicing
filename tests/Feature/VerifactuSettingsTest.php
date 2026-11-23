<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Config\Repository;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuManager;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuSettings;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\VerifactuEnvironment;
use IntrAgile\EInvoicing\Transport\Aeat\ThrottleScope;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckMode;

final class VerifactuSettingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function systemConfig(): array
    {
        return [
            'name' => 'Example Software Kft.',
            'nif' => 'B64829179',
            'foreign_id' => ['type' => null, 'id' => null, 'country' => null],
            'system_name' => 'Example Billing',
            'system_id' => 'EB',
            'version' => '1.0.0',
            'installation_number' => '1-999999',
            'verifactu_only' => true,
            'multiple_taxpayers_possible' => false,
            'multiple_taxpayers_indicator' => 'false',
        ];
    }

    private function settings(): VerifactuSettings
    {
        return VerifactuSettings::fromConfig(new Repository(['einvoicing' => config('einvoicing')]), '/tmp/default-schemas');
    }

    public function test_default_environment_is_test(): void
    {
        $settings = $this->settings();

        $this->assertSame(VerifactuEnvironment::Test, $settings->environment);
        $this->assertSame(IssuerDriver::Aeat, $settings->driver);
        $this->assertSame('Europe/Madrid', $settings->timezone->getName());
        $this->assertSame('/tmp/default-schemas', $settings->schemasDir);
        $this->assertSame(ThrottleScope::Installation, $settings->throttleScope);
        $this->assertSame(1000, $settings->maxBatch);
        $this->assertSame(60, $settings->initialWait);
        $this->assertSame(CrossCheckMode::Strict, $settings->crossCheck);
        $this->assertSame(60, $settings->stuckAfterMinutes);
        $this->assertNull($settings->b2brouterTransport);
        $this->assertSame(10, $settings->pollAttempts);
        $this->assertSame(30, $settings->pollDelay);
        $this->assertNull($settings->queue);
        $this->assertNull($settings->connection);
    }

    public function test_system_block_has_no_defaults(): void
    {
        $this->assertStringNotContainsString('IntrAgile', (string) file_get_contents(dirname(__DIR__, 2).'/config/einvoicing.php'));

        try {
            $this->settings()->system();
            $this->fail('An empty system block must be refused.');
        } catch (ConfigurationException $exception) {
            foreach (['name', 'system_name', 'system_id', 'version', 'installation_number', 'verifactu_only', 'multiple_taxpayers_possible', 'multiple_taxpayers_indicator'] as $key) {
                $this->assertStringContainsString('einvoicing.verifactu.system.'.$key, $exception->getMessage());
            }
            $this->assertStringContainsString('system.nif or einvoicing.verifactu.system.foreign_id', $exception->getMessage());
        }
    }

    public function test_system_is_built_from_config(): void
    {
        config()->set('einvoicing.verifactu.system', $this->systemConfig());

        $system = $this->settings()->system();

        $this->assertSame('Example Software Kft.', $system->name);
        $this->assertSame('B64829179', $system->nif?->value);
        $this->assertNull($system->foreignId);
        $this->assertSame('EB', $system->systemId);
        $this->assertSame('1-999999', $system->installationNumber);
        $this->assertTrue($system->verifactuOnly);
        $this->assertFalse($system->multipleTaxpayersPossible);
        $this->assertFalse($system->multipleTaxpayersIndicator);
    }

    public function test_system_accepts_a_foreign_identifier_but_not_both(): void
    {
        $foreign = ['type' => '02', 'id' => 'DE123456789', 'country' => 'DE'];
        config()->set('einvoicing.verifactu.system', ['nif' => null, 'foreign_id' => $foreign] + $this->systemConfig());
        $system = $this->settings()->system();
        $this->assertNull($system->nif);
        $this->assertSame('DE123456789', $system->foreignId?->id);
        $this->assertSame('DE', $system->foreignId->country?->value);

        config()->set('einvoicing.verifactu.system', ['foreign_id' => $foreign] + $this->systemConfig());
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('exactly one');
        $this->settings()->system();
    }

    public function test_invalid_system_nif_is_refused_by_key(): void
    {
        config()->set('einvoicing.verifactu.system', ['nif' => 'B64829170'] + $this->systemConfig());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.verifactu.system.nif');
        $this->settings()->system();
    }

    public function test_invalid_scope_batch_and_cross_check_are_refused(): void
    {
        foreach ([['max_batch', 1001], ['max_batch', 0], ['throttle_scope', 'tenant'], ['cross_check', 'off'], ['initial_wait', -1]] as [$key, $value]) {
            config()->set('einvoicing.verifactu.submission.'.$key, $value);
            if ($key === 'cross_check') {
                config()->set('einvoicing.verifactu.cross_check', $value);
            }
            try {
                $this->settings();
                $this->fail($key.' must be refused.');
            } catch (ConfigurationException $exception) {
                $this->assertStringContainsString($key, $exception->getMessage());
            }
            config()->set('einvoicing.verifactu.submission', ['max_batch' => 1000, 'initial_wait' => 60, 'throttle_scope' => 'installation', 'queue' => null, 'connection' => null]);
            config()->set('einvoicing.verifactu.cross_check', 'strict');
        }
    }

    public function test_invalid_environment_and_driver_are_refused(): void
    {
        config()->set('einvoicing.verifactu.environment', 'staging');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.verifactu.environment');
        $this->settings();
    }

    public function test_environment_variable_strings_are_read(): void
    {
        config()->set('einvoicing.verifactu.environment', 'production');
        config()->set('einvoicing.verifactu.driver', 'b2brouter');
        config()->set('einvoicing.verifactu.submission.max_batch', '250');
        config()->set('einvoicing.verifactu.submission.queue', 'verifactu');
        config()->set('einvoicing.verifactu.cross_check', 'report');

        $settings = $this->settings();

        $this->assertSame(VerifactuEnvironment::Production, $settings->environment);
        $this->assertSame(IssuerDriver::B2Brouter, $settings->driver);
        $this->assertSame(250, $settings->maxBatch);
        $this->assertSame('verifactu', $settings->queue);
        $this->assertSame(CrossCheckMode::Report, $settings->crossCheck);
    }

    public function test_schemas_dir_defaults_to_storage(): void
    {
        $this->assertSame(
            $this->app?->storagePath('einvoicing/verifactu'),
            $this->app?->make(VerifactuManager::class)->settings()->schemasDir,
        );
    }
}
