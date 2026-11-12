<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Jobs;

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

    public function handle(): void
    {
        $message = OutboundMessage::query()->find($this->messageId);
        if ($message === null) {
            return;
        }

        if ($message->transition(
            OutboundState::Sending,
            OutboundState::Unconfirmed,
            ['failure_message' => self::MESSAGE],
            ['attempts' => $this->attempt],
        )) {
            event(new DocumentUnconfirmed($message));
        }
    }
}
