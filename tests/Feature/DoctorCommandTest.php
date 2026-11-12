<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\DeliveryStatus;
use IntrAgile\EInvoicing\Transport\Exception\AuthenticationFailed;
use IntrAgile\EInvoicing\Transport\InboundDocument;
use IntrAgile\EInvoicing\Transport\InboundReference;
use IntrAgile\EInvoicing\Transport\InboundSource;
use IntrAgile\EInvoicing\Transport\OutboundDocument;
use IntrAgile\EInvoicing\Transport\Peppol\PeppolBis3;
use IntrAgile\EInvoicing\Transport\SendResult;
use IntrAgile\EInvoicing\Transport\Transport;
use LogicException;

final class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * Runs the doctor and returns its exit code and output.
     *
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function doctor(array $options = []): array
    {
        $code = Artisan::call('einvoicing:doctor', $options);

        return [$code, Artisan::output()];
    }

    /**
     * Asserts that the table holds a row whose check contains $check and whose status is $status.
     */
    private function assertRow(string $output, string $check, string $status, ?string $detail = null): void
    {
        $pattern = '/\|\s*[^|\n]*'.preg_quote($check, '/').'[^|\n]*\|\s*'.preg_quote($status, '/').'\s*\|'
            .($detail !== null ? '[^\n]*'.preg_quote($detail, '/') : '').'/';
        $this->assertMatchesRegularExpression($pattern, $output);
    }

    public function test_doctor_reports_missing_credentials_and_fails(): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand', ['driver' => 'recommand', 'company_id' => 'c_test']);

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertRow($output, 'recommand: config', 'fail', 'einvoicing.transports.recommand.api_key_id');
        $this->assertStringContainsString('einvoicing.transports.recommand.secret', $output);
        $this->assertStringNotContainsString('einvoicing.transports.recommand.company_id', $output);
    }

    public function test_doctor_fails_on_an_unknown_default_and_a_plain_http_base_url(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand.base_url', 'http://app.recommand.eu');
        config()->set('einvoicing.default', 'missing');

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertRow($output, 'default transport', 'fail', 'einvoicing.transports.missing');
        $this->assertRow($output, 'recommand: base URL', 'fail', 'https://');
    }

    public function test_doctor_warns_on_sync_and_disabled_validation(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertRow($output, 'default transport', 'ok', 'recommand');
        $this->assertRow($output, 'recommand: config', 'ok');
        $this->assertRow($output, 'recommand: base URL', 'ok');
        $this->assertRow($output, 'validation', 'warn', 'CustomizationID');
        $this->assertRow($output, 'queue', 'warn', 'no retries');
        $this->assertStringContainsString('no delivery status polling', $output);
        $this->assertStringContainsString('no expiry', $output);
        $this->assertStringContainsString('pcntl', $output);
        $this->assertRow($output, 'tables', 'ok');
    }

    public function test_doctor_treats_deferred_and_background_drivers_as_sync(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('queue.connections.later', ['driver' => 'deferred']);
        config()->set('einvoicing.queue.connection', 'later');

        [, $output] = $this->doctor();

        $this->assertRow($output, 'queue', 'warn', 'no retries');
    }

    public function test_doctor_warns_when_retry_after_is_not_longer_than_a_send(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('queue.connections.db', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        config()->set('queue.default', 'db');

        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertRow($output, 'queue retry_after', 'warn', 'queue.connections.db.retry_after');
        $this->assertStringContainsString('send 100 s', $output);
        $this->assertStringContainsString('longest 170 s', $output);

        config()->set('queue.connections.db.retry_after', 300);
        [, $output] = $this->doctor();

        $this->assertRow($output, 'queue retry_after', 'ok');
    }

    public function test_doctor_fails_without_rules_and_tables(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());
        Schema::drop('einvoicing_inbound_documents');

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertRow($output, 'validation', 'fail', 'run php artisan einvoicing:install-rules');
        $this->assertRow($output, 'tables', 'fail', 'run php artisan migrate');
        $this->assertStringContainsString('einvoicing_inbound_documents', $output);
    }

    public function test_doctor_shows_webhook_url(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);

        [, $output] = $this->doctor();

        $this->assertRow($output, 'recommand: webhook', 'ok', route('einvoicing.webhook', 'recommand'));
        $this->assertStringContainsString('/einvoicing/webhooks/recommand', $output);
    }

    public function test_doctor_warns_without_webhook_secret_or_company(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.transports.recommand.webhook_secret', null);
        config()->set('einvoicing.transports.other', [
            'driver' => 'recommand',
            'api_key_id' => 'k',
            'secret' => 's',
            'webhook_secret' => 'w',
        ]);

        [, $output] = $this->doctor();

        $this->assertRow($output, 'recommand: webhook', 'warn', 'webhooks off; schedule einvoicing:poll-inbound');
        $this->assertRow($output, 'other: webhook', 'warn', 'einvoicing.transports.other.company_id');
    }

    public function test_doctor_live_uses_inbox(): void
    {
        $this->configureRecommand();
        config()->set('einvoicing.validation.enabled', false);
        $fake = EInvoicing::fake();
        $fake->receive(new InboundDocument(
            new InboundReference('in-1', null, null, PeppolBis3::INVOICE, new DateTimeImmutable('2026-11-12 10:00:00')),
            '<Invoice/>',
        ));

        [$code, $output] = $this->doctor(['--live' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertRow($output, 'recommand: inbox', 'ok', '1 waiting');
    }

    public function test_doctor_without_live_never_calls_the_inbox(): void
    {
        $this->useStubTransports(['stub' => []]);

        [, $output] = $this->doctor();

        $this->assertStringNotContainsString('inbox', $output);
    }

    public function test_doctor_never_prints_secrets(): void
    {
        $secrets = [
            'api_key_id' => 'key-'.self::SECRET_MARK,
            'secret' => 'sec-'.self::SECRET_MARK,
            'company_id' => 'c-'.self::SECRET_MARK,
            'webhook_secret' => 'wh-'.self::SECRET_MARK,
        ];
        config()->set('einvoicing.validation.enabled', true);
        config()->set('einvoicing.validation.rules_dir', $this->emptyDir());
        config()->set('einvoicing.transports', [
            'recommand' => ['driver' => 'recommand', 'base_url' => 'https://app.recommand.eu', ...$secrets],
            'userinfo' => ['driver' => 'recommand', 'base_url' => 'https://u:pw-'.self::SECRET_MARK.'@app.recommand.eu', ...$secrets],
            'insecure' => ['driver' => 'recommand', 'base_url' => 'http://'.self::SECRET_MARK.'.example', ...$secrets],
        ]);

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertRow($output, 'userinfo: base URL', 'fail');
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);

        // The live check reports the exception message, from which every configured secret is cut.
        $this->useStubTransports(['stub' => $secrets]);

        [$code, $output] = $this->doctor(['--live' => true]);

        $this->assertSame(1, $code);
        $this->assertRow($output, 'stub: inbox', 'fail', AuthenticationFailed::class);
        $this->assertStringContainsString('refused', $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        $this->assertStringNotContainsString(base64_encode('key-'.self::SECRET_MARK.':sec-'.self::SECRET_MARK), $output);
    }

    /**
     * Configures transports of a stub driver whose inbox refuses the credentials, quoting them.
     *
     * @param  array<string, array<string, string>>  $transports
     */
    private function useStubTransports(array $transports): void
    {
        config()->set('einvoicing.validation.enabled', false);
        config()->set('einvoicing.default', (string) array_key_first($transports));
        config()->set('einvoicing.transports', array_map(
            static fn (array $config): array => ['driver' => 'stub', ...$config],
            $transports,
        ));

        $this->app->make(EInvoicingManager::class)->extend('stub', static fn ($app, array $config): Transport => new class($config) implements InboundSource, Transport
        {
            /** @param array<string, mixed> $config */
            public function __construct(private readonly array $config) {}

            public function send(OutboundDocument $document): SendResult
            {
                throw new LogicException('Not used.');
            }

            public function status(string $documentId): DeliveryStatus
            {
                throw new LogicException('Not used.');
            }

            public function pending(): array
            {
                $key = is_string($this->config['api_key_id'] ?? null) ? $this->config['api_key_id'] : '';
                $secret = is_string($this->config['secret'] ?? null) ? $this->config['secret'] : '';

                throw new AuthenticationFailed(sprintf(
                    'The credentials %s / %s were refused (Basic %s).',
                    $key,
                    $secret,
                    base64_encode($key.':'.$secret),
                ));
            }

            public function fetch(string $documentId): InboundDocument
            {
                throw new LogicException('Not used.');
            }

            public function acknowledge(string $documentId): void
            {
                throw new LogicException('Not used.');
            }
        });
    }
}
