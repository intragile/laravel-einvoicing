<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Live;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use IntrAgile\EInvoicing\Calculation\TotalsCalculator;
use IntrAgile\EInvoicing\CodeList\CountryCode;
use IntrAgile\EInvoicing\CodeList\CurrencyCode;
use IntrAgile\EInvoicing\CodeList\InvoiceTypeCode;
use IntrAgile\EInvoicing\CodeList\UnitCode;
use IntrAgile\EInvoicing\CodeList\VatCategory;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentDelivered;
use IntrAgile\EInvoicing\Laravel\Events\DocumentFailed;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\PollKsefStatus;
use IntrAgile\EInvoicing\Laravel\Ksef\KsefManager;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefLiveTap;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Model\Invoice;
use IntrAgile\EInvoicing\Model\InvoiceLine;
use IntrAgile\EInvoicing\Model\Item;
use IntrAgile\EInvoicing\Model\LineVat;
use IntrAgile\EInvoicing\Model\Party;
use IntrAgile\EInvoicing\Model\PostalAddress;
use IntrAgile\EInvoicing\Model\Price;
use IntrAgile\EInvoicing\Poland\PolishInvoice;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\Auth\CertificateCredentials;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;
use IntrAgile\EInvoicing\Transport\Ksef\Testing\SelfSignedCertificate;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Sends and receives an FA(3) invoice through the installed bridge on KSeF TE, the KSeF test
 * environment, which accepts self-signed seals.
 *
 * The seller and the buyer are random checksum-valid NIPs, each with a fresh self-signed seal
 * whose certificate and private key are written, readable by the owner only, into a temporary
 * directory that is removed afterwards; einvoicing.ksef points at those files. The seller queues
 * the invoice on the sync connection, so the send and the status poll run inline, and the row
 * must end delivered with its KSeF number and receipt (UPO). The buyer then runs
 * einvoicing:poll-inbound --transport=ksef, at most INBOX_POLLS times INBOX_WAIT_SECONDS apart, as
 * the buyer inbox lists an invoice about two minutes after acceptance, until DocumentReceived
 * fires; the run guard is disabled (inbound_min_interval_minutes 0) for those bounded polls. The
 * test spends at most METADATA_BUDGET metadata queries, within KSeF's 20 per hour.
 *
 * The KSeF transport is built from the bridge's configuration (KsefManager::ksefConfig(), the
 * cursor store from the container, the response size cap from einvoicing.ksef) over an HTTP
 * client whose PSR-18 client refuses every KSeF host but the TE API and has fixed timeouts, and is
 * bound in the container, so every call goes through that guard.
 *
 * Every invoice is synthetic: invented names and addresses, no bank account. No key or token is
 * ever printed. No test starts in or within twenty minutes of TE's daily maintenance window
 * (16:00-18:00 Europe/Warsaw), so a run that polls the inbox to its limit still ends before
 * 15:55.
 */
#[Group('ksef-live')]
final class KsefLiveTest extends TestCase
{
    use RefreshDatabase;

    private const TAG = 'ksef-live';

    private const ZONE = 'Europe/Warsaw';

    /**
     * The first minute of the day (Europe/Warsaw) at which no live test starts: the test can take
     * several minutes, and nothing may reach TE from 15:55.
     */
    private const QUIET_FROM_MINUTE = 15 * 60 + 40;

    /** The minute of the day (Europe/Warsaw) at which the maintenance window ends. */
    private const QUIET_TO_MINUTE = 18 * 60;

    private const INBOX_POLLS = 10;

    private const INBOX_WAIT_SECONDS = 25;

    /** Metadata queries the test may spend, within KSeF's 20 per hour per context. */
    private const METADATA_BUDGET = 15;

    /** Runs of the status job after the inline one, should the receipt not be issued at once. */
    private const RECEIPT_RETRIES = 3;

    private const RECEIPT_WAIT_SECONDS = 10;

    /** @var list<object> the bridge events fired during the test */
    private array $events = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.queue.connection', null);
        $app['config']->set('einvoicing.ksef.environment', 'test');
        $app['config']->set('einvoicing.ksef.inbound_min_interval_minutes', 0);
    }

    protected function setUp(): void
    {
        $reason = self::maintenanceReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }

        parent::setUp();
    }

    public function test_send_through_the_queue_and_receive_through_poll_inbound(): void
    {
        $started = microtime(true);
        $seller = SelfSignedCertificate::randomNip();
        do {
            $buyer = SelfSignedCertificate::randomNip();
        } while ($buyer === $seller);
        $dir = $this->emptyDir();
        chmod($dir, 0700);

        foreach ([DocumentSent::class, DocumentDelivered::class, DocumentFailed::class, DocumentUnconfirmed::class, DocumentReceived::class] as $event) {
            Event::listen($event, function (object $fired): void {
                $this->events[] = $fired;
            });
        }

        // Seller: queue on the sync connection; the send and the status poll run inline.
        $sellerTap = $this->useContext($seller, $dir.'/seller');
        $invoice = self::invoice($seller, $buyer);
        $step = microtime(true);
        $row = EInvoicing::ksef()->queue($invoice, reference: $invoice->number);
        $row->refresh();
        $retries = 0;
        while ($row->state === OutboundState::Delivered && $row->upo === null && $retries < self::RECEIPT_RETRIES) {
            sleep(self::RECEIPT_WAIT_SECONDS);
            $retries++;
            PollKsefStatus::dispatchSync($row->id, $row->attempts);
            $row->refresh();
        }
        self::report(sprintf(
            'seller: row %s, KSeF number %s, invoicing mode %s, UPO %s (%d extra status run(s)), %.1f s, %d TE API requests',
            $row->state->value,
            $row->ksef_number ?? '-',
            $row->invoicing_mode ?? '-',
            $row->upo === null ? 'missing' : sprintf('%d bytes', strlen($row->upo)),
            $retries,
            microtime(true) - $step,
            $sellerTap->count(),
        ));
        self::assertSame(OutboundState::Delivered, $row->state, (string) $row->failure_message);
        self::assertNotNull($row->ksef_number);
        self::assertStringStartsWith($seller.'-', $row->ksef_number);
        self::assertNotNull($row->upo);
        self::assertStringContainsString($row->ksef_number, $row->upo);
        self::assertSame('Online', $row->invoicing_mode);
        self::assertCount(1, $this->fired(DocumentSent::class));
        self::assertCount(1, $this->fired(DocumentDelivered::class));
        self::assertSame([], $this->fired(DocumentFailed::class));
        self::assertSame([], $this->fired(DocumentUnconfirmed::class));
        $ksefNumber = $row->ksef_number;
        $sentXml = OutboundMessage::query()->findOrFail($row->id)->xml;

        // Buyer: a second context with its own seal polls its inbox through the command.
        $buyerTap = $this->useContext($buyer, $dir.'/buyer');
        $step = microtime(true);
        $polls = 0;
        $received = null;
        while ($received === null && $polls < self::INBOX_POLLS) {
            if ($polls > 0) {
                sleep(self::INBOX_WAIT_SECONDS);
            }
            $polls++;
            $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
            foreach ($this->fired(DocumentReceived::class) as $event) {
                if (KsefTransport::ksefNumberOf($event->message->provider_document_id) === $ksefNumber) {
                    $received = $event;
                }
            }
        }
        $metadata = $buyerTap->countOf(KsefLiveTap::METADATA_QUERY) + $sellerTap->countOf(KsefLiveTap::METADATA_QUERY);
        self::report(sprintf(
            'buyer: %s after %d poll(s), %.1f s, %d TE API requests, %d metadata queries (seller %d, buyer %d)',
            $received === null ? 'not received' : 'DocumentReceived',
            $polls,
            microtime(true) - $step,
            $buyerTap->count(),
            $metadata,
            $sellerTap->countOf(KsefLiveTap::METADATA_QUERY),
            $buyerTap->countOf(KsefLiveTap::METADATA_QUERY),
        ));
        self::assertLessThanOrEqual(self::METADATA_BUDGET, $metadata);
        self::assertNotNull($received, sprintf('The buyer inbox did not list %s within %d polls.', $ksefNumber, self::INBOX_POLLS));
        $inbound = InboundMessage::query()->findOrFail($received->message->id);
        self::assertSame('ksef', $inbound->transport);
        self::assertSame(InboundState::Acknowledged, $inbound->state);
        self::assertSame('FA (3)', $inbound->document_type);
        self::assertSame($sentXml, $inbound->xml);

        self::report(sprintf(
            'total: %d TE API requests, %d requests to other hosts, %d metadata queries, %.1f s',
            $sellerTap->count() + $buyerTap->count(),
            $sellerTap->otherHosts() + $buyerTap->otherHosts(),
            $metadata,
            microtime(true) - $started,
        ));
    }

    /**
     * Points einvoicing.ksef at a fresh self-signed seal of the NIP, written into the directory,
     * and binds a KSeF transport built from that configuration over a guarded client; returns the
     * client's tap.
     */
    private function useContext(string $nip, string $dir): KsefLiveTap
    {
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create the credentials directory.');
        }
        $seal = SelfSignedCertificate::seal($nip, 'Testowa Pieczęć '.substr($nip, -4));
        config()->set('einvoicing.ksef.nip', $nip);
        config()->set('einvoicing.ksef.credentials', [
            'certificate' => self::writePrivate($dir.'/certificate.pem', $seal->certificatePem),
            'private_key' => self::writePrivate($dir.'/private-key.pem', $seal->privateKeyPem()),
            'passphrase' => null,
            'token' => null,
        ]);

        $manager = $this->app->make(KsefManager::class);
        $config = $manager->ksefConfig();
        self::assertSame(KsefLiveTap::BASE_URL, $config->baseUrl, 'The KSeF live test runs only against KSeF TE.');
        self::assertInstanceOf(CertificateCredentials::class, $config->credentials);
        $tap = new KsefLiveTap;
        $factory = new HttpFactory;
        $http = new HttpClient($tap, $factory, $factory, $manager->settings()->maxResponseBytes, $config->secrets());
        $this->app->instance(KsefTransport::class, KsefTransport::create($config, $http, $this->app->make(KsefCursorStore::class)));

        return $tap;
    }

    /**
     * Writes the contents into a new file readable and writable by the owner only, the mode set
     * before anything is written; returns the path.
     */
    private static function writePrivate(string $path, string $contents): string
    {
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create a credentials file.');
        }
        try {
            chmod($path, 0600);
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write a credentials file.');
            }
        } finally {
            fclose($handle);
        }
        self::assertSame(0600, fileperms($path) & 0777);

        return $path;
    }

    /**
     * A synthetic domestic VAT invoice in PLN from the seller NIP to the buyer NIP, issued today
     * (Europe/Warsaw), with a unique number.
     */
    private static function invoice(string $sellerNip, string $buyerNip): Invoice
    {
        $today = new DateTimeImmutable('today', new DateTimeZone(self::ZONE));

        return TotalsCalculator::complete((new Invoice(
            number: 'FV/LARAVEL/'.strtoupper(bin2hex(random_bytes(5))),
            issueDate: $today,
            type: InvoiceTypeCode::C_380,
            currency: CurrencyCode::PLN,
            seller: new Party(
                name: 'Testowy Sprzedawca '.substr($sellerNip, -4).' Sp. z o.o.',
                vatIdentifier: 'PL'.$sellerNip,
                address: new PostalAddress(country: CountryCode::PL, line1: 'ul. Przykładowa 1', city: 'Testowo', postCode: '00-950'),
            ),
            buyer: new Party(
                name: 'Testowy Nabywca '.substr($buyerNip, -4).' S.A.',
                vatIdentifier: 'PL'.$buyerNip,
                address: new PostalAddress(country: CountryCode::PL, line1: 'ul. Wymyślona 2', city: 'Testowo', postCode: '00-950'),
            ),
            lines: [
                new InvoiceLine(id: '1', quantity: '2', unit: UnitCode::HUR, price: new Price('100.00'), item: new Item('Usługa testowa'), vat: new LineVat(VatCategory::StandardRate, '23')),
                new InvoiceLine(id: '2', quantity: '3', unit: UnitCode::H87, price: new Price('10.00'), item: new Item('Materiał testowy'), vat: new LineVat(VatCategory::StandardRate, '8')),
            ],
            dueDate: $today->modify('+14 days'),
        ))->withExtension(new PolishInvoice(placeOfIssue: 'Testowo')));
    }

    /**
     * The fired bridge events of the class.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private function fired(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $class));
    }

    /**
     * Why a live test must not start now, or null: from twenty minutes before TE's daily
     * maintenance window until its end.
     */
    private static function maintenanceReason(): ?string
    {
        $warsaw = new DateTimeImmutable('now', new DateTimeZone(self::ZONE));
        $minute = (int) $warsaw->format('G') * 60 + (int) $warsaw->format('i');
        if ($minute >= self::QUIET_FROM_MINUTE && $minute < self::QUIET_TO_MINUTE) {
            return sprintf('KSeF TE is in or within twenty minutes of its daily maintenance window (16:00-18:00 Europe/Warsaw, now %s).', $warsaw->format('H:i'));
        }

        return null;
    }

    private static function report(string $line): void
    {
        fwrite(STDERR, PHP_EOL.'['.self::TAG.'] '.$line.PHP_EOL);
    }
}
