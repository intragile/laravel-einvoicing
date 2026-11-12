<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Enums;

/**
 * Where an outbound document is in its delivery.
 */
enum OutboundState: string
{
    /** Stored and waiting for a send job. */
    case Queued = 'queued';
    /** Claimed by a send job; the request may be in flight. */
    case Sending = 'sending';
    /** Accepted by the provider; delivery to the receiver is not confirmed yet. */
    case Pending = 'pending';
    /** Delivered to the receiver. */
    case Delivered = 'delivered';
    /** Definitely not delivered. */
    case Failed = 'failed';
    /** The outcome of the send is not known; only an operator resolves it. */
    case Unconfirmed = 'unconfirmed';

    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }
}
