<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use IntrAgile\EInvoicing\Laravel\Models\VerifactuRecord;

/**
 * Whether AEAT holds a VERI*FACTU record is not known, and the record is no longer sent again
 * automatically: its attempts are used up, or AEAT answered a first send as a duplicate of a
 * record it already holds. An operator settles it with a lookup or a retry. For a record submitted
 * through B2Brouter, its tax report was not final after the configured polls, and it stays pending.
 */
final class VerifactuRecordUnconfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public VerifactuRecord $record) {}
}
