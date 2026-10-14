<?php

declare(strict_types=1);

namespace ProSms\Http;

/**
 * Requête HTTP prête à partir : URL complète, en-têtes et corps déjà sérialisé (et signé).
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }

    /** Valeur d'un en-tête (insensible à la casse), ou null. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
