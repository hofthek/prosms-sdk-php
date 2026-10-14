<?php

declare(strict_types=1);

namespace ProSms\Http;

use ProSms\Exception\ConnectionError;
use ProSms\Exception\TimeoutError;
use Throwable;

/**
 * Adaptateur pour un client PSR-18 fourni par l'application (Guzzle, Symfony HttpClient…).
 *
 * Le SDK ne dépend pas de psr/http-client : les objets sont typés `object` et doivent offrir les méthodes
 * PSR-18 (`sendRequest`) et PSR-17 (`createRequest`, `createStream`). Le client ne doit pas suivre les
 * redirections ; son délai d'attente se règle sur le client lui-même.
 *
 * ```php
 * $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
 * $transport = new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 30]), $factory);
 * ```
 */
final class Psr18Transport implements Transport
{
    private readonly object $streamFactory;

    /**
     * @param object      $client         Psr\Http\Client\ClientInterface
     * @param object      $requestFactory Psr\Http\Message\RequestFactoryInterface
     * @param object|null $streamFactory  Psr\Http\Message\StreamFactoryInterface (par défaut : $requestFactory)
     */
    public function __construct(
        private readonly object $client,
        private readonly object $requestFactory,
        ?object $streamFactory = null,
    ) {
        $this->streamFactory = $streamFactory ?? $requestFactory;
    }

    public function send(Request $request): Response
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (Throwable $e) {
            // PSR-18 : une ClientExceptionInterface est un échec de transport, jamais un statut HTTP.
            if (! $e instanceof \Psr\Http\Client\ClientExceptionInterface) {
                throw $e;
            }
            if (stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false) {
                throw new TimeoutError('Délai dépassé : ' . $e->getMessage(), 0, $e);
            }

            throw new ConnectionError('Erreur réseau : ' . $e->getMessage(), 0, $e);
        }

        /** @var array<string, list<string>> $headers */
        $headers = $psrResponse->getHeaders();

        return new Response((int) $psrResponse->getStatusCode(), $headers, (string) $psrResponse->getBody());
    }
}
