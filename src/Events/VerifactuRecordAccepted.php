<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;

/**
 * AEAT registered a VERI*FACTU record. With errors, the record is registered but AEAT reported an
 * error (errorCode) that is remedied with a correction.
 */
final class VerifactuRecordAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public VerifactuRecord $record,
        public bool $withErrors,
        public ?string $errorCode,
    ) {}
}
