<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use IntrAgile\EInvoicing\France\Lifecycle\LifecycleStatus;
use IntrAgile\EInvoicing\Laravel\Events\EReportStateChanged;
use IntrAgile\EInvoicing\Laravel\Events\LifecycleStatusChanged;
use IntrAgile\EInvoicing\Laravel\Events\WebhookReceived;
use IntrAgile\EInvoicing\Laravel\Facades\EInvoicing;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;

final class WebhookControllerFranceTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/einvoicing/webhooks/b2brouter';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('einvoicing.transports.b2brouter', [
            'driver' => 'b2brouter',
            'api_key' => 'test_'.self::SECRET_MARK,
            'account_id' => '78234',
            'webhook_secret' => 'wh-'.self::SECRET_MARK,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        EInvoicing::fake();
        Event::fake([LifecycleStatusChanged::class, EReportStateChanged::class, WebhookReceived::class]);
    }

    /**
     * Posts a B2Brouter notification signed as B2Brouter signs it: an HMAC-SHA256 of
     * "<unix time>.<raw body>" in the X-B2Brouter-Signature header.
     */
    private function signedB2Brouter(string $raw): TestResponse
    {
        $timestamp = (string) time();
        $signature = 't='.$timestamp.',s='.hash_hmac('sha256', $timestamp.'.'.$raw, 'wh-'.self::SECRET_MARK);

        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_B2BROUTER_SIGNATURE' => $signature,
        ], $raw);
    }

    public function test_lifecycle_webhook_dispatches_event(): void
    {
        $this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":78234,"state":"accepted"}}')
            ->assertNoContent();
        $this->signedB2Brouter('{"code":"received_invoice.state_change","data":{"invoice_id":"5120","account_id":78234,"state":"refused"}}')
            ->assertNoContent();

        Event::assertDispatchedTimes(LifecycleStatusChanged::class, 2);
        Event::assertDispatched(LifecycleStatusChanged::class, static fn (LifecycleStatusChanged $event): bool => $event->transport === 'b2brouter'
            && $event->documentId === '4711'
            && $event->status === LifecycleStatus::Approved
            && $event->received === false);
        Event::assertDispatched(LifecycleStatusChanged::class, static fn (LifecycleStatusChanged $event): bool => $event->transport === 'b2brouter'
            && $event->documentId === '5120'
            && $event->status === LifecycleStatus::Refused
            && $event->received === true);
        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertNotDispatched(EReportStateChanged::class);
    }

    public function test_state_without_lifecycle_status_dispatches_no_lifecycle_event(): void
    {
        $this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":78234,"state":"error"}}')
            ->assertNoContent();
        $this->signedB2Brouter('{"code":"issued_invoice.created","data":{"invoice_id":4712,"account_id":78234,"state":"new"}}')
            ->assertNoContent();

        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertNotDispatched(LifecycleStatusChanged::class);
        Event::assertNotDispatched(EReportStateChanged::class);
    }

    public function test_ledger_webhook_dispatches_event(): void
    {
        $this->signedB2Brouter('{"code":"ledger.state_change","data":{"account_id":78234,"event_id":"ev-1","object":{"object":"ledger","id":991,"state":"accepted"}}}')
            ->assertNoContent();
        // The documented shape without an event code.
        $this->signedB2Brouter('{"account_id":78234,"object":{"object":"ledger","id":992,"state":"error"}}')
            ->assertNoContent();

        Event::assertDispatchedTimes(EReportStateChanged::class, 2);
        Event::assertDispatched(EReportStateChanged::class, static fn (EReportStateChanged $event): bool => $event->transport === 'b2brouter'
            && $event->ledgerId === '991');
        Event::assertDispatched(EReportStateChanged::class, static fn (EReportStateChanged $event): bool => $event->ledgerId === '992');
        Event::assertNotDispatched(LifecycleStatusChanged::class);
    }

    public function test_foreign_account_dispatches_no_france_event(): void
    {
        $this->signedB2Brouter('{"code":"issued_invoice.state_change","data":{"invoice_id":4711,"account_id":99999,"state":"accepted"}}')
            ->assertNoContent();
        $this->signedB2Brouter('{"account_id":99999,"object":{"object":"ledger","id":991,"state":"accepted"}}')
            ->assertNoContent();

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(LifecycleStatusChanged::class);
        Event::assertNotDispatched(EReportStateChanged::class);
    }

    public function test_unsigned_france_notification_dispatches_nothing(): void
    {
        $raw = '{"code":"ledger.state_change","data":{"object":{"object":"ledger","id":991}}}';

        $this->call('POST', self::URI, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_B2BROUTER_SIGNATURE' => 't=1,s='.str_repeat('0', 64)], $raw)
            ->assertUnauthorized();

        Event::assertNotDispatched(EReportStateChanged::class);
    }
}
