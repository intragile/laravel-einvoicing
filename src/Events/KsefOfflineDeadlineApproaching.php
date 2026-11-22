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
 * einvoicing:ksef-deadlines fires it again on every run while the row stays inside the window and
 * is neither delivered nor failed, so a listener that notifies people should remember what it has
 * already sent.
 */
final class KsefOfflineDeadlineApproaching
{
    use Dispatchable, SerializesModels;

    /**
     * @param  DateTimeImmutable  $deadline  the last instant of the deadline day in Europe/Warsaw
     */
    public function __construct(public OutboundMessage $message, public DateTimeImmutable $deadline) {}
}
