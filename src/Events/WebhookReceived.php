<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IntrAgile\EInvoicing\Transport\Webhook\WebhookEvent;

/**
 * A signed webhook notification for this provider account has been verified; it fires before the
 * notification is applied, once per delivery, so a repeated delivery fires it again. Drop
 * duplicates by $event->deduplicationKey.
 */
final class WebhookReceived
{
    use Dispatchable;

    public function __construct(public string $transport, public WebhookEvent $event) {}
}
