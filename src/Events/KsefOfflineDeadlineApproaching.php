<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * An offline KSeF invoice still on its way to KSeF (queued, sending, pending or unconfirmed) must
 * reach KSeF by the deadline, which lies within einvoicing.ksef.deadline_warning_hours or has
 * already passed.
 *
 * It also fires for a row that failed for a reason that does not settle the invoice, such as a
 * transport error during a KSeF outage (any failure category except validation, duplicate and
 * document_not_supported): the invoice must still reach KSeF, so resend it with
 * einvoicing:resolve --resend.
 *
 * einvoicing:ksef-deadlines fires it again on every run while the row stays inside the window and
 * is neither delivered nor settled, so a listener that notifies people should remember what it
 * has already sent.
 */
final class KsefOfflineDeadlineApproaching
{
    use Dispatchable, SerializesModels;

    /**
     * @param  DateTimeImmutable  $deadline  the last instant of the deadline day in Europe/Warsaw
     */
    public function __construct(public OutboundMessage $message, public DateTimeImmutable $deadline) {}
}
