<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;

/**
 * AEAT refused a VERI*FACTU record. The record stays in its chain; a new record is needed (see the
 * record's error_action).
 */
final class VerifactuRecordRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VerifactuRecord $record,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}
}
