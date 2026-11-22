<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\InboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentReceived;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Jobs\FetchInboundDocument;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;
use IntrAgile\EInvoicing\Laravel\Support\InboundRecorder;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Poland\KsefNumber;
use IntrAgile\EInvoicing\Transport\Ksef\Inbound\KsefCursorStore;
use IntrAgile\EInvoicing\Transport\Ksef\KsefTransport;

final class KsefInboundTest extends TestCase
{
    use KsefInvoices;
    use RefreshDatabase;

    private const RECEIVED = '5555555555-20261120-010080615740-BB';

    private const RECEIVED_ID = self::RECEIVED.'@2026-11-21T09:00:00.000000Z';

    private const RECEIVED_XML = '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
    }

    private function bindInbox(FakeKsefApi $api): FakeKsefApi
    {
        config()->set('einvoicing.ksef.nip', FakeKsefApi::NIP);
        $this->api = $api;
        $this->app->instance(KsefTransport::class, $api->transport($this->app->make(KsefCursorStore::class)));

        return $api;
    }

    /**
     * @param  list<string>  $ksefNumbers
     */
    private static function metadataPage(array $ksefNumbers): Response
    {
        return FakeKsefApi::json(200, [
            'hasMore' => false,
            'isTruncated' => false,
            'permanentStorageHwmDate' => '2026-11-21T09:59:00.000000+00:00',
            'invoices' => array_map(static fn (string $number): array => [
                'ksefNumber' => $number,
                'invoiceNumber' => 'FA/1/2026',
                'issueDate' => '2026-11-20',
                'invoicingDate' => '2026-11-21T09:00:00.000+00:00',
                'acquisitionDate' => '2026-11-21T09:00:00.000+00:00',
                'permanentStorageDate' => '2026-11-21T09:00:00.000+00:00',
                'seller' => ['nip' => substr($number, 0, 10), 'name' => 'Test Seller'],
                'buyer' => ['identifier' => ['type' => 'Nip', 'value' => FakeKsefApi::NIP], 'name' => 'Test Buyer'],
                'netAmount' => 100.0,
                'grossAmount' => 123.0,
                'vatAmount' => 23.0,
                'currency' => 'PLN',
                'invoicingMode' => 'Online',
                'invoiceType' => 'Vat',
                'formCode' => ['systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'],
                'isSelfInvoicing' => false,
                'hasAttachment' => false,
                'invoiceHash' => base64_encode(hash('sha256', self::RECEIVED_XML, true)),
                'thirdSubjects' => [],
            ], $ksefNumbers),
        ]);
    }

    /**
     * @return list<string>
     */
    private function metadataQueries(): array
    {
        return array_values(array_filter($this->api->paths(), static fn (string $path): bool => $path === 'POST /invoices/query/metadata'));
    }

    public function test_ksef_inbound_records_and_fetches_through_existing_jobs(): void
    {
        Event::fake([DocumentReceived::class]);
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', self::metadataPage([self::RECEIVED]))
            ->on('GET', '#\A/invoices/ksef/[A-Za-z0-9-]+\z#', new Response(200, ['Content-Type' => 'application/xml'], self::RECEIVED_XML)));

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])
            ->expectsOutputToContain('1 new')
            ->assertSuccessful();

        $row = InboundMessage::query()->where('transport', 'ksef')->sole();
        // The full id pending() returned is kept, so a later fetch needs no metadata query.
        $this->assertSame(self::RECEIVED_ID, $row->provider_document_id);
        $this->assertSame(self::RECEIVED, KsefTransport::ksefNumberOf($row->provider_document_id));
        $this->assertSame(InboundState::Acknowledged, $row->state);
        $this->assertSame(self::RECEIVED_XML, InboundMessage::query()->findOrFail($row->id)->xml);
        $this->assertSame('FA (3)', $row->document_type);
        Event::assertDispatched(DocumentReceived::class, fn (DocumentReceived $event): bool => $event->message->id === $row->id);
        // The acknowledged window moved the stored cursor on.
        $this->assertSame(1, DB::table('einvoicing_ksef_cursors')->count());
        $this->assertSame(EInvoicing::inbound('ksef'), $this->app->make(KsefTransport::class));
    }

    public function test_poll_inside_min_interval_is_skipped(): void
    {
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', self::metadataPage([]), self::metadataPage([])));

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
        $this->assertCount(1, $this->metadataQueries());

        // Ten minutes later the inbox is not read again.
        $this->travel(10)->minutes();
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])
            ->expectsOutputToContain('inbound_min_interval_minutes')
            ->assertSuccessful();
        $this->assertCount(1, $this->metadataQueries());

        // Once the interval has passed it is.
        $this->travel(6)->minutes();
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
        $this->assertCount(2, $this->metadataQueries());
    }

    public function test_on_time_run_after_a_late_one_is_not_skipped(): void
    {
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', self::metadataPage([]), self::metadataPage([])));

        // The scheduler starts run 1 a few seconds late and run 2 exactly on time.
        $this->travelTo(new \DateTimeImmutable('2026-11-21T10:00:07+00:00'));
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
        $this->travelTo(new \DateTimeImmutable('2026-11-21T10:15:00+00:00'));
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])
            ->doesntExpectOutputToContain('Skipped')
            ->assertSuccessful();

        $this->assertCount(2, $this->metadataQueries());
    }

    public function test_fetch_in_a_fresh_worker_spends_no_metadata_query(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', self::metadataPage([self::RECEIVED])));
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
        $row = InboundMessage::query()->where('transport', 'ksef')->sole();

        // Another process: a new transport that never listed the inbox.
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('GET', '#\A/invoices/ksef/[A-Za-z0-9-]+\z#', new Response(200, ['Content-Type' => 'application/xml'], self::RECEIVED_XML)));
        (new FetchInboundDocument($row->id))->handle($this->app->make(EInvoicingManager::class));

        $this->assertSame([], $this->metadataQueries());
        $this->assertContains('GET /invoices/ksef/'.self::RECEIVED, $this->api->paths());
        $this->assertSame(self::RECEIVED_XML, InboundMessage::query()->findOrFail($row->id)->xml);
    }

    public function test_one_invoice_listed_in_both_id_forms_is_recorded_once(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $recorder = $this->app->make(InboundRecorder::class);

        $first = $recorder->notify('ksef', self::RECEIVED_ID);
        $plain = $recorder->notify('ksef', self::RECEIVED);
        $again = $recorder->notify('ksef', self::RECEIVED_ID);

        $this->assertSame(1, InboundMessage::query()->where('transport', 'ksef')->count());
        $this->assertSame($first->id, $plain->id);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(self::RECEIVED_ID, $plain->provider_document_id);

        // The other order as well: a plain id first, the full one later.
        $other = '5555555555-20261120-010080615741-'.KsefNumber::checksum('5555555555-20261120-010080615741');
        $recorder->notify('ksef', $other);
        $recorder->notify('ksef', $other.'@2026-11-21T09:00:00.000000Z');
        $this->assertSame(2, InboundMessage::query()->where('transport', 'ksef')->count());
        Queue::assertPushed(FetchInboundDocument::class, 2);
    }

    public function test_min_interval_of_zero_never_skips(): void
    {
        config()->set('einvoicing.ksef.inbound_min_interval_minutes', 0);
        $this->bindInbox((new FakeKsefApi)->withTokenAuthentication()
            ->on('POST', '#\A/invoices/query/metadata\z#', self::metadataPage([]), self::metadataPage([])));

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'ksef'])->assertSuccessful();

        $this->assertCount(2, $this->metadataQueries());
    }

    public function test_other_transports_have_no_min_interval(): void
    {
        $this->configureRecommand();
        EInvoicing::fake();

        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'recommand'])->assertSuccessful();
        $this->artisan('einvoicing:poll-inbound', ['--transport' => 'recommand'])
            ->doesntExpectOutputToContain('inbound_min_interval_minutes')
            ->assertSuccessful();
    }
}
