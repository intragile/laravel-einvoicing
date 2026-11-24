<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;

/**
 * AEAT registered a VERI*FACTU record that B2Brouter generated and submitted, and the record is
 * attached to its B2Brouter chain. The state is registered, or accepted_with_errors when AEAT
 * registered it with errors to correct. In the report cross-check mode the record may still have
 * failed the cross-check (see VerifactuCrossCheckFailed and the record's cross_check).
 */
final class VerifactuRecordRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public VerifactuRecord $record) {}
}
