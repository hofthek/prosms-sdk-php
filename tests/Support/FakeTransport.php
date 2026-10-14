<?php

declare(strict_types=1);

namespace ProSms\Tests\Support;

use ProSms\Http\Request;
use ProSms\Http\Response;
use ProSms\Http\Transport;
use RuntimeException;
use Throwable;

/**
 * Faux transport : renvoie des réponses (ou lève des exceptions) préparées, et enregistre les requêtes.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|Throwable> */
    private array $queue = [];

    /**
     * @param array<string, mixed>|list<mixed>|null $json
     * @param array<string, string>                 $headers
     */
    public function push(int $status, array|null $json = null, array $headers = [], ?string $raw = null): self
    {
        $this->queue[] = new Response($status, $headers + ['Content-Type' => 'application/json'], $raw ?? ($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR)));

        return $this;
    }

    public function pushError(Throwable $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('FakeTransport : aucune réponse préparée pour ' . $request->method . ' ' . $request->url);
        }
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): Request
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /** @return array<string, mixed> */
    public function lastJson(): array
    {
        return json_decode((string) $this->last()->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
