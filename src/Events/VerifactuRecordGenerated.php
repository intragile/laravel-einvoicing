<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;

/**
 * A VERI*FACTU record was generated, chained and stored, with its XML and QR URL, and waits to be
 * submitted. Fired once the surrounding database transaction commits; never for a record that was
 * rolled back.
 */
final class VerifactuRecordGenerated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public VerifactuRecord $record) {}
}
