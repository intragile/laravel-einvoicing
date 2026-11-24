<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\HttpFactory;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Tests\Support\AeatCertificates;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeAeatApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuFiles;
use IntrAgile\EInvoicing\Laravel\Tests\Support\VerifactuSamples;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Laravel\Verifactu\VerifactuClients;
use IntrAgile\EInvoicing\Spain\Nif;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerContext;
use IntrAgile\EInvoicing\Spain\Verifactu\IssuerDriver;
use IntrAgile\EInvoicing\Spain\Verifactu\VerifactuIssuerResolver;
use IntrAgile\EInvoicing\Transport\Aeat\AeatClient;
use IntrAgile\EInvoicing\Transport\B2Brouter\B2BrouterConfig;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\B2BrouterVerifactu;
use IntrAgile\EInvoicing\Transport\Exception\TransportUnavailable;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Validation\Spain\VerifactuSchemas;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use Throwable;

#[RequiresPhpExtension('openssl')]
final class VerifactuClientsTest extends TestCase
{
    private const PASSWORD = 'pw-'.self::SECRET_MARK.'-1234';

    /** Seconds the TLS server child lives at most. */
    private const SERVER_LIFETIME = 10;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $storage;

    private string $certificatePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->emptyDir();
        $this->app->useStoragePath($this->storage);
        $this->certificatePath = $this->emptyDir().'/issuer.p12';
        $this->writeCertificate('AEAT TEST CLIENT');

        config()->set('einvoicing.verifactu.schemas_dir', VerifactuFiles::schemasDir());
        config()->set('einvoicing.verifactu.issuers', [VerifactuSamples::ISSUER_NIF => ['name' => 'Test Issuer S.L.']]);
        config()->set('einvoicing.verifactu.aeat.certificate', $this->certificatePath);
        config()->set('einvoicing.verifactu.aeat.password', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->app?->make(VerifactuClients::class)->forget();
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->server);
        }

        parent::tearDown();
    }

    public function test_clients_are_one_instance_per_process(): void
    {
        $this->assertSame($this->app->make(VerifactuClients::class), $this->app->make(VerifactuClients::class));
    }

    public function test_aeat_client_is_cached_per_credentials(): void
    {
        $clients = $this->app->make(VerifactuClients::class);
        $first = $clients->aeat($this->context());

        $this->assertSame($first, $clients->aeat($this->context()));
        $this->assertSame($clients->aeatHttpClient($this->context()), $clients->aeatHttpClient($this->context()));
        $this->assertCount(2, $this->materializedFiles());

        // A renewed certificate replaces the file in place: a fresh client is built, and the
        // files of the old one are deleted.
        $this->writeCertificate('AEAT TEST CLIENT RENEWED');
        touch($this->certificatePath, time() + 5);
        clearstatcache();
        $renewed = $clients->aeat($this->context());
        $this->assertNotSame($first, $renewed);
        $this->assertCount(2, $this->materializedFiles());

        // Another port is another credential set.
        config()->set('einvoicing.verifactu.aeat.port', 'seal');
        $seal = $clients->aeat($this->context());
        $this->assertNotSame($renewed, $seal);
        $this->assertCount(4, $this->materializedFiles());
    }

    public function test_issuer_moved_to_b2brouter_still_gets_an_aeat_client_for_its_aeat_chain(): void
    {
        $clients = $this->app->make(VerifactuClients::class);
        $aeat = $clients->aeat($this->context());
        config()->set('einvoicing.verifactu.issuers.'.VerifactuSamples::ISSUER_NIF.'.driver', 'b2brouter');
        $switched = $this->context();
        $this->assertSame(IssuerDriver::B2Brouter, $switched->driver);

        // The same credentials: the cached client of the AEAT chain.
        $this->assertSame($aeat, $clients->aeat($switched));
        $this->assertSame(IssuerDriver::Aeat, VerifactuClients::forAeatChain($switched)->driver);
        $this->assertSame($switched->credentials, VerifactuClients::forAeatChain($switched)->credentials);
    }

    public function test_issuer_moved_to_b2brouter_without_aeat_credentials_gets_no_aeat_client(): void
    {
        config()->set('einvoicing.verifactu.aeat.certificate', null);
        config()->set('einvoicing.verifactu.issuers.'.VerifactuSamples::ISSUER_NIF.'.driver', 'b2brouter');

        $thrown = $this->capture(fn () => $this->app->make(VerifactuClients::class)->aeat($this->context()));

        $this->assertInstanceOf(ConfigurationException::class, $thrown);
        $this->assertStringContainsString('B2Brouter', $thrown->getMessage());
        $this->assertStringContainsString('einvoicing.verifactu.aeat.certificate', $thrown->getMessage());
        $this->assertStringNotContainsString(self::SECRET_MARK, $thrown->getMessage());
    }

    public function test_materialized_files_are_disposed(): void
    {
        $clients = $this->app->make(VerifactuClients::class);
        $clients->aeatHttpClient($this->context());
        $files = $this->materializedFiles();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        }

        $clients->forget();

        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertSame([], $this->materializedFiles());
    }

    public function test_tls_directory_is_private_and_stale_key_files_are_removed(): void
    {
        $clients = $this->app->make(VerifactuClients::class);
        $clients->aeatHttpClient($this->context());
        $directories = glob($this->storage.'/einvoicing/verifactu/tls/*', GLOB_ONLYDIR) ?: [];
        $this->assertCount(1, $directories);
        $directory = $directories[0];
        $this->assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
        $clients->forget();

        // Files left by a worker that was killed before it could delete them.
        $stale = [$directory.'/aeat-0123456789abcdef01234567-certificate.pem', $directory.'/aeat-0123456789abcdef01234567-key.pem'];
        $recent = $directory.'/aeat-fedcba9876543210fedcba98-key.pem';
        foreach ([...$stale, $recent] as $file) {
            file_put_contents($file, 'left over');
        }
        foreach ($stale as $file) {
            touch($file, time() - 2 * 86400);
        }
        $other = $directory.'/notes.txt';
        file_put_contents($other, 'not ours');
        touch($other, time() - 2 * 86400);

        $clients->aeatHttpClient($this->context());

        foreach ($stale as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $this->assertFileExists($recent);
        $this->assertFileExists($other);
    }

    public function test_a_second_process_does_not_break_the_files_of_a_long_running_one(): void
    {
        $first = new VerifactuClients($this->app);
        $first->aeatHttpClient($this->context());
        $files = $this->materializedFiles();
        $this->assertCount(2, $files);

        // A client in use renews its files on every use, so they never look left over.
        $this->backdate($files);
        $first->aeatHttpClient($this->context());
        $second = new VerifactuClients($this->app);
        $second->aeat($this->context());
        foreach ($files as $file) {
            $this->assertFileExists($file);
        }
        $this->assertCount(4, $this->materializedFiles());

        // Files of an idle client that the other process removed are written again on next use.
        $this->backdate($files);
        $third = new VerifactuClients($this->app);
        $third->aeat($this->context());
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
        $first->aeatHttpClient($this->context());
        $this->assertCount(6, $this->materializedFiles());
        $first->forget();
        $this->assertCount(4, $this->materializedFiles());
        $second->forget();
        $third->forget();
        $this->assertSame([], $this->materializedFiles());
    }

    public function test_left_over_files_of_other_credentials_are_removed_with_their_empty_folder(): void
    {
        $root = $this->storage.'/einvoicing/verifactu/tls';
        $abandoned = $root.'/'.sha1('abandoned');
        $shared = $root.'/'.sha1('shared');
        $foreign = $root.'/not-ours';
        foreach ([$abandoned, $shared, $foreign] as $folder) {
            mkdir($folder, 0700, true);
        }
        $old = [
            $abandoned.'/aeat-0123456789abcdef01234567-certificate.pem',
            $abandoned.'/aeat-0123456789abcdef01234567-key.pem',
            $shared.'/aeat-0123456789abcdef01234567-key.pem',
            $foreign.'/aeat-0123456789abcdef01234567-key.pem',
        ];
        $kept = [$shared.'/aeat-fedcba9876543210fedcba98-key.pem', $shared.'/notes.pem'];
        foreach ([...$old, ...$kept] as $file) {
            file_put_contents($file, 'left over');
        }
        $this->backdate([...$old, $shared.'/notes.pem']);

        $this->app->make(VerifactuClients::class)->aeatHttpClient($this->context());

        $this->assertDirectoryDoesNotExist($abandoned);
        $this->assertFileDoesNotExist($old[2]);
        $this->assertFileDoesNotExist($old[3]);
        foreach ($kept as $file) {
            $this->assertFileExists($file);
        }
        // A folder the package did not name is emptied of its files, never removed.
        $this->assertDirectoryExists($foreign);
    }

    public function test_an_unwritable_tls_folder_is_a_transient_failure(): void
    {
        mkdir($this->storage.'/einvoicing/verifactu', 0700, true);
        file_put_contents($this->storage.'/einvoicing/verifactu/tls', 'not a folder');

        $thrown = $this->capture(fn () => $this->app->make(VerifactuClients::class)->aeat($this->context()));

        $this->assertInstanceOf(TransportUnavailable::class, $thrown);
        $this->assertStringNotContainsString(self::SECRET_MARK, $thrown->getMessage());
    }

    public function test_unreadable_certificate_is_a_configuration_error_without_the_password(): void
    {
        config()->set('einvoicing.verifactu.aeat.password', 'wrong-'.self::SECRET_MARK);

        $thrown = $this->capture(fn () => $this->app->make(VerifactuClients::class)->aeat($this->context()));

        $this->assertInstanceOf(ConfigurationException::class, $thrown);
        $this->assertStringContainsString(VerifactuSamples::ISSUER_NIF, $thrown->getMessage());
        $this->assertStringNotContainsString(self::SECRET_MARK, $thrown->getMessage());
        $this->assertStringNotContainsString(self::SECRET_MARK, print_r($this->app->make(VerifactuClients::class), true));
    }

    public function test_bound_clients_win(): void
    {
        $aeat = (new FakeAeatApi)->client(new VerifactuSchemas(VerifactuFiles::schemasDir()));
        $this->app->instance(AeatClient::class, $aeat);
        $b2brouter = new B2BrouterVerifactu(new B2BrouterConfig('test_'.self::SECRET_MARK.'key', 999999), $this->httpClient());
        $this->app->instance(B2BrouterVerifactu::class, $b2brouter);
        config()->set('einvoicing.verifactu.aeat.certificate', '/nonexistent/issuer.p12');

        $clients = $this->app->make(VerifactuClients::class);

        $this->assertSame($aeat, $clients->aeat($this->context()));
        $this->assertSame($b2brouter, $clients->b2brouter());
        $this->assertSame([], $this->materializedFiles());
    }

    public function test_b2brouter_client_is_built_from_the_named_transport(): void
    {
        config()->set('einvoicing.transports.es', [
            'driver' => 'b2brouter',
            'api_key' => 'test_'.self::SECRET_MARK.'key',
            'account_id' => 999999,
        ]);
        config()->set('einvoicing.verifactu.b2brouter.transport', 'es');

        $this->assertInstanceOf(B2BrouterVerifactu::class, $this->app->make(VerifactuClients::class)->b2brouter());
    }

    public function test_b2brouter_client_needs_a_b2brouter_transport(): void
    {
        $unset = $this->capture(fn () => $this->app->make(VerifactuClients::class)->b2brouter());
        $this->assertInstanceOf(ConfigurationException::class, $unset);
        $this->assertStringContainsString('einvoicing.verifactu.b2brouter.transport', $unset->getMessage());

        $this->configureRecommand();
        config()->set('einvoicing.verifactu.b2brouter.transport', 'recommand');
        $other = $this->capture(fn () => $this->app->make(VerifactuClients::class)->b2brouter());
        $this->assertInstanceOf(ConfigurationException::class, $other);
        $this->assertStringContainsString('recommand', $other->getMessage());
        $this->assertStringNotContainsString(self::SECRET_MARK, $other->getMessage());
    }

    public function test_client_certificate_is_presented_over_tls(): void
    {
        $directory = $this->emptyDir();
        $caFile = $directory.'/ca.pem';
        file_put_contents($caFile, AeatCertificates::caPem());
        $server = AeatCertificates::server();
        file_put_contents($directory.'/server.crt', $server['certificate']);
        file_put_contents($directory.'/server.key', $server['privateKey']);
        $port = $this->startServer($directory.'/server.crt', $directory.'/server.key', $caFile);
        config()->set('einvoicing.verifactu.aeat.ca_bundle', $caFile);
        config()->set('einvoicing.verifactu.aeat.timeout', 8);

        $response = $this->app->make(VerifactuClients::class)->aeatHttpClient($this->context())->send('GET', "https://127.0.0.1:{$port}/");

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('AEAT TEST CLIENT', (string) $response->getBody());
    }

    public function test_unknown_ca_is_refused(): void
    {
        $directory = $this->emptyDir();
        $caFile = $directory.'/ca.pem';
        file_put_contents($caFile, AeatCertificates::caPem());
        $server = AeatCertificates::server();
        file_put_contents($directory.'/server.crt', $server['certificate']);
        file_put_contents($directory.'/server.key', $server['privateKey']);
        $port = $this->startServer($directory.'/server.crt', $directory.'/server.key', $caFile);
        $otherCa = $directory.'/other-ca.pem';
        file_put_contents($otherCa, AeatCertificates::selfSigned('Another Throwaway CA'));
        config()->set('einvoicing.verifactu.aeat.timeout', 8);

        // Another CA, and the system store: neither trusts the server, and no setting turns the
        // verification off.
        foreach ([$otherCa, null] as $bundle) {
            config()->set('einvoicing.verifactu.aeat.ca_bundle', $bundle);
            $clients = $this->app->make(VerifactuClients::class);
            $clients->forget();

            $thrown = $this->capture(fn () => $clients->aeatHttpClient($this->context())->send('GET', "https://127.0.0.1:{$port}/"));

            $this->assertInstanceOf(TransportUnavailable::class, $thrown, (string) $bundle);
            $this->assertStringNotContainsString(self::SECRET_MARK, $thrown->getMessage());
        }
    }

    private function context(): IssuerContext
    {
        return $this->app->make(VerifactuIssuerResolver::class)->resolve(Nif::parse(VerifactuSamples::ISSUER_NIF));
    }

    private function writeCertificate(string $commonName): void
    {
        $issued = AeatCertificates::client(['commonName' => $commonName, 'serialNumber' => 'IDCES-'.VerifactuSamples::ISSUER_NIF]);
        file_put_contents($this->certificatePath, AeatCertificates::pkcs12($issued['certificate'], $issued['privateKey'], self::PASSWORD));
    }

    /**
     * @return list<string>
     */
    private function materializedFiles(): array
    {
        return glob($this->storage.'/einvoicing/verifactu/tls/*/aeat-*.pem') ?: [];
    }

    /**
     * @param  list<string>  $files
     */
    private function backdate(array $files): void
    {
        foreach ($files as $file) {
            touch($file, time() - 2 * 86400);
        }
        clearstatcache();
    }

    private function httpClient(): HttpClient
    {
        $factory = new HttpFactory;

        return new HttpClient(new FakeAeatApi, $factory, $factory);
    }

    private function capture(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private function startServer(string $certificate, string $key, string $caFile): int
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__).'/Support/tls-echo-server.php', $certificate, $key, $caFile, (string) self::SERVER_LIFETIME];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the TLS test server.');
        }
        $this->server = $process;
        $this->pipes = $pipes;
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);

        $line = '';
        $deadline = hrtime(true) / 1e9 + self::SERVER_LIFETIME;
        while (! str_contains($line, "\n")) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0 || ! proc_get_status($process)['running']) {
                stream_set_blocking($pipes[2], false);
                $this->fail('The TLS test server did not start: '.stream_get_contents($pipes[2]));
            }
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) min(200_000, $remaining * 1e6)) > 0) {
                $line .= (string) fread($pipes[1], 64);
            }
        }
        if (preg_match('/\APORT (\d+)\n/', $line, $match) !== 1) {
            $this->fail('The TLS test server reported no port.');
        }

        return (int) $match[1];
    }
}
