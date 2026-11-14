<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Exceptions\ConfigurationException;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;
use IntrAgile\EInvoicing\Laravel\Tests\TestCase;
use IntrAgile\EInvoicing\Transport\FailureCategory;
use IntrAgile\EInvoicing\Transport\OutboundDocument;

final class ResolveCommandTest extends TestCase
{
    use RefreshDatabase;

    /** SendDocument timeout with the default HTTP settings (60 + 10 + 30), plus 30 seconds. */
    private const CLAIM_LIFETIME = 130;

    private const CONFIRMATION = 'The access point may already have accepted this document; sending it again may deliver a duplicate invoice. Queue it again?';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'database');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([DocumentSent::class]);
    }

    private function message(OutboundState $state, int $attempts = 1, ?string $providerId = null): OutboundMessage
    {
        $xml = file_get_contents(dirname(__DIR__).'/fixtures/bis-invoice.xml');
        $this->assertIsString($xml);

        $message = OutboundMessage::fromDocument(OutboundDocument::fromXml($xml), 'recommand', 'INV-1');
        $message->state = $state;
        $message->attempts = $attempts;
        $message->provider_document_id = $providerId;
        if ($state === OutboundState::Unconfirmed) {
            $message->failure_message = 'The request timed out after it was sent.';
        }
        if ($state === OutboundState::Failed) {
            $message->failure_category = FailureCategory::Transport;
            $message->failure_message = 'Connection refused.';
            $message->finished_at = now();
        }
        $message->save();

        return $message;
    }

    private function stored(OutboundMessage $message): OutboundMessage
    {
        return OutboundMessage::query()->findOrFail($message->id);
    }

    public function test_resolve_sent_moves_to_pending(): void
    {
        $message = $this->message(OutboundState::Unconfirmed, 2);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => 'doc-9'])
            ->expectsOutputToContain('pending')
            ->assertExitCode(Command::SUCCESS);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Pending, $row->state);
        $this->assertSame('doc-9', $row->provider_document_id);
        $this->assertSame(2, $row->attempts);
        $this->assertNull($row->failure_message);
        $this->assertNotNull($row->sent_at);
        Event::assertDispatched(DocumentSent::class, fn (DocumentSent $e): bool => $e->message->id === $message->id);
        Queue::assertPushed(PollDeliveryStatus::class, fn (PollDeliveryStatus $job): bool => $job->messageId === $message->id && $job->delay === 60 && $job->attempt === 2);
        Queue::assertNotPushed(SendDocument::class);
    }

    public function test_resolve_sent_on_sync_connection_schedules_no_poll(): void
    {
        config()->set('einvoicing.queue.connection', 'sync');
        $message = $this->message(OutboundState::Unconfirmed);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => 'doc-9'])->assertExitCode(Command::SUCCESS);

        $this->assertSame(OutboundState::Pending, $this->stored($message)->state);
        Queue::assertNothingPushed();
    }

    public function test_resolve_sent_rejects_a_provider_id_in_use(): void
    {
        $this->message(OutboundState::Delivered, 1, 'doc-9');
        $message = $this->message(OutboundState::Unconfirmed);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => 'doc-9'])
            ->expectsOutputToContain('already')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);
    }

    public function test_resolve_sent_rejects_an_empty_id(): void
    {
        $message = $this->message(OutboundState::Unconfirmed);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => ' '])->assertExitCode(Command::INVALID);

        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);
    }

    public function test_resolve_resend_unconfirmed_needs_force(): void
    {
        $message = $this->message(OutboundState::Unconfirmed, 3);

        // Without a terminal to confirm on, --force is required.
        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true, '--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertExitCode(Command::INVALID);
        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);

        // Declining the confirmation changes nothing.
        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true])
            ->expectsConfirmation(self::CONFIRMATION, 'no')
            ->assertExitCode(Command::FAILURE);
        $this->assertSame(OutboundState::Unconfirmed, $this->stored($message)->state);
        Queue::assertNothingPushed();

        // Confirming queues it again.
        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true])
            ->expectsConfirmation(self::CONFIRMATION, 'yes')
            ->assertExitCode(Command::SUCCESS);
        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);

        // --force skips the question.
        $other = $this->message(OutboundState::Unconfirmed, 3);
        $this->artisan('einvoicing:resolve', ['id' => $other->id, '--resend' => true, '--force' => true, '--no-interaction' => true])
            ->assertExitCode(Command::SUCCESS);

        $row = $this->stored($other);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(3, $row->attempts);
        $this->assertNull($row->failure_message);
        Queue::assertPushed(SendDocument::class, 2);
        Queue::assertPushed(SendDocument::class, fn (SendDocument $job): bool => $job->messageId === $other->id && $job->afterCommit === true);
    }

    public function test_resolve_resend_failed_requeues(): void
    {
        $message = $this->message(OutboundState::Failed, 5);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true, '--no-interaction' => true])
            ->assertExitCode(Command::SUCCESS);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(5, $row->attempts);
        $this->assertNull($row->failure_category);
        $this->assertNull($row->failure_message);
        $this->assertNull($row->finished_at);
        Queue::assertPushed(SendDocument::class, fn (SendDocument $job): bool => $job->messageId === $message->id);
    }

    public function test_resend_clears_the_previous_send(): void
    {
        $message = $this->message(OutboundState::Failed, 2, 'doc-1');
        $message->forceFill([
            'peppol_message_id' => 'pm-1',
            'envelope_id' => 'env-1',
            'provider_code' => 'E1',
            'status_checks' => 4,
            'sent_at' => now(),
        ])->save();

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true])->assertExitCode(Command::SUCCESS);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(2, $row->attempts);
        $this->assertNull($row->provider_document_id);
        $this->assertNull($row->peppol_message_id);
        $this->assertNull($row->envelope_id);
        $this->assertNull($row->provider_code);
        $this->assertNull($row->sent_at);
        $this->assertSame(0, $row->status_checks);
        // A notification for the earlier document no longer finds this row.
        $this->assertFalse(OutboundMessage::query()->where('provider_document_id', 'doc-1')->exists());
    }

    /**
     * Makes the row look as if another worker claimed it again right after the command read it.
     */
    private function claimedAgainAfterRead(OutboundMessage $message): void
    {
        $done = false;
        OutboundMessage::retrieved(function (OutboundMessage $row) use ($message, &$done): void {
            if (! $done && $row->id === $message->id) {
                $done = true;
                OutboundMessage::query()->whereKey($row->id)->increment('attempts');
            }
        });
    }

    public function test_resend_of_a_row_changed_meanwhile_does_nothing(): void
    {
        $message = $this->message(OutboundState::Failed, 2);
        $this->claimedAgainAfterRead($message);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true])
            ->expectsOutputToContain('changed while it was being resolved')
            ->assertExitCode(Command::FAILURE);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Failed, $row->state);
        $this->assertSame(3, $row->attempts);
        Queue::assertNothingPushed();
    }

    public function test_sent_on_a_row_changed_meanwhile_does_nothing(): void
    {
        $message = $this->message(OutboundState::Unconfirmed, 2);
        $this->claimedAgainAfterRead($message);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--sent' => 'doc-9'])
            ->expectsOutputToContain('changed while it was being resolved')
            ->assertExitCode(Command::FAILURE);

        $row = $this->stored($message);
        $this->assertSame(OutboundState::Unconfirmed, $row->state);
        $this->assertNull($row->provider_document_id);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_resend_with_an_invalid_job_setting_changes_nothing(): void
    {
        $message = $this->message(OutboundState::Failed, 2);
        $before = $this->stored($message)->getAttributes();
        config()->set('einvoicing.http.timeout', 'soon');

        try {
            Artisan::call('einvoicing:resolve', ['id' => $message->id, '--resend' => true, '--no-interaction' => true]);
            $this->fail('A resend with a non-numeric job setting was accepted.');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('einvoicing.http.timeout', $exception->getMessage());
        }

        // Checked before the row is queued again, so no queued row is left without a job.
        $this->assertSame($before, $this->stored($message)->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_sent_with_an_invalid_job_setting_changes_nothing(): void
    {
        $message = $this->message(OutboundState::Unconfirmed, 2);
        $before = $this->stored($message)->getAttributes();
        config()->set('einvoicing.http.timeout', 'soon');

        try {
            Artisan::call('einvoicing:resolve', ['id' => $message->id, '--sent' => 'doc-9', '--no-interaction' => true]);
            $this->fail('A --sent with a non-numeric job setting was accepted.');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString('einvoicing.http.timeout', $exception->getMessage());
        }

        // Checked before the row becomes pending, so no pending row is left without a status poll.
        $this->assertSame($before, $this->stored($message)->getAttributes());
        Queue::assertNothingPushed();
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_resolve_resend_queued_dispatches_again(): void
    {
        $message = $this->message(OutboundState::Queued, 0);

        $this->artisan('einvoicing:resolve', ['id' => $message->id, '--resend' => true, '--no-interaction' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(OutboundState::Queued, $this->stored($message)->state);
        $this->assertSame(0, $this->stored($message)->attempts);
        Queue::assertPushed(SendDocument::class, 1);
    }

    public function test_resolve_rejects_wrong_state_and_both_options(): void
    {
        $unconfirmed = $this->message(OutboundState::Unconfirmed);

        $this->artisan('einvoicing:resolve', ['id' => $unconfirmed->id, '--sent' => 'doc-9', '--resend' => true])
            ->assertExitCode(Command::INVALID);
        $this->artisan('einvoicing:resolve', ['id' => $unconfirmed->id])
            ->assertExitCode(Command::INVALID);
        $this->assertSame(OutboundState::Unconfirmed, $this->stored($unconfirmed)->state);

        $pending = $this->message(OutboundState::Pending, 1, 'doc-1');
        $this->artisan('einvoicing:resolve', ['id' => $pending->id, '--resend' => true, '--force' => true])
            ->expectsOutputToContain('pending')
            ->assertExitCode(Command::FAILURE);

        $delivered = $this->message(OutboundState::Delivered, 1, 'doc-2');
        $this->artisan('einvoicing:resolve', ['id' => $delivered->id, '--sent' => 'doc-3'])
            ->expectsOutputToContain('delivered')
            ->assertExitCode(Command::FAILURE);

        $failed = $this->message(OutboundState::Failed);
        $this->artisan('einvoicing:resolve', ['id' => $failed->id, '--sent' => 'doc-4'])
            ->expectsOutputToContain('failed')
            ->assertExitCode(Command::FAILURE);

        $sending = $this->message(OutboundState::Sending);
        $this->artisan('einvoicing:resolve', ['id' => $sending->id, '--resend' => true, '--force' => true])
            ->expectsOutputToContain('sending')
            ->assertExitCode(Command::FAILURE);

        $this->artisan('einvoicing:resolve', ['id' => 999_999, '--resend' => true])
            ->assertExitCode(Command::FAILURE);
        $this->artisan('einvoicing:resolve', ['id' => 'abc', '--resend' => true])
            ->assertExitCode(Command::INVALID);

        $this->assertSame(OutboundState::Pending, $this->stored($pending)->state);
        $this->assertSame('doc-2', $this->stored($delivered)->provider_document_id);
        $this->assertSame(OutboundState::Failed, $this->stored($failed)->state);
        $this->assertSame(OutboundState::Sending, $this->stored($sending)->state);
        Queue::assertNothingPushed();
        Event::assertNotDispatched(DocumentSent::class);
    }

    public function test_resolve_accepts_a_stale_sending_claim(): void
    {
        $stale = $this->message(OutboundState::Sending, 2);
        $other = $this->message(OutboundState::Sending, 2);
        $this->travel(self::CLAIM_LIFETIME + 1)->seconds();

        $this->artisan('einvoicing:resolve', ['id' => $stale->id, '--sent' => 'doc-9'])
            ->assertExitCode(Command::SUCCESS);
        $this->assertSame(OutboundState::Pending, $this->stored($stale)->state);
        $this->assertSame(2, $this->stored($stale)->attempts);

        $this->artisan('einvoicing:resolve', ['id' => $other->id, '--resend' => true, '--no-interaction' => true])
            ->assertExitCode(Command::INVALID);
        $this->artisan('einvoicing:resolve', ['id' => $other->id, '--resend' => true, '--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $row = $this->stored($other);
        $this->assertSame(OutboundState::Queued, $row->state);
        $this->assertSame(2, $row->attempts);
        Queue::assertPushed(SendDocument::class, 1);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('einvoicing:resolve', $this->app->make(Kernel::class)->all());
    }
}
