<?php

declare(strict_types=1);

namespace ProSms\Http;

use Closure;
use ProSms\Exception\ApiError;
use ProSms\Exception\ConnectionError;
use ProSms\Model\RateLimit;
use ProSms\ProSms;
use ProSms\RequestSignature;

/**
 * Exécute les appels à l'API : URL, en-têtes, idempotence, signature, réessais et erreurs.
 *
 * @internal
 */
final class Requester
{
    /** Statuts réessayés (en plus des erreurs réseau). */
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private const MAX_RETRY_AFTER = 60.0;

    private const BACKOFF_BASE = 0.5;

    private const BACKOFF_MAX = 8.0;

    public ?RateLimit $lastRateLimit = null;

    private readonly string $basePath;

    /** @var array<string, int> Dernier horodatage de signature par requête (empreinte → secondes). */
    private array $signedAt = [];

    /**
     * @param Closure(float): void $sleep Attente entre deux tentatives, en secondes.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ?string $signingSecret,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly Transport $transport,
        private readonly Closure $sleep,
    ) {
        $this->basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
    }

    /**
     * @param array<string, scalar|null> $query Paramètres de query (les null sont ignorés).
     * @param array<string, mixed>|null  $body  Corps JSON (les null de premier niveau sont ignorés).
     *
     * @return array{0: array<string, mixed>, 1: Response} Corps décodé et réponse brute.
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): array
    {
        $method = strtoupper($method);

        // URL construite une seule fois : on signe exactement ce qui part.
        $query = array_filter($query, static fn ($value): bool => $value !== null);
        $queryString = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $this->baseUrl . $path . $queryString;
        $signedPath = $this->basePath . $path . $queryString;

        // Corps sérialisé une seule fois, signé et envoyé octet pour octet.
        $payload = null;
        if ($body !== null) {
            $payload = json_encode(
                array_filter($body, static fn ($value): bool => $value !== null),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => sprintf('prosms-php/%s (php/%s)', ProSms::VERSION, PHP_VERSION),
        ];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($method === 'POST') {
            // Une clé par appel, réutilisée par tous les réessais : jamais de double envoi.
            $headers['Idempotency-Key'] = $idempotencyKey ?? self::uuid4();
        }

        for ($attempt = 0; ; $attempt++) {
            $attemptHeaders = $headers;
            if ($this->signingSecret !== null) {
                $timestamp = $this->signatureTimestamp($method . "\n" . $signedPath . "\n" . hash('sha256', $payload ?? ''));
                $attemptHeaders['X-Prosms-Timestamp'] = (string) $timestamp;
                $attemptHeaders['X-Prosms-Signature'] = RequestSignature::compute(
                    $this->signingSecret,
                    $timestamp,
                    $method,
                    $signedPath,
                    $payload ?? '',
                );
            }

            try {
                $response = $this->transport->send(new Request($method, $url, $attemptHeaders, $payload, $this->timeout));
            } catch (ConnectionError $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleep)($this->backoff($attempt));

                continue;
            }

            $this->lastRateLimit = RateLimit::fromResponse($response);

            if ($response->status >= 200 && $response->status < 300) {
                return [$this->decode($response), $response];
            }

            if ($attempt < $this->maxRetries && in_array($response->status, self::RETRY_STATUSES, true)) {
                ($this->sleep)($this->retryDelay($response, $attempt));

                continue;
            }

            throw ApiError::fromResponse($response);
        }
    }

    /**
     * Horodatage de signature : l'heure réelle, sauf si une requête identique (méthode, chemin avec query,
     * corps) a déjà été signée à cette seconde ou après ; on prend alors la seconde suivante libre. Le serveur
     * n'accepte qu'une fois une signature de POST : sans ce décalage, un réessai rapide ou deux envois
     * identiques successifs seraient refusés (`invalid_signature`). Les requêtes différentes ne dérivent pas.
     */
    private function signatureTimestamp(string $fingerprint): int
    {
        $now = time();
        // Seules les entrées de la seconde courante ou à venir peuvent encore entrer en collision.
        $this->signedAt = array_filter($this->signedAt, static fn (int $t): bool => $t >= $now);

        $timestamp = max($now, ($this->signedAt[$fingerprint] ?? $now - 1) + 1);
        $this->signedAt[$fingerprint] = $timestamp;

        return $timestamp;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if (trim($response->body) === '') {
            return [];
        }

        $json = json_decode($response->body, true);
        if (! is_array($json)) {
            throw new ApiError(
                sprintf('Réponse illisible de l\'API ProSMS (HTTP %d, JSON attendu).', $response->status),
                $response->status,
                'invalid_response',
                [],
                $response->headers,
                $response->body,
            );
        }

        return $json;
    }

    private function retryDelay(Response $response, int $attempt): float
    {
        if ($response->status === 429) {
            $retryAfter = trim((string) $response->header('retry-after'));
            if ($retryAfter !== '' && is_numeric($retryAfter)) {
                return min(max(0.0, (float) $retryAfter), self::MAX_RETRY_AFTER);
            }
        }

        return $this->backoff($attempt);
    }

    /** Backoff exponentiel (0,5 s, 1 s, 2 s…) avec gigue de ±20 %, plafonné à 8 s. */
    private function backoff(int $attempt): float
    {
        $delay = min(self::BACKOFF_BASE * (2 ** $attempt), self::BACKOFF_MAX);
        $jitter = 0.8 + (mt_rand() / mt_getrandmax()) * 0.4;

        return min($delay * $jitter, self::BACKOFF_MAX);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
