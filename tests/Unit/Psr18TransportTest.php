<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\TimeoutError;
use ProSms\Http\Psr18Transport;
use ProSms\Http\Request;
use ProSms\ProSms;
use RuntimeException;

/**
 * L'adaptateur PSR-18 n'exige pas psr/http-client : on le teste avec des objets minimaux.
 */
final class Psr18TransportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Support/psr18-stubs.php';
    }

    private static function factory(): object
    {
        return new class () {
            public function createRequest(string $method, string $uri): object
            {
                return new class ($method, $uri) {
                    /** @var array<string, string> */
                    public array $headers = [];

                    public string $body = '';

                    public function __construct(public string $method, public string $uri)
                    {
                    }

                    public function withHeader(string $name, string $value): static
                    {
                        $clone = clone $this;
                        $clone->headers[$name] = $value;

                        return $clone;
                    }

                    public function withBody(object $stream): static
                    {
                        $clone = clone $this;
                        $clone->body = (string) $stream;

                        return $clone;
                    }
                };
            }

            public function createStream(string $content): object
            {
                return new class ($content) {
                    public function __construct(private string $content)
                    {
                    }

                    public function __toString(): string
                    {
                        return $this->content;
                    }
                };
            }
        };
    }

    private static function response(int $status, array $headers, string $body): object
    {
        return new class ($status, $headers, $body) {
            public function __construct(private int $status, private array $headers, private string $body)
            {
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function getHeaders(): array
            {
                return $this->headers;
            }

            public function getBody(): string
            {
                return $this->body;
            }
        };
    }

    public function testSendsThroughThePsrClient(): void
    {
        $client = new class (self::response(200, ['X-RateLimit-Limit' => ['50'], 'X-RateLimit-Remaining' => ['10']], '{"data":{"national":5,"international":1}}')) {
            public ?object $sent = null;

            public function __construct(private object $response)
            {
            }

            public function sendRequest(object $request): object
            {
                $this->sent = $request;

                return $this->response;
            }
        };

        $prosms = new ProSms(apiKey: 'psk_test_abcdefgh.x', transport: new Psr18Transport($client, self::factory()));
        $balance = $prosms->balance->get();

        self::assertSame(5, $balance->national);
        self::assertSame(10, $prosms->lastRateLimit?->remaining);
        self::assertSame('GET', $client->sent->method);
        self::assertSame('https://api.prosms.ci/v1/balance', $client->sent->uri);
        self::assertSame('Bearer psk_test_abcdefgh.x', $client->sent->headers['Authorization']);
    }

    public function testBodyIsForwarded(): void
    {
        $client = new class (self::response(202, [], '{"data":{"messages":[],"rejected":[],"segments":0,"cost":{"national":0,"international":0},"test":true}}')) {
            public ?object $sent = null;

            public function __construct(private object $response)
            {
            }

            public function sendRequest(object $request): object
            {
                $this->sent = $request;

                return $this->response;
            }
        };

        (new ProSms(apiKey: 'psk_test_abcdefgh.x', transport: new Psr18Transport($client, self::factory())))
            ->sms->send(to: '07', text: 'x');

        self::assertSame('{"to":"07","text":"x"}', $client->sent->body);
        self::assertSame('application/json', $client->sent->headers['Content-Type']);
    }

    public function testClientExceptionsBecomeConnectionErrors(): void
    {
        $transport = new Psr18Transport(new class () {
            public function sendRequest(object $request): object
            {
                throw new class ('cURL error 28: Operation timed out') extends RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
                };
            }
        }, self::factory());

        try {
            $transport->send(new Request('GET', 'https://x.test/v1/balance', [], null, 1.0));
            self::fail('TimeoutError attendue.');
        } catch (TimeoutError $e) {
            self::assertInstanceOf(ConnectionError::class, $e);
        }

        $transport = new Psr18Transport(new class () {
            public function sendRequest(object $request): object
            {
                throw new class ('Could not resolve host') extends RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
                };
            }
        }, self::factory());

        $this->expectException(ConnectionError::class);
        $transport->send(new Request('GET', 'https://x.test/v1/balance', [], null, 1.0));
    }

    public function testOtherExceptionsAreNotHidden(): void
    {
        $transport = new Psr18Transport(new class () {
            public function sendRequest(object $request): object
            {
                throw new \LogicException('bogue');
            }
        }, self::factory());

        $this->expectException(\LogicException::class);
        $transport->send(new Request('GET', 'https://x.test/v1/balance', [], null, 1.0));
    }
}
