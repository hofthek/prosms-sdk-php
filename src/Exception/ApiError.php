<?php

declare(strict_types=1);

namespace ProSms\Exception;

use ProSms\Http\Response;
use Throwable;

/**
 * Réponse d'erreur de l'API (`{ "message": "…", "code": "…", "errors"?: {…} }`).
 *
 * `errorCode` porte le `code` de l'API tel quel (`insufficient_balance`, `invalid_signature`…) :
 * branchez dessus plutôt que sur la classe si besoin. `getCode()` renvoie la même chaîne.
 */
class ApiError extends ProSmsError
{
    /**
     * @param array<string, list<string>> $errors  Erreurs par champ (`validation_failed`).
     * @param array<string, string>       $headers En-têtes de la réponse, noms en minuscules.
     * @param array<string, mixed>|null   $json    Corps décodé, ou null s'il n'est pas du JSON.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $errors = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?array $json = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        // Comme PDOException : le code est une chaîne.
        $this->code = $errorCode;
    }

    /**
     * Construit l'erreur adaptée au statut HTTP.
     *
     * @internal
     */
    public static function fromResponse(Response $response): self
    {
        $json = json_decode($response->body, true);
        $json = is_array($json) ? $json : null;

        $code = is_string($json['code'] ?? null) ? $json['code'] : 'http_error';
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : sprintf('Erreur HTTP %d renvoyée par l\'API ProSMS.', $response->status);

        $errors = [];
        if (is_array($json['errors'] ?? null)) {
            foreach ($json['errors'] as $field => $messages) {
                $errors[(string) $field] = array_values(array_map('strval', (array) $messages));
            }
        }

        $class = match (true) {
            $response->status === 401 => AuthenticationError::class,
            $response->status === 402 => InsufficientBalanceError::class,
            $response->status === 403 => PermissionDeniedError::class,
            $response->status === 404 => NotFoundError::class,
            $response->status === 409 => ConflictError::class,
            $response->status === 422 => ValidationError::class,
            $response->status === 429 => RateLimitError::class,
            $response->status >= 500 => ServerError::class,
            default => self::class,
        };

        return new $class($message, $response->status, $code, $errors, $response->headers, $response->body, $json);
    }
}
