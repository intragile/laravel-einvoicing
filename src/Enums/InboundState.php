<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Enums;

/**
 * Where a received document is in its intake.
 */
enum InboundState: string
{
    /** The provider announced the document; its content is not stored yet. */
    case Notified = 'notified';
    /** The content is stored locally; the provider has not been told yet. */
    case Fetched = 'fetched';
    /** The provider has been told the document is safely stored. */
    case Acknowledged = 'acknowledged';
}
