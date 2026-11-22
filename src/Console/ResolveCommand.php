<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentSent;
use IntrAgile\EInvoicing\Laravel\Jobs\PollDeliveryStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\PollKsefStatus;
use IntrAgile\EInvoicing\Laravel\Jobs\SendDocument;
use IntrAgile\EInvoicing\Laravel\Jobs\SendKsefInvoice;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * Settles an outbound document whose send outcome is not known, or queues a document again.
 *
 * An unconfirmed document, or one whose sending claim has outlived its worker, is resolved
 * either as sent, with the id the access point gave it, or by sending it again, which risks a
 * duplicate invoice and therefore needs confirmation. A failed or still queued document can be
 * queued again without asking. The attempt count is never lowered: it identifies the sending
 * claim, and the next send claims the following attempt.
 *
 * A KSeF row is sent again by SendKsefInvoice and polled by PollKsefStatus. Queued again from an
 * unknown outcome, it is marked as possibly accepted (provider_code "outcome_unknown"), so that
 * KSeF's duplicate answer to the resend delivers it with the original KSeF number.
 */
final class ResolveCommand extends Command
{
    protected $signature = 'einvoicing:resolve
        {id : The id of the outbound document}
        {--sent= : The access point\'s document id, when the document was accepted}
        {--resend : Queue the document again}
        {--force : Skip the duplicate-risk confirmation}';

    protected $description = 'Resolve an unconfirmed outbound document as sent, or queue a document again';

    public function handle(): int
    {
        $sent = $this->option('sent');
        $resend = (bool) $this->option('resend');

        if (($sent !== null) === $resend) {
            $this->components->error('Give exactly one of --sent=ID or --resend.');

            return self::INVALID;
        }

        $id = $this->argument('id');
        $id = is_string($id) || is_int($id) ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            $this->components->error('The id must be a positive integer.');

            return self::INVALID;
        }

        $message = OutboundMessage::query()->find($id);
        if ($message === null) {
            $this->components->error(sprintf('No outbound document with id %d.', $id));

            return self::FAILURE;
        }

        if ($sent !== null) {
            return $this->markSent($message, is_string($sent) ? trim($sent) : '');
        }

        return $this->resend($message);
    }

    private function markSent(OutboundMessage $message, string $documentId): int
    {
        if ($documentId === '') {
            $this->components->error('--sent needs the document id the access point returned.');

            return self::INVALID;
        }
        if (self::isKsef($message) && ! PollKsefStatus::isKsefDocumentId($documentId)) {
            $this->components->error('A KSeF document id is "<session reference>/<invoice reference>", as the send returned it.');

            return self::INVALID;
        }

        $from = $this->unresolvedState($message);
        if ($from === null) {
            return $this->wrongState($message, 'only an unconfirmed document can be marked as sent');
        }

        $taken = OutboundMessage::query()
            ->where('transport', $message->transport)
            ->where('provider_document_id', $documentId)
            ->whereKeyNot($message->getKey())
            ->exists();
        if ($taken) {
            return $this->idInUse($message, $documentId);
        }

        // Before the row becomes pending, so an invalid setting leaves no pending row without a poll.
        PollDeliveryStatus::assertSettings();

        try {
            $moved = $message->transition($from, OutboundState::Pending, [
                'provider_document_id' => $documentId,
                'failure_message' => null,
                'sent_at' => $message->sent_at ?? now(),
            ], ['attempts' => $message->attempts]);
        } catch (UniqueConstraintViolationException) {
            return $this->idInUse($message->refresh(), $documentId);
        }

        if (! $moved) {
            return $this->changedMeanwhile($message);
        }

        event(new DocumentSent($message));
        if (self::isKsef($message)) {
            PollKsefStatus::start($message->id, $message->attempts);
        } else {
            PollDeliveryStatus::start($message->id, $message->attempts);
        }

        $this->components->info(sprintf('Outbound document %d is now pending as %s.', $message->id, $documentId));

        return self::SUCCESS;
    }

    private function resend(OutboundMessage $message): int
    {
        $from = $this->unresolvedState($message);
        if ($from !== null) {
            if (! $this->option('force')) {
                if (! $this->input->isInteractive()) {
                    $this->components->error('The access point may already have accepted this document. Pass --force to queue it again without confirmation.');

                    return self::INVALID;
                }

                if (! $this->confirm('The access point may already have accepted this document; sending it again may deliver a duplicate invoice. Queue it again?')) {
                    $this->components->warn('Nothing was changed.');

                    return self::FAILURE;
                }
            }
        } elseif ($message->state === OutboundState::Failed || $message->state === OutboundState::Queued) {
            $from = $message->state;
        } else {
            return $this->wrongState($message, 'only an unconfirmed, failed or queued document can be queued again');
        }

        // Before the row is queued again, so an invalid setting leaves no queued row without a job.
        SendDocument::assertSettings();

        // An earlier KSeF send that may have been accepted is remembered for its duplicate answer.
        $outcomeUnknown = self::isKsef($message)
            && ($from === OutboundState::Unconfirmed || $from === OutboundState::Sending || $message->provider_code === SendKsefInvoice::OUTCOME_UNKNOWN);

        // The earlier send is cleared, so a late notification for it no longer finds this row.
        if (! $message->transition($from, OutboundState::Queued, [
            'provider_document_id' => null,
            'peppol_message_id' => null,
            'envelope_id' => null,
            'sent_at' => null,
            'status_checks' => 0,
            'failure_category' => null,
            'failure_message' => null,
            'provider_code' => $outcomeUnknown ? SendKsefInvoice::OUTCOME_UNKNOWN : null,
            'finished_at' => null,
        ], ['attempts' => $message->attempts])) {
            return $this->changedMeanwhile($message);
        }

        if (self::isKsef($message)) {
            SendKsefInvoice::schedule($message->id);
        } else {
            SendDocument::schedule($message->id);
        }

        $this->components->info(sprintf('Outbound document %d is queued to be sent again.', $message->id));

        return self::SUCCESS;
    }

    /**
     * The state to resolve from when the send outcome is not known: unconfirmed, or sending with
     * a claim older than any worker could still hold, which happens on connections that cannot
     * schedule the claim's expiry.
     */
    private function unresolvedState(OutboundMessage $message): ?OutboundState
    {
        if ($message->state === OutboundState::Unconfirmed) {
            return OutboundState::Unconfirmed;
        }

        if ($message->state === OutboundState::Sending
            && $message->updated_at->lessThan(now()->subSeconds(self::isKsef($message) ? SendKsefInvoice::claimLifetime() : SendDocument::claimLifetime()))) {
            return OutboundState::Sending;
        }

        return null;
    }

    private static function isKsef(OutboundMessage $message): bool
    {
        return $message->transport === EInvoicingManager::KSEF;
    }

    private function wrongState(OutboundMessage $message, string $rule): int
    {
        $this->components->error(sprintf(
            'Outbound document %d is %s%s; %s.',
            $message->id,
            $message->state->value,
            $message->state === OutboundState::Sending ? ' and its send may still be in progress' : '',
            $rule,
        ));

        return self::FAILURE;
    }

    private function idInUse(OutboundMessage $message, string $documentId): int
    {
        $this->components->error(sprintf(
            'The document id %s is already recorded for another outbound document of transport "%s".',
            $documentId,
            $message->transport,
        ));

        return self::FAILURE;
    }

    private function changedMeanwhile(OutboundMessage $message): int
    {
        $this->components->error(sprintf(
            'Outbound document %d changed while it was being resolved and is now %s; nothing was changed.',
            $message->id,
            $message->state->value,
        ));

        return self::FAILURE;
    }
}
