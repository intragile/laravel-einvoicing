<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Laravel\Tests\Support\CallbackValidator;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\Exception\InvalidDocument;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandTransport;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandWebhook;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use IntrAgile\EInvoicing\Transport\ValidatingTransport;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\FacturX\FacturXValidator;
use IntrAgile\EInvoicing\Validation\XRechnung\XRechnungValidator;
use LogicException;

final class ManagerTest extends TestCase
{
    public function test_builds_recommand_wrapped_in_validation(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());

        try {
            $this->manager()->transport();
            self::fail('A missing Peppol rule set must surface when the transport is built.');
        } catch (RulesNotInstalled) {
            $this->addToAssertionCount(1);
        }

        config()->set('einvoicing.validation.enabled', false);
        $transport = $this->freshManager()->transport('recommand');

        self::assertInstanceOf(RecommandTransport::class, $transport);
        self::assertSame($transport, $this->manager()->transport(), 'Built transports are cached per name.');
    }

    public function test_config_errors_name_keys_not_values(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        config()->set('einvoicing.transports.recommand.secret', '');
        $this->assertConfigurationError('einvoicing.transports.recommand.secret');

        config()->set('einvoicing.transports.recommand.secret', 'sec-'.self::SECRET_MARK);
        config()->set('einvoicing.transports.recommand.api_key_id', null);
        $this->assertConfigurationError('einvoicing.transports.recommand.api_key_id');

        config()->set('einvoicing.transports.recommand.api_key_id', 'key-'.self::SECRET_MARK);
        config()->set('einvoicing.transports.recommand.company_id', '  ');
        $this->assertConfigurationError('einvoicing.transports.recommand.company_id');

        config()->set('einvoicing.transports.recommand.company_id', 'c_test');
        config()->set('einvoicing.transports.recommand.base_url', 'http://user:'.self::SECRET_MARK.'@app.recommand.eu/?token='.self::SECRET_MARK);
        $exception = $this->assertConfigurationError('einvoicing.transports.recommand.base_url');
        self::assertNull($exception->getPrevious(), 'The core message would repeat the URL.');
    }

    public function test_numeric_string_settings_are_accepted(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.http.timeout', '20');
        config()->set('einvoicing.http.connect_timeout', '2.5');
        config()->set('einvoicing.http.max_response_bytes', '1000000');
        config()->set('einvoicing.sending.max_attempts', '3');

        self::assertInstanceOf(RecommandTransport::class, $this->freshManager()->transport());
        // 20 + ceil(2.5) + 30
        self::assertSame(53, (new SendDocument(1))->timeout);
        self::assertSame(3, ConfigValue::number(config('einvoicing.sending.max_attempts'), 'einvoicing.sending.max_attempts'));
        self::assertSame(2.5, ConfigValue::number('2.5', 'x'));
        self::assertSame(7, ConfigValue::number(7, 'x'));
    }

    public function test_non_numeric_setting_names_the_key(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        foreach (['einvoicing.http.timeout', 'einvoicing.http.connect_timeout', 'einvoicing.http.max_response_bytes'] as $key) {
            config()->set($key, 'plenty');
            $this->assertConfigurationError($key);
            config()->set($key, null);
        }

        config()->set('einvoicing.http.max_response_bytes', '1.5');
        $this->assertConfigurationError('einvoicing.http.max_response_bytes');
        config()->set('einvoicing.http.max_response_bytes', null);

        config()->set('einvoicing.http.timeout', ['60']);
        try {
            new SendDocument(1);
            self::fail('A non-numeric job setting was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.http.timeout', $exception->getMessage());
        }
    }

    public function test_short_credentials_are_named_not_taken_for_a_bad_base_url(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        config()->set('einvoicing.transports.recommand.api_key_id', 'short');
        $exception = $this->assertConfigurationError('einvoicing.transports.recommand.api_key_id');
        self::assertStringNotContainsString('base_url', $exception->getMessage());
        self::assertStringNotContainsString('short', $exception->getMessage());

        config()->set('einvoicing.transports.recommand.api_key_id', 'key-'.self::SECRET_MARK);
        config()->set('einvoicing.transports.recommand.secret', '   tiny   ');
        $this->assertConfigurationError('einvoicing.transports.recommand.secret');
    }

    public function test_transport_cache_keeps_the_validation_setting_it_was_built_with(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        $transport = $this->freshManager()->transport();

        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());

        self::assertSame($transport, $this->manager()->transport());
    }

    public function test_unknown_transport_and_driver(): void
    {
        try {
            $this->manager()->transport('missing');
            self::fail('An unknown transport name must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.transports.missing', $exception->getMessage());
        }

        config()->set('einvoicing.transports.odd', ['driver' => 'carrier-pigeon']);
        try {
            $this->manager()->transport('odd');
            self::fail('An unknown driver must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.transports.odd.driver', $exception->getMessage());
        }
    }

    public function test_extend_registers_custom_driver(): void
    {
        $custom = new FakeTransport;
        $received = null;
        config()->set('einvoicing.transports.inhouse', ['driver' => 'inhouse', 'endpoint' => 'https://ap.example']);
        config()->set('einvoicing.validation.enabled', false);
        $this->manager()->extend('inhouse', function (Application $app, array $config) use ($custom, &$received): Transport {
            $received = $config;

            return $custom;
        });

        self::assertSame($custom, $this->manager()->transport('inhouse'));
        self::assertSame($custom, $this->manager()->inbound('inhouse'));
        self::assertSame(['driver' => 'inhouse', 'endpoint' => 'https://ap.example'], $received);
        self::assertContains('inhouse', $this->manager()->names());

        // Validation wraps every configured transport, custom drivers included.
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());
        $this->freshManager()->extend('inhouse', fn (): Transport => $custom);
        $this->expectException(RulesNotInstalled::class);
        $this->manager()->transport('inhouse');
    }

    public function test_extend_refuses_a_factory_that_returns_no_transport(): void
    {
        config()->set('einvoicing.transports.inhouse', ['driver' => 'inhouse']);
        $this->manager()->extend('inhouse', fn (): object => new \stdClass);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.transports.inhouse.driver');
        $this->manager()->transport('inhouse');
    }

    public function test_inbound_requires_an_inbound_source(): void
    {
        config()->set('einvoicing.transports.outonly', ['driver' => 'outonly']);
        $this->manager()->extend('outonly', fn (): Transport => new class implements Transport
        {
            public function send(OutboundDocument $document): SendResult
            {
                throw new LogicException('Not used.');
            }

            public function status(string $documentId): DeliveryStatus
            {
                throw new LogicException('Not used.');
            }
        });

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.transports.outonly');
        $this->manager()->inbound('outonly');
    }

    public function test_inbound_returns_the_unwrapped_driver_without_rules(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());

        self::assertInstanceOf(RecommandTransport::class, $this->manager()->inbound());
    }

    public function test_webhook_requires_recommand_driver_and_secret(): void
    {
        $this->configureRecommand();
        EInvoicing::fake();

        self::assertInstanceOf(RecommandWebhook::class, $this->manager()->webhook('recommand'));

        config()->set('einvoicing.transports.recommand.webhook_secret', null);
        try {
            $this->manager()->webhook('recommand');
            self::fail('A transport without a webhook secret has no webhook.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('einvoicing.transports.recommand.webhook_secret', $exception->getMessage());
        }

        config()->set('einvoicing.transports.inhouse', ['driver' => 'inhouse', 'webhook_secret' => 'wh-'.self::SECRET_MARK]);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('einvoicing.transports.inhouse.driver');
        $this->manager()->webhook('inhouse');
    }

    public function test_a_dotted_name_is_not_resolved_as_a_nested_key(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        // "recommand.extra" must not reach einvoicing.transports.recommand.extra, nor a dotted
        // name the nested recommand configuration.
        config()->set('einvoicing.transports.recommand.extra', ['driver' => 'recommand', 'webhook_secret' => 'wh-'.self::SECRET_MARK]);
        foreach (['recommand.extra', 'recommand.', '.recommand'] as $name) {
            try {
                $this->manager()->webhook($name);
                self::fail(sprintf('"%s" is not a configured transport.', $name));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }

        config()->set('einvoicing.transports', ['a.b' => ['driver' => 'recommand', 'webhook_secret' => 'wh-'.self::SECRET_MARK]]);
        self::assertInstanceOf(RecommandWebhook::class, $this->manager()->webhook('a.b'), 'A configured name with a dot is found as is.');

        $this->expectException(ConfigurationException::class);
        $this->freshManager()->transport('recommand.extra');
    }

    public function test_fake_replaces_every_transport_and_skips_validation(): void
    {
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());

        $fake = EInvoicing::fake();

        self::assertSame($fake, $this->manager()->transport());
        self::assertSame($fake, $this->manager()->transport('recommand'));
        self::assertSame($fake, $this->manager()->transport('anything'));
        self::assertSame($fake, $this->manager()->inbound());
        self::assertSame($fake, $this->manager()->inbound('anything'));
    }

    public function test_manager_debug_info_hides_secrets(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        $manager = $this->manager();
        $manager->transport();
        $manager->webhook('recommand');

        $dumps = [var_export($manager, true), print_r($manager, true), print_r($manager->__debugInfo(), true)];
        foreach ($dumps as $dump) {
            self::assertStringNotContainsString(self::SECRET_MARK, $dump);
        }
        self::assertStringContainsString('recommand', $dumps[1]);
    }

    public function test_stack_frame_arguments_never_carry_secrets(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand.company_id', '');

        try {
            $this->freshManager()->transport();
            self::fail('A missing company id must be refused.');
        } catch (ConfigurationException $exception) {
            $this->assertTraceHidesSecrets($exception);
        }

        config()->set('einvoicing.transports.custom', [
            'driver' => 'failing',
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
        ]);
        $manager = $this->freshManager();
        $manager->extend('failing', static fn (Application $app, #[\SensitiveParameter] array $config): Transport => throw new LogicException('Factory failed.'));

        try {
            $manager->transport('custom');
            self::fail('The factory exception must propagate.');
        } catch (LogicException $exception) {
            $this->assertTraceHidesSecrets($exception);
        }
    }

    private function assertTraceHidesSecrets(\Throwable $exception): void
    {
        $frames = 0;
        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                if (is_string($argument) || is_array($argument)) {
                    $frames++;
                    self::assertStringNotContainsString(self::SECRET_MARK, (string) json_encode($argument), $frame['function']);
                }
            }
        }
        self::assertGreaterThan(0, $frames, 'The trace must carry arguments for this check to mean anything.');
    }

    public function test_rules_dir_defaults_to_storage(): void
    {
        config()->set('einvoicing.validation.rules_dir', null);
        self::assertSame(storage_path('einvoicing/peppol-rules'), $this->manager()->rulesDir());

        config()->set('einvoicing.validation.rules_dir', '/srv/rules');
        self::assertSame('/srv/rules', $this->manager()->rulesDir());
    }

    public function test_default_name_and_names_come_from_config(): void
    {
        config()->set('einvoicing.default', 'other');
        config()->set('einvoicing.transports.other', ['driver' => 'recommand']);

        self::assertSame('other', $this->manager()->defaultName());
        self::assertSame(['recommand', 'other'], $this->manager()->names());
    }

    public function test_public_transport_still_validates(): void
    {
        $inner = new FakeTransport;
        $validator = new CallbackValidator(static fn () => CallbackValidator::invalid());
        $this->app->instance(DocumentValidator::class, $validator);
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.transports.recommand', ['driver' => 'inner']);
        $manager = $this->freshManager();
        $manager->extend('inner', static fn (): Transport => $inner);

        $transport = $manager->transport();
        self::assertInstanceOf(ValidatingTransport::class, $transport);
        self::assertSame($inner, $manager->transport(validated: false));

        $xml = (string) file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        try {
            $transport->send(OutboundDocument::fromXml($xml));
            self::fail('The public transport must validate before sending.');
        } catch (InvalidDocument $exception) {
            self::assertStringContainsString('PEPPOL-EN16931-R001', $exception->getMessage());
        }
        self::assertSame(1, $validator->calls);
        self::assertSame([], $inner->sent());
    }

    public function test_validator_follows_the_binding_the_setting_and_the_fake(): void
    {
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());
        try {
            $this->freshManager()->validator();
            self::fail('Without a binding, the Peppol rules must be required.');
        } catch (RulesNotInstalled) {
            $this->addToAssertionCount(1);
        }

        $bound = new CallbackValidator(static fn () => CallbackValidator::valid());
        $this->app->instance(DocumentValidator::class, $bound);
        self::assertSame($bound, $this->freshManager()->validator());

        config()->set('einvoicing.validation.enabled', false);
        self::assertNull($this->freshManager()->validator());

        config()->set('einvoicing.validation.enabled', true);
        $manager = $this->freshManager();
        $manager->fake();
        self::assertNull($manager->validator(), 'Faked transports skip validation.');
    }

    public function test_xrechnung_and_facturx_accessors(): void
    {
        self::assertInstanceOf(XRechnungValidator::class, $this->manager()->xrechnung());
        self::assertInstanceOf(FacturXValidator::class, $this->manager()->facturx());
    }

    private function manager(): EInvoicingManager
    {
        return $this->app->make(EInvoicingManager::class);
    }

    private function freshManager(): EInvoicingManager
    {
        $this->app->forgetInstance(EInvoicingManager::class);
        EInvoicing::clearResolvedInstances();

        return $this->manager();
    }

    private function assertConfigurationError(string $keyPath): ConfigurationException
    {
        try {
            $this->freshManager()->transport();
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($keyPath, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());

            return $exception;
        }
        self::fail(sprintf('Expected a ConfigurationException naming %s.', $keyPath));
    }
}
