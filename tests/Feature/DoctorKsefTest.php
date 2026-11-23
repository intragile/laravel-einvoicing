<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefFiles;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Latarnia\Latarnia;
use Psr\Http\Message\RequestInterface;

final class DoctorKsefTest extends TestCase
{
    use KsefFiles;
    use RefreshDatabase;

    /** @var list<RequestInterface> */
    private array $latarniaRequests = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.validation.enabled', false);
    }

    /**
     * Binds a Latarnia client that answers from canned responses and records its requests.
     */
    private function fakeLatarnia(Response ...$responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->latarniaRequests[] = $request;

                return $handler($request, $options);
            };
        });
        $factory = new HttpFactory;
        $this->app->instance(Latarnia::class, new Latarnia(
            'https://api-latarnia-test.ksef.mf.gov.pl',
            new HttpClient(new Client(['handler' => $stack]), $factory, $factory),
        ));
    }

    /**
     * @return array{int, string}
     */
    private function doctor(): array
    {
        $code = Artisan::call('einvoicing:doctor');

        return [$code, Artisan::output()];
    }

    private function assertRow(string $output, string $check, string $status, ?string $detail = null): void
    {
        $pattern = '/\|\s*'.preg_quote($check, '/').'\s*\|\s*'.preg_quote($status, '/').'\s*\|'
            .($detail !== null ? '[^\n]*'.preg_quote($detail, '/') : '').'/';
        $this->assertMatchesRegularExpression($pattern, $output);
    }

    public function test_doctor_reports_ksef_and_expiring_offline_certificate(): void
    {
        $this->configureRecommand();
        $this->configureKsefCertificate(365);
        $offline = $this->ksefCertificateFiles(10);
        config()->set('einvoicing.ksef.offline_certificate', $offline + ['passphrase' => self::KSEF_PASSPHRASE]);
        $this->fakeLatarnia(new Response(200, ['Content-Type' => 'application/json'], '{"status":"AVAILABLE","messages":[]}'));

        [, $output] = $this->doctor();

        $this->assertRow($output, 'ksef: config', 'ok', 'test environment');
        $this->assertRow($output, 'ksef: certificate', 'ok', 'valid until');
        $this->assertRow($output, 'ksef: offline certificate', 'warn', 'expires in');
        $this->assertRow($output, 'ksef: latarnia', 'ok', 'AVAILABLE');
        $this->assertRow($output, 'ksef: cursor table', 'ok');
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        $this->assertStringNotContainsString('PRIVATE KEY', $output);

        // Only Latarnia was asked, once; KSeF itself never.
        $this->assertCount(1, $this->latarniaRequests);
        $this->assertSame('/status', $this->latarniaRequests[0]->getUri()->getPath());
    }

    public function test_doctor_fails_on_expired_or_unreadable_certificates_and_warns_on_latarnia(): void
    {
        $this->configureRecommand();
        $files = $this->configureKsefCertificate(365);
        config()->set('einvoicing.ksef.offline_certificate', $this->ksefCertificateFiles(0) + ['passphrase' => self::KSEF_PASSPHRASE]);
        $this->fakeLatarnia(new Response(200, [], '{"status":"FAILURE","messages":[]}'));

        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertRow($output, 'ksef: offline certificate', 'fail', 'expired');
        $this->assertRow($output, 'ksef: latarnia', 'warn', 'FAILURE');

        $missing = dirname($files['private_key']).'/absent.pem';
        config()->set('einvoicing.ksef.credentials.private_key', $missing);
        config()->set('einvoicing.ksef.offline_certificate', null);
        $this->fakeLatarnia(new Response(503));

        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertRow($output, 'ksef: config', 'fail', 'einvoicing.ksef.credentials.private_key');
        $this->assertStringContainsString($missing, $output);
        $this->assertRow($output, 'ksef: offline certificate', 'info', 'not set');
        $this->assertRow($output, 'ksef: latarnia', 'warn');
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
    }

    public function test_doctor_live_never_reads_the_ksef_inbox_of_a_shadowed_transport(): void
    {
        $this->configureKsefCertificate(365);
        config()->set('einvoicing.transports.ksef', ['driver' => 'recommand', 'api_key_id' => 'key', 'secret' => 'sec', 'company_id' => 'c_test']);
        $api = (new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', FakeKsefApi::json(200, ['hasMore' => false, 'isTruncated' => false, 'invoices' => []]));
        $this->app->instance(KsefTransport::class, $api->transport());
        $this->fakeLatarnia(new Response(200, ['Content-Type' => 'application/json'], '{"status":"AVAILABLE","messages":[]}'));

        $code = Artisan::call('einvoicing:doctor', ['--live' => true]);
        $output = Artisan::output();

        $this->assertRow($output, 'transport ksef: inbox', 'info', 'reserved for the KSeF transport');
        $this->assertSame([], $api->paths());
        $this->assertCount(1, $this->latarniaRequests);
        $this->assertSame('/status', $this->latarniaRequests[0]->getUri()->getPath());
        $this->assertNotSame(2, $code);
    }

    public function test_doctor_live_skips_a_ksef_named_transport_when_einvoicing_ksef_is_unset(): void
    {
        config()->set('einvoicing.ksef', null);
        config()->set('einvoicing.transports.ksef', ['driver' => 'recommand', 'api_key_id' => 'key', 'secret' => 'sec', 'company_id' => 'c_test']);

        Artisan::call('einvoicing:doctor', ['--live' => true]);
        $output = Artisan::output();

        $this->assertRow($output, 'transport ksef: inbox', 'info', 'reserved for the KSeF transport');
        $this->assertStringNotContainsString('shadowed by einvoicing.ksef, and', $output);
    }

    public function test_doctor_retry_after_accounts_for_the_ksef_job_timeouts(): void
    {
        $this->configureKsefCertificate(365);
        config()->set('queue.connections.db', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300]);
        config()->set('queue.default', 'db');
        $this->fakeLatarnia(
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"AVAILABLE","messages":[]}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"status":"AVAILABLE","messages":[]}'),
        );

        [, $output] = $this->doctor();
        $this->assertRow($output, 'queue retry_after', 'warn', 'queue.connections.db.retry_after');
        $this->assertStringContainsString('longest 310 s', $output);

        config()->set('queue.connections.db.retry_after', 320);
        [, $output] = $this->doctor();
        $this->assertRow($output, 'queue retry_after', 'ok', 'longest job timeout of 310 s');
    }

    public function test_doctor_skips_ksef_when_unused_and_notes_demo_has_no_latarnia(): void
    {
        $this->configureRecommand();
        [, $output] = $this->doctor();
        $this->assertStringNotContainsString('ksef:', $output);

        // A transport named ksef is shadowed by the built-in name.
        config()->set('einvoicing.transports.ksef', ['driver' => 'recommand', 'api_key_id' => 'key-'.self::SECRET_MARK, 'secret' => 'sec-'.self::SECRET_MARK, 'company_id' => 'c_test']);
        [, $output] = $this->doctor();
        $this->assertRow($output, 'ksef: transports', 'warn', 'einvoicing.transports.ksef is shadowed');
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
        config()->set('einvoicing.transports.ksef', null);

        config()->set('einvoicing.ksef.environment', 'demo');
        config()->set('einvoicing.ksef.nip', self::KSEF_NIP);
        config()->set('einvoicing.ksef.credentials', ['token' => 'token-'.self::SECRET_MARK]);
        [, $output] = $this->doctor();
        $this->assertRow($output, 'ksef: config', 'ok', 'demo environment');
        $this->assertRow($output, 'ksef: latarnia', 'info', 'DEMO');
        $this->assertStringNotContainsString('ksef: certificate', $output);
        $this->assertStringNotContainsString(self::SECRET_MARK, $output);
    }
}
