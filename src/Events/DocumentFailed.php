<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * A document was definitely not delivered.
 */
final class DocumentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public OutboundMessage $message) {}
}
