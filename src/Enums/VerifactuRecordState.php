<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Enums;

/**
 * Where a VERI*FACTU billing record is on its way to AEAT, directly or through B2Brouter.
 */
enum VerifactuRecordState: string
{
    /** Generated and chained; queued for submission to AEAT. */
    case Generated = 'generated';
    /** Claimed by a submission; the request may be in flight. */
    case Sending = 'sending';
    /** The outcome of the submission is not known; the same bytes are resent. */
    case Unconfirmed = 'unconfirmed';
    /** Registered by AEAT. */
    case Accepted = 'accepted';
    /** Registered by AEAT with errors to correct. */
    case AcceptedWithErrors = 'accepted_with_errors';
    /** Refused by AEAT; a new record is needed. */
    case Rejected = 'rejected';
    /** Imported into B2Brouter; its registration is not known yet. */
    case Pending = 'pending';
    /** Registered by AEAT through B2Brouter. */
    case Registered = 'registered';
    /** Not registered through B2Brouter, or its record failed the cross-check. */
    case Failed = 'failed';

    /** Whether the record has reached an outcome that no further submission changes. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Accepted, self::AcceptedWithErrors, self::Rejected, self::Registered, self::Failed => true,
            self::Generated, self::Sending, self::Unconfirmed, self::Pending => false,
        };
    }
}
