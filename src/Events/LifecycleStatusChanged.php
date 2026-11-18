<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IntrAgile\EInvoicing\France\Lifecycle\LifecycleStatus;

/**
 * A signed webhook notification reported the French lifecycle status (CDV code) of an invoice.
 * It fires after the notification is applied, once per delivery, so a repeated or late delivery
 * fires it again: compare with the status already stored before acting on it.
 *
 * $received is true for an invoice the account received (the status is then the buyer's own
 * response, for example Refused) and false for one it issued (the status reported by the buyer
 * or the platform). $documentId is the provider's invoice id.
 */
final class LifecycleStatusChanged
{
    use Dispatchable;

    public function __construct(
        public string $transport,
        public string $documentId,
        public LifecycleStatus $status,
        public bool $received,
    ) {}
}
