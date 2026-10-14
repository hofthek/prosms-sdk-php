<?php

declare(strict_types=1);

namespace ProSms\Http;

use CurlHandle;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\TimeoutError;

/**
 * Transport par défaut, fondé sur ext-curl. Réutilise la même poignée (connexions keep-alive).
 */
final class CurlTransport implements Transport
{
    private ?CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $curlOptions Options curl supplémentaires (proxy, CA…), appliquées en dernier.
     */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public function send(Request $request): Response
    {
        $handle = $this->handle();

        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Pas de « Expect: 100-continue » : le corps part d'un seul bloc.
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($request->timeout, 10.0) * 1000)),
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    // Nouvelle réponse (ex. après un 100 Continue) : on repart de zéro.
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $key = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$key] = isset($responseHeaders[$key]) ? $responseHeaders[$key] . ', ' . $value : $value;
                }

                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        curl_setopt_array($handle, $this->curlOptions + $options);

        $body = curl_exec($handle);
        if ($body === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutError(sprintf('Délai dépassé (%s s) pour %s %s.', $request->timeout, $request->method, $request->url));
            }

            throw new ConnectionError(sprintf('Erreur réseau vers %s : %s (curl %d).', $request->url, $error, $errno));
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, (string) $body);
    }

    private function handle(): CurlHandle
    {
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new ConnectionError('Impossible d\'initialiser curl.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }

        return $this->handle;
    }
}
