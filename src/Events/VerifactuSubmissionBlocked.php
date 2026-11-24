<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Submissions under a throttle key stopped because AEAT refused a submission as a whole, or the
 * issuer or its configuration cannot be used. AEAT registered nothing; the records wait until the
 * cause is fixed and an operator clears the block.
 */
final class VerifactuSubmissionBlocked
{
    use Dispatchable;

    /**
     * @param  string  $code  the AEAT code, or a code of this package such as "unknown_issuer"
     */
    public function __construct(
        public string $throttleKey,
        public string $code,
        public string $message,
    ) {}
}
