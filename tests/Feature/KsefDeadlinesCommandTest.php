<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\KsefOfflineDeadlineApproaching;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\Support\FakeKsefApi;
use IntrAgile\EInvoicing\Laravel\Tests\Support\KsefInvoices;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\Http\HttpClient;
use IntrAgile\EInvoicing\Transport\Ksef\Latarnia\Latarnia;

final class KsefDeadlinesCommandTest extends TestCase
{
    use KsefInvoices;
    use RefreshDatabase;

    private FakeKsefApi $latarnia;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([KsefOfflineDeadlineApproaching::class]);
        config()->set('einvoicing.ksef.nip', FakeKsefApi::NIP);
        // Monday 2026-11-23, 10:00 in Warsaw, given in UTC (the application timezone): older Carbon
        // releases read database timestamps in the timezone of the test clock.
        $this->travelTo(new DateTimeImmutable('2026-11-23T09:00:00Z'));
    }

    /**
     * Binds a Latarnia client answering the message list with the given answer.
     */
    private function bindLatarnia(Response $messages): void
    {
        $this->latarnia = (new FakeKsefApi)->on('GET', '#\A/messages\z#', $messages);
        $factory = new HttpFactory;
        $this->app->instance(Latarnia::class, new Latarnia('https://api-latarnia-test.ksef.mf.gov.pl', new HttpClient($this->latarnia, $factory, $factory)));
    }

    /**
     * Offline rows: one due today, one due tomorrow, one delivered, and an online one.
     *
     * @return array{OutboundMessage, OutboundMessage}
     */
    private function rows(): array
    {
        $dueToday = $this->ksefRow(OutboundState::Queued, ['invoicing_mode' => 'Offline', 'reference' => 'FV/DUE/TODAY']);
        $dueTomorrow = $this->ksefRow(OutboundState::Queued, [
            'invoicing_mode' => 'Offline',
            'reference' => 'FV/DUE/TOMORROW',
            'xml' => self::fa3('2026-11-23', 'FV/DUE/TOMORROW'),
        ]);
        $this->ksefRow(OutboundState::Delivered, ['invoicing_mode' => 'Offline', 'reference' => 'FV/DELIVERED', 'ksef_number' => FakeKsefApi::KSEF_NUMBER]);
        $this->ksefRow(OutboundState::Failed, [
            'invoicing_mode' => 'Offline',
            'reference' => 'FV/FAILED',
            'failure_category' => FailureCategory::Validation,
            'provider_document_id' => FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::INVOICE_REF.'-F',
        ]);
        $this->ksefRow(OutboundState::Pending, [
            'reference' => 'FV/ONLINE',
            'provider_document_id' => FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::SECOND_INVOICE_REF,
        ]);

        return [$dueToday, $dueTomorrow];
    }

    private function deadlines(): string
    {
        $exitCode = Artisan::call('einvoicing:ksef-deadlines');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    public function test_deadlines_command_lists_and_fires_event_within_warning_window(): void
    {
        $this->bindLatarnia(FakeKsefApi::json(200, []));
        [$dueToday, $dueTomorrow] = $this->rows();

        $output = $this->deadlines();

        $this->assertMatchesRegularExpression('#FV/DUE/TODAY .*2026-11-23 23:59 \(due soon\)#', $output);
        $this->assertMatchesRegularExpression('#FV/DUE/TOMORROW .*2026-11-24 23:59 #', $output);
        $this->assertStringNotContainsString('FV/DELIVERED', $output);
        $this->assertStringNotContainsString('FV/ONLINE', $output);
        $this->assertStringNotContainsString('FV/FAILED', $output);

        Event::assertDispatched(KsefOfflineDeadlineApproaching::class, 1);
        Event::assertDispatched(
            KsefOfflineDeadlineApproaching::class,
            fn (KsefOfflineDeadlineApproaching $event): bool => $event->message->id === $dueToday->id
                && $event->deadline->format('Y-m-d H:i:s') === '2026-11-23 23:59:59',
        );
        $this->assertSame(['GET /messages'], $this->latarnia->paths());
        // The computed deadline is kept on the row.
        $stored = OutboundMessage::query()->findOrFail($dueTomorrow->id)->offline_deadline;
        $this->assertNotNull($stored);
        $this->assertSame('2026-11-24 22:59:59', $stored->utc()->format('Y-m-d H:i:s'));
    }

    public function test_offline_row_failed_on_a_transport_error_is_still_listed_and_warned(): void
    {
        $this->bindLatarnia(FakeKsefApi::json(200, []));
        $transport = $this->ksefRow(OutboundState::Failed, [
            'invoicing_mode' => 'Offline',
            'reference' => 'FV/FAILED/TRANSPORT',
            'failure_category' => FailureCategory::Transport,
            'provider_document_id' => null,
        ]);
        $this->ksefRow(OutboundState::Failed, [
            'invoicing_mode' => 'Offline',
            'reference' => 'FV/FAILED/DUPLICATE',
            'failure_category' => FailureCategory::Duplicate,
            'provider_document_id' => FakeKsefApi::SESSION_REF.'/'.FakeKsefApi::INVOICE_REF.'-D',
        ]);

        $output = $this->deadlines();

        $this->assertMatchesRegularExpression('#FV/FAILED/TRANSPORT .*failed.*2026-11-23 23:59 \(due soon\)#', $output);
        $this->assertStringContainsString('einvoicing:resolve --resend', $output);
        $this->assertStringNotContainsString('FV/FAILED/DUPLICATE', $output);
        Event::assertDispatched(KsefOfflineDeadlineApproaching::class, 1);
        Event::assertDispatched(KsefOfflineDeadlineApproaching::class, fn (KsefOfflineDeadlineApproaching $event): bool => $event->message->id === $transport->id);
    }

    public function test_deadlines_command_applies_an_announced_failure(): void
    {
        // A failure announced on Saturday that has not ended: the invoice due today has no known
        // deadline; the one issued today, after the failure began, keeps its own.
        $this->bindLatarnia(FakeKsefApi::json(200, [[
            'id' => 'X/2026/F/01',
            'eventId' => 1,
            'category' => 'FAILURE',
            'type' => 'FAILURE_START',
            'title' => 'Failure',
            'text' => 'KSeF failure.',
            'start' => '2026-11-21T08:00:00+00:00',
            'end' => null,
            'version' => 1,
            'published' => '2026-11-21T08:05:00+00:00',
        ]]));
        [$dueToday] = $this->rows();

        $output = $this->deadlines();

        $this->assertMatchesRegularExpression('#FV/DUE/TODAY .*unknown \(failure ongoing\)#', $output);
        $this->assertMatchesRegularExpression('#FV/DUE/TOMORROW .*2026-11-24 23:59 #', $output);

        Event::assertNotDispatched(KsefOfflineDeadlineApproaching::class);
        $this->assertNull(OutboundMessage::query()->findOrFail($dueToday->id)->offline_deadline);
    }

    public function test_deadlines_command_without_latarnia_still_lists(): void
    {
        $this->bindLatarnia(new Response(503, [], 'Service Unavailable'));
        [$dueToday] = $this->rows();

        $output = $this->deadlines();

        $this->assertStringContainsString('Latarnia could not be read', $output);
        $this->assertStringContainsString('without announced outages', $output);
        $this->assertMatchesRegularExpression('#FV/DUE/TODAY .*2026-11-23 23:59#', $output);

        Event::assertDispatched(KsefOfflineDeadlineApproaching::class, fn (KsefOfflineDeadlineApproaching $event): bool => $event->message->id === $dueToday->id);
    }

    public function test_deadlines_command_with_nothing_offline_says_so(): void
    {
        $this->bindLatarnia(FakeKsefApi::json(200, []));

        $this->artisan('einvoicing:ksef-deadlines')
            ->expectsOutputToContain('No offline KSeF invoice')
            ->assertSuccessful();

        // Without rows Latarnia is not asked.
        $this->assertSame([], $this->latarnia->paths());
    }
}
