<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * A document reached the receiver.
 */
final class DocumentDelivered
{
    use Dispatchable, SerializesModels;

    public function __construct(public OutboundMessage $message) {}
}
