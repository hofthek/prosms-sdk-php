<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Exception\ProSmsError;
use ProSms\Model\RateLimit;
use ProSms\ProSms;
use ProSms\Tests\Support\TestCase;

final class ClientTest extends TestCase
{
    private const ENV = ['PROSMS_API_KEY', 'PROSMS_BASE_URL', 'PROSMS_SIGNING_SECRET'];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV as $name) {
            $this->savedEnv[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => [$env, $server, $getenv]) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
            putenv($getenv === false ? $name : "{$name}={$getenv}");
        }
    }

    public function testRejectsMissingKeyBeforeAnyNetworkCall(): void
    {
        try {
            new ProSms(transport: $this->transport);
            self::fail('Une clé absente doit être refusée.');
        } catch (InvalidArgumentError $e) {
            self::assertStringContainsString('PROSMS_API_KEY', $e->getMessage());
            self::assertInstanceOf(ProSmsError::class, $e);
        }
        self::assertSame([], $this->transport->requests);
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsMalformedKey(string $key): void
    {
        $this->expectException(InvalidArgumentError::class);
        $this->expectExceptionMessage('psk_live_');

        new ProSms(apiKey: $key, transport: $this->transport);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'préfixe inconnu' => ['sk_live_abcdefgh.secret'];
        yield 'jeton back-office' => ['1|abcdef'];
        yield 'mode inconnu' => ['psk_prod_abcdefgh.secret'];
    }

    public function testIsTest(): void
    {
        self::assertTrue((new ProSms(apiKey: 'psk_test_abcdefgh.x', transport: $this->transport))->isTest);
        self::assertFalse((new ProSms(apiKey: 'psk_live_abcdefgh.x', transport: $this->transport))->isTest);
    }

    public function testReadsConfigurationFromEnvironment(): void
    {
        putenv('PROSMS_API_KEY=psk_live_envenvXX.secret');
        putenv('PROSMS_BASE_URL=http://localhost:8090/');
        putenv('PROSMS_SIGNING_SECRET=whsec_env');

        $client = new ProSms(transport: $this->transport);
        $this->transport->push(200, ['data' => ['national' => 1, 'international' => 2]]);
        $client->balance->get();

        self::assertFalse($client->isTest);
        self::assertSame('http://localhost:8090', $client->baseUrl);
        $request = $this->transport->last();
        self::assertSame('http://localhost:8090/v1/balance', $request->url);
        self::assertSame('Bearer psk_live_envenvXX.secret', $request->header('Authorization'));
        self::assertNotNull($request->header('X-Prosms-Signature'));
    }

    public function testExplicitOptionsWinOverEnvironment(): void
    {
        putenv('PROSMS_API_KEY=psk_live_envenvXX.secret');

        $client = new ProSms(apiKey: self::API_KEY, transport: $this->transport);

        self::assertTrue($client->isTest);
        self::assertSame(ProSms::DEFAULT_BASE_URL, $client->baseUrl);
    }

    public function testBaseUrlWithV1SuffixIsNormalized(): void
    {
        $client = $this->client(baseUrl: 'https://api.prosms.test/v1/');
        $this->transport->push(200, ['data' => []]);
        $client->senders->list();

        self::assertSame('https://api.prosms.test/v1/senders', $this->transport->last()->url);
    }

    public function testRejectsInvalidBaseUrlTimeoutAndRetries(): void
    {
        foreach ([
            fn () => new ProSms(apiKey: self::API_KEY, baseUrl: 'api.prosms.ci'),
            fn () => new ProSms(apiKey: self::API_KEY, timeout: 0),
            fn () => new ProSms(apiKey: self::API_KEY, maxRetries: -1),
        ] as $factory) {
            try {
                $factory();
                self::fail('Configuration invalide acceptée.');
            } catch (InvalidArgumentError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSendsStandardHeaders(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => ['national' => 12000, 'international' => 300]]);
        $client->balance->get();

        $request = $this->transport->last();
        self::assertSame('GET', $request->method);
        self::assertSame('Bearer ' . self::API_KEY, $request->header('Authorization'));
        self::assertSame('application/json', $request->header('Accept'));
        self::assertNull($request->header('Content-Type'), 'Pas de Content-Type sans corps.');
        self::assertNull($request->header('Idempotency-Key'), 'Pas d\'Idempotency-Key sur un GET.');
        self::assertNull($request->header('X-Prosms-Signature'), 'Pas de signature sans signingSecret.');
        self::assertNull($request->body);
        self::assertSame(30.0, $request->timeout);
        self::assertMatchesRegularExpression('#^prosms-php/1\.0\.0 \(php/\d+\.\d+\.\d+.*\)$#', (string) $request->header('User-Agent'));
    }

    public function testPostCarriesJsonContentTypeAndIdempotencyKey(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted());
        $client->sms->send(to: '0700000000', text: 'Bonjour');

        $request = $this->transport->last();
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $request->header('Idempotency-Key'),
        );
    }

    public function testGeneratesANewIdempotencyKeyPerCall(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted())->push(202, self::submitted());
        $client->sms->send(to: '0700000000', text: 'A');
        $client->sms->send(to: '0700000000', text: 'A');

        self::assertNotSame(
            $this->transport->requests[0]->header('Idempotency-Key'),
            $this->transport->requests[1]->header('Idempotency-Key'),
        );
    }

    public function testLastRateLimitFollowsEachResponse(): void
    {
        $client = $this->client();
        self::assertNull($client->lastRateLimit);

        $this->transport->push(200, ['data' => []], ['X-RateLimit-Limit' => '50', 'X-RateLimit-Remaining' => '49']);
        $client->senders->list();

        self::assertInstanceOf(RateLimit::class, $client->lastRateLimit);
        self::assertSame(50, $client->lastRateLimit->limit);
        self::assertSame(49, $client->lastRateLimit->remaining);
        self::assertTrue(isset($client->lastRateLimit));

        $this->transport->push(200, ['data' => []]);
        $client->senders->list();
        self::assertNull($client->lastRateLimit);
    }
}
