<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\OutboundMessage;

/**
 * The outcome of a send is not known: the document may or may not have been accepted. It is
 * never sent again automatically; an operator checks the access point and resolves it.
 */
final class DocumentUnconfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public OutboundMessage $message) {}
}
