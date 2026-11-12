<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\InboundMessage;

/**
 * A received document has been stored; it fires once per document, after the database commit.
 *
 * The business document is in `$message->xml`, which is hidden from the model's array and JSON
 * forms; parse it with the core `UblReader`. Whether the access point has been told yet depends
 * on `einvoicing.inbound.acknowledge`: the acknowledgement follows this event.
 */
final class DocumentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public InboundMessage $message) {}
}
