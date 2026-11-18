<?php

declare(strict_types=1);

namespace VijayBilling;

use RuntimeException;

/**
 * A client-facing error with an HTTP status and a stable machine-readable
 * error code. The code is the only text sent to the client.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers Extra response headers (e.g. Allow).
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        private readonly array $headers = [],
    ) {
        parent::__construct($errorCode, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
