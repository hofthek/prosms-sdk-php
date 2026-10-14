<?php

declare(strict_types=1);

namespace ProSms\Exception;

use Throwable;

/**
 * 429 : débit de l'application dépassé (`rate_limited`). Levée une fois les réessais épuisés.
 */
class RateLimitError extends ApiError
{
    /** Secondes à attendre (en-tête `Retry-After`), ou null s'il est absent. */
    public readonly ?int $retryAfter;

    public function __construct(
        string $message,
        int $status,
        string $errorCode,
        array $errors = [],
        array $headers = [],
        string $body = '',
        ?array $json = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $errorCode, $errors, $headers, $body, $json, $previous);
        $value = $headers['retry-after'] ?? null;
        $this->retryAfter = $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
