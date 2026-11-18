<?php

declare(strict_types=1);

namespace VijayBilling;

use RuntimeException;

/**
 * Thrown when required server configuration (environment variables) is
 * missing or malformed. Mapped to a generic 500 response; details are logged
 * server-side only.
 */
final class ConfigurationException extends RuntimeException
{
}
