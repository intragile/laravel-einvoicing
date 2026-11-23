<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Enums;

/**
 * The kind of a VERI*FACTU billing record.
 */
enum VerifactuRecordKind: string
{
    /** A RegistroAlta. */
    case Registration = 'alta';
    /** A RegistroAnulacion. */
    case Cancellation = 'anulacion';
}
