<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Enums;

use IntrAgile\EInvoicing\Transport\Aeat\DuplicateState;
use IntrAgile\EInvoicing\Transport\Aeat\RecordOutcome;
use IntrAgile\EInvoicing\Transport\DeliveryState;

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

    /**
     * The state a record reaches through an AEAT outcome, from the outcome's delivery state:
     * delivered is accepted (accepted with errors when AEAT registered it with errors), failed is
     * rejected and unknown is unconfirmed. An invoice that is already cancelled therefore accepts a
     * cancellation and rejects a registration.
     *
     * AEAT matches a duplicate by the invoice id, not by the Huella, so AlreadyRegistered proves
     * that it holds this very record only when the answer is to a resend of bytes sent before. On
     * a first send it leaves the record unconfirmed, to be settled with a lookup. On a resend, a
     * duplicate AEAT holds as AceptadaConErrores is accepted with errors.
     *
     * @param  bool  $cancellation  whether the record is a RegistroAnulacion
     * @param  bool  $resend  whether the same bytes had been sent before this submission
     * @param  DuplicateState|null  $duplicate  EstadoRegistroDuplicado of the answer line, if any
     */
    public static function fromOutcome(RecordOutcome $outcome, bool $cancellation, bool $resend, ?DuplicateState $duplicate = null): self
    {
        if ($outcome === RecordOutcome::AlreadyRegistered) {
            if (! $resend) {
                return self::Unconfirmed;
            }
            if ($duplicate === DuplicateState::AceptadaConErrores) {
                return self::AcceptedWithErrors;
            }
        }

        return match ($outcome->deliveryState($cancellation)) {
            DeliveryState::Delivered => $outcome === RecordOutcome::AcceptedWithErrors ? self::AcceptedWithErrors : self::Accepted,
            DeliveryState::Failed => self::Rejected,
            DeliveryState::Unknown, DeliveryState::Pending => self::Unconfirmed,
        };
    }
}
