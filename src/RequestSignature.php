<?php

declare(strict_types=1);

namespace ProSms;

/**
 * Signature des requêtes vers `/v1` (option `signingSecret`), identique au calcul du serveur :
 * `hex(hmac_sha256(secret, timestamp + "\n" + MÉTHODE + "\n" + chemin_avec_query + "\n" + hex(sha256(corps))))`.
 */
final class RequestSignature
{
    /**
     * @param string $pathWithQuery Chemin exact envoyé, `/v1` et query string compris (`/v1/sms?status=sent`).
     * @param string $body          Corps envoyé, chaîne vide pour `GET` et `HEAD`.
     */
    public static function compute(string $secret, int $timestamp, string $method, string $pathWithQuery, string $body): string
    {
        $method = strtoupper($method);
        if ($method === 'GET' || $method === 'HEAD') {
            $body = '';
        }

        return hash_hmac('sha256', $timestamp . "\n" . $method . "\n" . $pathWithQuery . "\n" . hash('sha256', $body), $secret);
    }
}
