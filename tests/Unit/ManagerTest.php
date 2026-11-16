<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Unit;

use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Support\ConfigValue;
use IntrAgile\EInvoicing\Laravel\Tests\Support\CallbackValidator;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterTransport;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterWebhook;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeTransport;
use IntrAgile\EInvoicing\Transport\EInvoiceBe\EInvoiceBeWebhook;
use IntrAgile\EInvoicing\Transport\Exception\InvalidDocument;
use IntrAgile\EInvoicing\Transport\FakeTransport;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandTransport;
use IntrAgile\EInvoicing\Transport\Recommand\RecommandWebhook;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use IntrAgile\EInvoicing\Transport\ValidatingTransport;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookParser;
use IntrAgile\EInvoicing\Validation\DocumentValidator;
use IntrAgile\EInvoicing\Validation\Exception\RulesNotInstalled;
use IntrAgile\EInvoicing\Validation\FacturX\FacturXValidator;
use IntrAgile\EInvoicing\Validation\XRechnung\XRechnungValidator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

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

    /**
     * @return iterable<string, array{string, array<string, mixed>, class-string<Transport>}>
     */
    public static function builtInDrivers(): iterable
    {
        yield 'recommand' => ['recommand', [
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c_test',
        ], RecommandTransport::class];
        yield 'b2brouter' => ['b2brouter', [
            'api_key' => 'test_'.self::SECRET_MARK,
            'account_id' => '78234',
            'base_url' => null,
            'document_type_codes' => [],
        ], B2BrouterTransport::class];
        yield 'b2brouter with an int account id and a custom code' => ['b2brouter', [
            'api_key' => 'test_'.self::SECRET_MARK,
            'account_id' => 78234,
            'base_url' => 'https://api-staging.b2brouter.net',
            'document_type_codes' => ['urn:example:doctype' => 'xml.ubl.invoice.custom'],
        ], B2BrouterTransport::class];
        yield 'einvoicebe' => ['einvoicebe', [
            'api_key' => 'ak-'.self::SECRET_MARK,
            'base_url' => null,
            'inbound_lookback_days' => '30',
            'inbound_max_pages' => 5,
        ], EInvoiceBeTransport::class];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  class-string<Transport>  $class
     */
    #[DataProvider('builtInDrivers')]
    public function test_builds_each_built_in_driver(string $driver, array $settings, string $class): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.peppol', ['driver' => $driver, ...$settings]);

        $transport = $this->freshManager()->transport('peppol');

        self::assertInstanceOf($class, $transport);
        self::assertTrue($this->manager()->hasDriver($driver));
        self::assertStringNotContainsString(self::SECRET_MARK, print_r($transport, true));
    }

    /**
     * The drivers follow the redirects they trust themselves, so the shared client must not.
     *
     * @param  array<string, mixed>  $settings
     * @param  class-string<Transport>  $class
     */
    #[DataProvider('builtInDrivers')]
    public function test_http_client_never_follows_redirects(string $driver, array $settings, string $class): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.peppol', ['driver' => $driver, ...$settings]);

        $transport = $this->freshManager()->transport('peppol');
        self::assertInstanceOf($class, $transport);
        $http = (new ReflectionProperty($transport, 'http'))->getValue($transport);
        self::assertInstanceOf(HttpClient::class, $http);
        $client = (new ReflectionProperty($http, 'client'))->getValue($http);
        self::assertInstanceOf(Client::class, $client);

        self::assertFalse($client->getConfig('allow_redirects'));
        self::assertFalse($client->getConfig('http_errors'));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string, string|null}>
     */
    public static function invalidDriverSettings(): iterable
    {
        $b2brouter = ['driver' => 'b2brouter', 'api_key' => 'test_'.self::SECRET_MARK, 'account_id' => '78234'];
        $einvoicebe = ['driver' => 'einvoicebe', 'api_key' => 'ak-'.self::SECRET_MARK];

        yield 'b2brouter key missing' => ['b2brouter', [...$b2brouter, 'api_key' => null], 'api_key', null];
        yield 'b2brouter key short' => ['b2brouter', [...$b2brouter, 'api_key' => 'zq-71x'], 'api_key', 'zq-71x'];
        yield 'b2brouter account missing' => ['b2brouter', [...$b2brouter, 'account_id' => ''], 'account_id', null];
        yield 'b2brouter account not digits' => ['b2brouter', [...$b2brouter, 'account_id' => '78q34'], 'account_id', '78q34'];
        yield 'b2brouter account zero' => ['b2brouter', [...$b2brouter, 'account_id' => 0], 'account_id', null];
        yield 'b2brouter account fraction' => ['b2brouter', [...$b2brouter, 'account_id' => '7.5'], 'account_id', '7.5'];
        yield 'b2brouter base URL' => ['b2brouter', [...$b2brouter, 'base_url' => 'http://u:'.self::SECRET_MARK.'@api.b2brouter.net/?t=x'], 'base_url', null];
        yield 'b2brouter base URL not a string' => ['b2brouter', [...$b2brouter, 'base_url' => ['https://api.b2brouter.net']], 'base_url', null];
        yield 'b2brouter codes not a map' => ['b2brouter', [...$b2brouter, 'document_type_codes' => 'xml.ubl.invoice.bis3'], 'document_type_codes', 'xml.ubl'];
        yield 'b2brouter codes a list' => ['b2brouter', [...$b2brouter, 'document_type_codes' => ['xml.ubl.invoice.custom']], 'document_type_codes', 'xml.ubl'];
        yield 'b2brouter code malformed' => ['b2brouter', [...$b2brouter, 'document_type_codes' => ['urn:x' => 'Bad Code!']], 'document_type_codes', 'Bad Code!'];
        yield 'einvoicebe key missing' => ['einvoicebe', [...$einvoicebe, 'api_key' => '  '], 'api_key', null];
        yield 'einvoicebe key short' => ['einvoicebe', [...$einvoicebe, 'api_key' => 'zq-71x'], 'api_key', 'zq-71x'];
        yield 'einvoicebe base URL' => ['einvoicebe', [...$einvoicebe, 'base_url' => 'http://'.self::SECRET_MARK.'.example'], 'base_url', null];
        yield 'einvoicebe look-back zero' => ['einvoicebe', [...$einvoicebe, 'inbound_lookback_days' => 0], 'inbound_lookback_days', null];
        yield 'einvoicebe look-back text' => ['einvoicebe', [...$einvoicebe, 'inbound_lookback_days' => 'week'], 'inbound_lookback_days', 'week'];
        yield 'einvoicebe pages over the cap' => ['einvoicebe', [...$einvoicebe, 'inbound_max_pages' => '101'], 'inbound_max_pages', null];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    #[DataProvider('invalidDriverSettings')]
    public function test_missing_key_names_the_setting_without_value(string $driver, array $settings, string $key, ?string $value): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.default', 'peppol');
        config()->set('einvoicing.transports.peppol', $settings);

        $exception = $this->assertConfigurationError('einvoicing.transports.peppol.'.$key);
        if ($value !== null) {
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
        self::assertNull($exception->getPrevious(), 'A core message may quote the value.');
        $frames = array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === EInvoicingManager::class);
        self::assertNotSame([], $frames);
        self::assertStringNotContainsString(self::SECRET_MARK, (string) json_encode(array_column($frames, 'args')));
    }

    public function test_config_file_documents_each_built_in_driver(): void
    {
        $file = (string) file_get_contents(dirname(__DIR__, 2).'/config/einvoicing.php');
        // The blocks of the drivers besides the default one ship commented out.
        $uncommented = (string) preg_replace('/^(\s*)\/\/ (?=\s*[\'\]])/m', '$1', $file);
        $path = $this->emptyDir().'/einvoicing.php';
        file_put_contents($path, $uncommented);
        $config = require $path;
        self::assertIsArray($config);

        foreach (EInvoicingManager::BUILT_IN_DRIVERS as $driver) {
            $block = $config['transports'][$driver] ?? null;
            self::assertIsArray($block, $driver);
            self::assertSame($driver, $block['driver']);
            foreach ([...EInvoicingManager::REQUIRED_KEYS[$driver], 'base_url', 'webhook_secret'] as $key) {
                self::assertArrayHasKey($key, $block, "{$driver}.{$key}");
            }
        }
        self::assertSame(['api_key', 'account_id', 'base_url', 'document_type_codes', 'webhook_secret', 'webhook_tolerance'], array_keys(array_slice($config['transports']['b2brouter'], 1)));
        self::assertSame(['api_key', 'base_url', 'tenant_id', 'inbound_lookback_days', 'inbound_max_pages', 'webhook_secret'], array_keys(array_slice($config['transports']['einvoicebe'], 1)));
        self::assertSame('https://api.b2brouter.net', $config['transports']['b2brouter']['base_url']);
        self::assertSame('https://api.e-invoice.be', $config['transports']['einvoicebe']['base_url']);
    }

    public function test_webhook_parser_per_driver(): void
    {
        EInvoicing::fake();
        config()->set('einvoicing.transports', [
            'recommand' => ['driver' => 'recommand', 'webhook_secret' => 'wh-'.self::SECRET_MARK],
            'b2brouter' => ['driver' => 'b2brouter', 'webhook_secret' => 'wh-'.self::SECRET_MARK, 'webhook_tolerance' => '300'],
            'einvoicebe' => ['driver' => 'einvoicebe', 'webhook_secret' => 'wh-'.self::SECRET_MARK],
        ]);

        self::assertInstanceOf(RecommandWebhook::class, $this->manager()->webhook('recommand'));
        self::assertInstanceOf(B2BrouterWebhook::class, $this->manager()->webhook('b2brouter'));
        self::assertInstanceOf(EInvoiceBeWebhook::class, $this->manager()->webhook('einvoicebe'));
        foreach (['recommand', 'b2brouter', 'einvoicebe'] as $name) {
            self::assertStringNotContainsString(self::SECRET_MARK, print_r($this->manager()->webhook($name), true));
        }

        foreach (['b2brouter' => 'webhook_tolerance'] as $name => $key) {
            foreach ([0, 'soon', 31_536_001, 2.5] as $tolerance) {
                config()->set("einvoicing.transports.{$name}.{$key}", $tolerance);
                $this->assertWebhookError($name, "einvoicing.transports.{$name}.{$key}");
            }
            config()->set("einvoicing.transports.{$name}.{$key}", null);
        }

        foreach (['recommand', 'b2brouter', 'einvoicebe'] as $name) {
            config()->set("einvoicing.transports.{$name}.webhook_secret", ' ');
            $this->assertWebhookError($name, "einvoicing.transports.{$name}.webhook_secret");
        }
    }

    public function test_custom_webhook_parser_via_extend(): void
    {
        $parser = new RecommandWebhook('wh-'.self::SECRET_MARK);
        $received = null;
        config()->set('einvoicing.transports.inhouse', ['driver' => 'inhouse', 'webhook_secret' => 'wh-'.self::SECRET_MARK]);

        // A custom driver without a parser takes no webhooks.
        $this->assertWebhookError('inhouse', 'einvoicing.transports.inhouse.driver');

        $this->manager()->extendWebhook('inhouse', function (Application $app, array $config) use ($parser, &$received): WebhookParser {
            $received = $config;

            return $parser;
        });
        self::assertSame($parser, $this->manager()->webhook('inhouse'));
        self::assertSame(['driver' => 'inhouse', 'webhook_secret' => 'wh-'.self::SECRET_MARK], $received);

        // The secret is required for every driver.
        config()->set('einvoicing.transports.inhouse.webhook_secret', null);
        $this->assertWebhookError('inhouse', 'einvoicing.transports.inhouse.webhook_secret');

        config()->set('einvoicing.transports.inhouse.webhook_secret', 'wh-'.self::SECRET_MARK);
        $this->manager()->extendWebhook('inhouse', static fn (): object => new \stdClass);
        $this->assertWebhookError('inhouse', 'einvoicing.transports.inhouse.driver');
    }

    public function test_account_id_per_driver(): void
    {
        config()->set('einvoicing.transports', [
            'recommand' => ['driver' => 'recommand', 'company_id' => 'c_test'],
            'b2brouter' => ['driver' => 'b2brouter', 'account_id' => 78234],
            'einvoicebe' => ['driver' => 'einvoicebe', 'tenant_id' => 'tenant-1'],
            'einvoicebe_open' => ['driver' => 'einvoicebe'],
            'inhouse' => ['driver' => 'inhouse', 'company_id' => 'c_test', 'account_id' => 'a_test'],
        ]);

        self::assertSame('c_test', $this->manager()->accountId('recommand'));
        self::assertSame('78234', $this->manager()->accountId('b2brouter'));
        self::assertSame('tenant-1', $this->manager()->accountId('einvoicebe'));
        self::assertNull($this->manager()->accountId('einvoicebe_open'));
        self::assertNull($this->manager()->accountId('inhouse'));
        self::assertNull($this->manager()->accountId('missing'));
    }

    public function test_account_id_is_trimmed_and_an_invalid_one_is_refused(): void
    {
        config()->set('einvoicing.transports', [
            'recommand' => ['driver' => 'recommand', 'company_id' => " c_test\t"],
            'b2brouter' => ['driver' => 'b2brouter', 'account_id' => ' 78234 '],
            'einvoicebe' => ['driver' => 'einvoicebe', 'tenant_id' => null],
            'empty' => ['driver' => 'einvoicebe', 'tenant_id' => ''],
            'blank' => ['driver' => 'einvoicebe', 'tenant_id' => '  '],
            'listed' => ['driver' => 'recommand', 'company_id' => ['c_test']],
            'wordy' => ['driver' => 'b2brouter', 'account_id' => 'abc'],
        ]);

        self::assertSame('c_test', $this->manager()->accountId('recommand'));
        self::assertSame('78234', $this->manager()->accountId('b2brouter'));
        self::assertNull($this->manager()->accountId('einvoicebe'));
        self::assertNull($this->manager()->accountId('empty'));

        foreach (['blank' => 'tenant_id', 'listed' => 'company_id', 'wordy' => 'account_id'] as $name => $key) {
            try {
                $this->manager()->accountId($name);
                self::fail("An invalid {$key} must be refused.");
            } catch (ConfigurationException $exception) {
                self::assertSame(
                    "einvoicing.transports.{$name}.{$key} is not a valid account id; webhook events are ignored.",
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_validation_flag_reads_environment_strings(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());

        foreach (['false', '0', 'off', ''] as $value) {
            config()->set('einvoicing.validation.enabled', $value);
            self::assertNotInstanceOf(ValidatingTransport::class, $this->freshManager()->transport(), var_export($value, true));
        }

        // An unreadable value keeps validation on, like "true".
        foreach (['true', 'ture'] as $value) {
            config()->set('einvoicing.validation.enabled', $value);
            try {
                $this->freshManager()->transport();
                self::fail('Validation must stay on for '.var_export($value, true).'.');
            } catch (RulesNotInstalled) {
                self::addToAssertionCount(1);
            }
        }
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

    private function assertWebhookError(string $name, string $keyPath): void
    {
        try {
            $this->manager()->webhook($name);
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($keyPath, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET_MARK, $exception->getMessage());

            return;
        }
        self::fail(sprintf('Expected a ConfigurationException naming %s.', $keyPath));
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
