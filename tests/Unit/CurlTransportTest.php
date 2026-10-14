<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\TimeoutError;
use ProSms\Http\CurlTransport;
use ProSms\Http\Request;

/**
 * Transport curl contre le serveur PHP intégré, en local (aucun accès externe).
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../Support/echo-server.php'];
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            return;
        }
        self::$server = $process;

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        if (! is_resource(self::$server)) {
            self::markTestSkipped('Serveur PHP intégré indisponible.');
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testPostWithHeadersAndBody(): void
    {
        $transport = new CurlTransport();
        $body = '{"to":"0700000000","text":"Bonjour é"}';

        $response = $transport->send(new Request('POST', $this->url('/v1/sms?x=1'), [
            'Authorization' => 'Bearer psk_test_x',
            'Content-Type' => 'application/json',
            'Idempotency-Key' => 'k-1',
        ], $body, 5.0));

        self::assertSame(200, $response->status);
        self::assertSame('7', $response->header('X-RateLimit-Remaining'));
        $echo = json_decode($response->body, true)['data'];
        self::assertSame('POST', $echo['method']);
        self::assertSame('/v1/sms?x=1', $echo['uri']);
        self::assertSame($body, $echo['body']);
        self::assertSame('Bearer psk_test_x', $echo['headers']['authorization']);
        self::assertSame('k-1', $echo['headers']['idempotency-key']);
        self::assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testHandleIsReusedAndGetHasNoBody(): void
    {
        $transport = new CurlTransport();
        $transport->send(new Request('POST', $this->url('/a'), [], 'x', 5.0));

        $response = $transport->send(new Request('GET', $this->url('/b'), ['Accept' => 'application/json'], null, 5.0));

        $echo = json_decode($response->body, true)['data'];
        self::assertSame('GET', $echo['method']);
        self::assertSame('', $echo['body']);
    }

    public function testErrorStatusesAreReturnedNotThrown(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', $this->url('/error'), [], null, 5.0));

        self::assertSame(503, $response->status);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', $this->url('/redirect'), [], null, 5.0));

        self::assertSame(302, $response->status);
        self::assertSame('/elsewhere', $response->header('location'));
    }

    public function testTimeout(): void
    {
        $this->expectException(TimeoutError::class);

        (new CurlTransport())->send(new Request('GET', $this->url('/slow'), [], null, 0.3));
    }

    public function testConnectionRefused(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(ConnectionError::class);
        (new CurlTransport())->send(new Request('GET', 'http://' . $name . '/v1/balance', [], null, 2.0));
    }
}
