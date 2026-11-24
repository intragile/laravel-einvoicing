<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;
use IntrAgile\EInvoicing\Transport\B2Brouter\Spain\CrossCheckResult;

/**
 * The VERI*FACTU record B2Brouter generated does not match what our own engine makes of the
 * invoice, or it cannot be attached to its chain. In the strict cross-check mode the record is
 * failed; in the report mode it is registered all the same. The record's cross_check holds the
 * summary and every difference.
 */
final class VerifactuCrossCheckFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VerifactuRecord $record,
        public CrossCheckResult $result,
    ) {}
}
