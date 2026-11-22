<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * An offline KSeF invoice that is not delivered yet must reach KSeF by the deadline, which lies
 * within einvoicing.ksef.deadline_warning_hours or has already passed.
 */
final class KsefOfflineDeadlineApproaching
{
    use Dispatchable, SerializesModels;

    /**
     * @param  DateTimeImmutable  $deadline  the last instant of the deadline day in Europe/Warsaw
     */
    public function __construct(public OutboundMessage $message, public DateTimeImmutable $deadline) {}
}
