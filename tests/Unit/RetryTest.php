<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ProSms\Exception\ApiError;
use ProSms\Exception\ConflictError;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\RateLimitError;
use ProSms\Exception\ServerError;
use ProSms\Exception\TimeoutError;
use ProSms\Tests\Support\TestCase;

final class RetryTest extends TestCase
{
    public function testRetriesKeepTheSameIdempotencyKey(): void
    {
        $client = $this->client();
        $this->transport
            ->push(503, ['message' => 'Indisponible', 'code' => 'server_error'])
            ->pushError(new ConnectionError('connexion réinitialisée'))
            ->push(202, self::submitted());

        $result = $client->sms->send(to: '0700000000', text: 'Bonjour');

        self::assertCount(1, $result->messages);
        self::assertCount(3, $this->transport->requests);
        $keys = array_unique(array_map(fn ($r) => $r->header('Idempotency-Key'), $this->transport->requests));
        self::assertCount(1, $keys, 'Tous les essais portent la même Idempotency-Key.');
        $bodies = array_unique(array_map(fn ($r) => $r->body, $this->transport->requests));
        self::assertCount(1, $bodies, 'Le corps est identique octet pour octet.');
    }

    public function testCallerIdempotencyKeyIsReusedOnRetries(): void
    {
        $client = $this->client();
        $this->transport->push(500, ['message' => 'x', 'code' => 'server_error'])->push(202, self::submitted());

        $client->sms->sendBatch(messages: [['to' => '07', 'text' => 'x']], idempotencyKey: 'ma-cle');

        self::assertSame(['ma-cle', 'ma-cle'], array_map(fn ($r) => $r->header('Idempotency-Key'), $this->transport->requests));
    }

    #[DataProvider('retryableStatuses')]
    public function testRetriesRetryableStatuses(int $status): void
    {
        $client = $this->client();
        $this->transport->push($status, ['message' => 'x', 'code' => 'server_error'])->push(200, ['data' => ['national' => 1, 'international' => 0]]);

        self::assertSame(1, $client->balance->get()->national);
        self::assertCount(2, $this->transport->requests);
        self::assertCount(1, $this->sleeps);
    }

    /** @return iterable<string, array{int}> */
    public static function retryableStatuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('nonRetryableStatuses')]
    public function testDoesNotRetryOtherErrors(int $status, string $code): void
    {
        $client = $this->client();
        $this->transport->push($status, ['message' => 'Erreur', 'code' => $code]);

        try {
            $client->sms->send(to: '07', text: 'x');
            self::fail('Une erreur était attendue.');
        } catch (ApiError $e) {
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
        }
        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeps);
    }

    /** @return iterable<string, array{int, string}> */
    public static function nonRetryableStatuses(): iterable
    {
        yield '400' => [400, 'http_error'];
        yield '401' => [401, 'invalid_api_key'];
        yield '402' => [402, 'insufficient_balance'];
        yield '403' => [403, 'insufficient_scope'];
        yield '404' => [404, 'not_found'];
        yield '405' => [405, 'method_not_allowed'];
        yield '409' => [409, 'idempotency_conflict'];
        yield '422' => [422, 'validation_failed'];
        yield '501' => [501, 'http_error'];
    }

    public function testIdempotencyConflictIsNeverRetried(): void
    {
        $client = $this->client(maxRetries: 5);
        $this->transport->push(409, ['message' => 'Clé déjà utilisée.', 'code' => 'idempotency_conflict']);

        $this->expectException(ConflictError::class);
        try {
            $client->sms->send(to: '07', text: 'x', idempotencyKey: 'k');
        } finally {
            self::assertCount(1, $this->transport->requests);
        }
    }

    public function testRetryAfterIsHonouredAndCappedAt60Seconds(): void
    {
        $client = $this->client();
        $this->transport
            ->push(429, ['message' => 'Trop', 'code' => 'rate_limited'], ['Retry-After' => '3'])
            ->push(429, ['message' => 'Trop', 'code' => 'rate_limited'], ['Retry-After' => '120'])
            ->push(200, ['data' => []]);

        $client->senders->list();

        self::assertSame([3.0, 60.0], $this->sleeps);
    }

    public function testRateLimitWithoutRetryAfterUsesBackoff(): void
    {
        $client = $this->client(maxRetries: 1);
        $this->transport->push(429, ['message' => 'Trop', 'code' => 'rate_limited'])->push(200, ['data' => []]);

        $client->senders->list();

        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(0.4, $this->sleeps[0]);
        self::assertLessThanOrEqual(0.6, $this->sleeps[0]);
    }

    public function testExponentialBackoffWithJitterIsCappedAt8Seconds(): void
    {
        $client = $this->client(maxRetries: 6);
        for ($i = 0; $i < 6; $i++) {
            $this->transport->push(503, ['message' => 'x', 'code' => 'server_error']);
        }
        $this->transport->push(200, ['data' => []]);

        $client->senders->list();

        $expected = [0.5, 1.0, 2.0, 4.0, 8.0, 8.0];
        self::assertCount(6, $this->sleeps);
        foreach ($this->sleeps as $i => $delay) {
            self::assertGreaterThanOrEqual($expected[$i] * 0.8, $delay, "Essai {$i}");
            self::assertLessThanOrEqual(min($expected[$i] * 1.2, 8.0), $delay, "Essai {$i}");
        }
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $client = $this->client(maxRetries: 2);
        for ($i = 0; $i < 3; $i++) {
            $this->transport->push(503, ['message' => 'Indisponible', 'code' => 'server_error']);
        }

        try {
            $client->balance->get();
            self::fail('ServerError attendue.');
        } catch (ServerError $e) {
            self::assertSame(503, $e->status);
            self::assertSame('server_error', $e->errorCode);
        }
        self::assertCount(3, $this->transport->requests, '1 essai + 2 réessais.');
    }

    public function testRateLimitErrorAfterRetries(): void
    {
        $client = $this->client(maxRetries: 0);
        $this->transport->push(429, ['message' => 'Trop de requêtes.', 'code' => 'rate_limited'], ['Retry-After' => '1']);

        try {
            $client->balance->get();
            self::fail('RateLimitError attendue.');
        } catch (RateLimitError $e) {
            self::assertSame(1, $e->retryAfter);
            self::assertSame('rate_limited', $e->errorCode);
        }
        self::assertCount(1, $this->transport->requests);
    }

    public function testNetworkErrorsAreRetriedThenRethrown(): void
    {
        $client = $this->client(maxRetries: 2);
        $this->transport
            ->pushError(new TimeoutError('délai'))
            ->pushError(new ConnectionError('DNS'))
            ->pushError(new TimeoutError('délai encore'));

        try {
            $client->sms->send(to: '07', text: 'x');
            self::fail('TimeoutError attendue.');
        } catch (TimeoutError $e) {
            self::assertSame('délai encore', $e->getMessage());
        }
        self::assertCount(3, $this->transport->requests);
        self::assertCount(2, $this->sleeps);
        self::assertCount(1, array_unique(array_map(fn ($r) => $r->header('Idempotency-Key'), $this->transport->requests)));
    }

    public function testNoRetryWhenMaxRetriesIsZero(): void
    {
        $client = $this->client(maxRetries: 0);
        $this->transport->pushError(new ConnectionError('refusée'));

        $this->expectException(ConnectionError::class);
        $client->balance->get();
    }
}
