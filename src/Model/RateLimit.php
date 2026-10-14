<?php

declare(strict_types=1);

namespace ProSms\Model;

use ProSms\Http\Response;

/**
 * Débit de l'application (`X-RateLimit-Limit`, `X-RateLimit-Remaining`) relevé sur la dernière réponse.
 */
final class RateLimit
{
    public function __construct(
        /** Requêtes par seconde autorisées. */
        public readonly int $limit,
        /** Requêtes restantes dans la seconde en cours. */
        public readonly int $remaining,
    ) {
    }

    /**
     * @internal
     */
    public static function fromResponse(Response $response): ?self
    {
        $limit = $response->header('x-ratelimit-limit');
        $remaining = $response->header('x-ratelimit-remaining');
        if ($limit === null || $remaining === null || ! is_numeric($limit) || ! is_numeric($remaining)) {
            return null;
        }

        return new self((int) $limit, (int) $remaining);
    }
}
