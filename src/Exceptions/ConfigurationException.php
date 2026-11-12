<?php

declare(strict_types=1);

namespace IntrAgile\EInvoicing\Laravel\Exceptions;

use RuntimeException;

/**
 * Thrown when the einvoicing configuration is missing or invalid. The message names the
 * configuration key, never its value, so it is safe to log.
 */
final class ConfigurationException extends RuntimeException {}
