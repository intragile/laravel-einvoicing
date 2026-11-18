<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A signed webhook notification reported that a deposited e-reporting file (a B2Brouter ledger)
 * changed state. The notification is not trusted for the state itself: read it with
 * EInvoicing::france($event->transport)->eReportStatus($event->ledgerId). It fires once per
 * delivery, so a repeated delivery fires it again.
 */
final class EReportStateChanged
{
    use Dispatchable;

    public function __construct(public string $transport, public string $ledgerId) {}
}
