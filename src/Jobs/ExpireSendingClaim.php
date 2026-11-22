<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

use IntrAgile\EInvoicing\Laravel\EInvoicingManager;
use IntrAgile\EInvoicing\Laravel\Enums\OutboundState;
use IntrAgile\EInvoicing\Laravel\Events\DocumentUnconfirmed;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * Ends a sending claim whose worker never recorded an outcome, e.g. because it was killed while
 * the request was in flight.
 *
 * The request may have reached the access point, so the document becomes unconfirmed rather than
 * queued again. Only the claim the job was scheduled for is ended: a row that has since been
 * claimed again, or has left the sending state, is left alone.
 */
final class ExpireSendingClaim extends MessageJob
{
    public const MESSAGE = 'The worker stopped while sending; check the access point before resending.';

    /** The message on a KSeF row, which KSeF answers with a duplicate when it is sent again. */
    public const KSEF_MESSAGE = 'The worker stopped while sending to KSeF; the invoice may have been accepted. Check it in KSeF, or queue it again with einvoicing:resolve --resend: a copy KSeF already accepted is answered as a duplicate and compared with the original.';

    public function handle(): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        if ($message->transition(
            OutboundState::Sending,
            OutboundState::Unconfirmed,
            $message->transport === EInvoicingManager::KSEF
                ? ['failure_message' => self::KSEF_MESSAGE, 'provider_code' => SendKsefInvoice::OUTCOME_UNKNOWN]
                : ['failure_message' => self::MESSAGE],
            ['attempts' => $this->attempt],
        )) {
            event(new DocumentUnconfirmed($message));
        }
    }
}
