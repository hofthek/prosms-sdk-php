<?php

declare(strict_types=1);

namespace ProSms\Http;

/**
 * Réponse HTTP brute renvoyée par un transport.
 */
final class Response
{
    /** @var array<string, string> En-têtes, noms en minuscules. */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        $this->headers = $normalized;
    }

    /** Valeur d'un en-tête (insensible à la casse), ou null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
