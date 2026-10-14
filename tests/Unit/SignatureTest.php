<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use ProSms\Http\Request;
use ProSms\RequestSignature;
use ProSms\Tests\Support\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** Vecteur commun aux trois SDK (docs/SDK-SPEC.md du dépôt prosms-api). */
    public function testSharedRequestVector(): void
    {
        self::assertSame(
            '8f6f05222e4a82f2d659b8ae8beb8e49bdf1d367a1aab36c7583b50431aa9cf6',
            RequestSignature::compute(self::SECRET, 1760175000, 'POST', '/v1/sms', '{"to":"0700000000","text":"Bonjour"}'),
        );
    }

    public function testMethodIsUppercasedAndGetSignsAnEmptyBody(): void
    {
        self::assertSame(
            RequestSignature::compute(self::SECRET, 1760175000, 'POST', '/v1/sms', '{"to":"0700000000","text":"Bonjour"}'),
            RequestSignature::compute(self::SECRET, 1760175000, 'post', '/v1/sms', '{"to":"0700000000","text":"Bonjour"}'),
        );
        self::assertSame(
            hash_hmac('sha256', "1760175000\nGET\n/v1/balance\n" . hash('sha256', ''), self::SECRET),
            RequestSignature::compute(self::SECRET, 1760175000, 'GET', '/v1/balance', 'ignoré'),
        );
    }

    private static function assertSignedLikeTheServer(Request $request, string $expectedPath): void
    {
        $timestamp = $request->header('X-Prosms-Timestamp');
        self::assertNotNull($timestamp);
        self::assertMatchesRegularExpression('/^\d+$/', $timestamp);
        self::assertLessThanOrEqual(5, abs(time() - (int) $timestamp), 'Horodatage frais.');

        // Calcul du serveur (App\Support\Webhooks\WebhookSignature::request).
        $body = in_array($request->method, ['GET', 'HEAD'], true) ? '' : (string) $request->body;
        $expected = hash_hmac('sha256', $timestamp . "\n" . $request->method . "\n" . $expectedPath . "\n" . hash('sha256', $body), self::SECRET);
        self::assertSame($expected, $request->header('X-Prosms-Signature'));
    }

    public function testPostIsSignedOverTheExactBodySent(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        $this->transport->push(202, self::submitted());

        $client->sms->send(to: '0700000000', text: 'Bonjour');

        $request = $this->transport->last();
        self::assertSame('{"to":"0700000000","text":"Bonjour"}', $request->body);
        self::assertSignedLikeTheServer($request, '/v1/sms');
    }

    public function testGetIsSignedWithTheQueryStringAsSent(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        $this->transport->push(200, ['data' => [], 'meta' => ['next_cursor' => null]]);

        $client->sms->list(status: 'delivered', reference: 'é 1/2', perPage: 10);

        $request = $this->transport->last();
        $path = '/v1/sms?status=delivered&reference=%C3%A9%201%2F2&per_page=10';
        self::assertSame('https://api.prosms.test' . $path, $request->url);
        self::assertSignedLikeTheServer($request, $path);
    }

    public function testBaseUrlPathPrefixIsPartOfTheSignedPath(): void
    {
        $client = $this->client(signingSecret: self::SECRET, baseUrl: 'https://proxy.test/prosms');
        $this->transport->push(200, ['data' => ['national' => 0, 'international' => 0]]);

        $client->balance->get();

        self::assertSame('https://proxy.test/prosms/v1/balance', $this->transport->last()->url);
        self::assertSignedLikeTheServer($this->transport->last(), '/prosms/v1/balance');
    }

    public function testEveryAttemptIsSignedAgainWithADistinctSignature(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        $this->transport->push(503, ['message' => 'x', 'code' => 'server_error'])->push(202, self::submitted());

        $client->sms->send(to: '07', text: 'x');

        self::assertCount(2, $this->transport->requests);
        foreach ($this->transport->requests as $request) {
            self::assertSignedLikeTheServer($request, '/v1/sms');
        }
        // Le serveur refuse une signature déjà vue : un réessai dans la même seconde doit en changer.
        self::assertNotSame(
            $this->transport->requests[0]->header('X-Prosms-Signature'),
            $this->transport->requests[1]->header('X-Prosms-Signature'),
        );
    }

    public function testIdenticalRequestsInTheSameSecondGetDistinctSignatures(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        for ($i = 0; $i < 3; $i++) {
            $this->transport->push(200, ['data' => ['national' => 0, 'international' => 0]]);
            $client->balance->get();
        }
        $this->transport->push(200, ['data' => []]);
        $client->senders->list();

        $signatures = array_map(fn (Request $r) => $r->header('X-Prosms-Signature'), $this->transport->requests);
        self::assertCount(4, array_unique($signatures));
        foreach (array_slice($this->transport->requests, 0, 3) as $request) {
            self::assertSignedLikeTheServer($request, '/v1/balance');
        }
        // Une autre requête n'est pas décalée.
        $timestamps = array_map(fn (Request $r) => (int) $r->header('X-Prosms-Timestamp'), $this->transport->requests);
        self::assertSame($timestamps[0] + 1, $timestamps[1]);
        self::assertSame($timestamps[0] + 2, $timestamps[2]);
        self::assertLessThanOrEqual($timestamps[0] + 1, $timestamps[3]);
    }

    public function testTwoIdenticalSendsInTheSameSecondGetDistinctTimestamps(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        $this->transport->push(202, self::submitted())->push(202, self::submitted());

        $client->sms->send(to: '0700000000', text: 'Bonjour');
        $client->sms->send(to: '0700000000', text: 'Bonjour');

        [$first, $second] = $this->transport->requests;
        self::assertSame($first->body, $second->body);
        self::assertGreaterThan((int) $first->header('X-Prosms-Timestamp'), (int) $second->header('X-Prosms-Timestamp'));
        self::assertNotSame($first->header('X-Prosms-Signature'), $second->header('X-Prosms-Signature'));
        self::assertSignedLikeTheServer($first, '/v1/sms');
        self::assertSignedLikeTheServer($second, '/v1/sms');
    }

    public function testDistinctRequestsKeepTheRealTimeWithoutDrift(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        $start = time();
        for ($i = 0; $i < 100; $i++) {
            $this->transport->push(202, self::submitted());
            $client->sms->send(to: '0700000000', text: "Message {$i}");
        }
        $end = time();

        foreach ($this->transport->requests as $request) {
            $timestamp = (int) $request->header('X-Prosms-Timestamp');
            self::assertGreaterThanOrEqual($start, $timestamp);
            self::assertLessThanOrEqual($end, $timestamp, 'Aucune dérive pour des requêtes différentes.');
        }
    }

    public function testManyIdenticalSendsAdvanceOneSecondEach(): void
    {
        $client = $this->client(signingSecret: self::SECRET);
        for ($i = 0; $i < 5; $i++) {
            $this->transport->push(202, self::submitted());
            $client->sms->send(to: '0700000000', text: 'Identique');
        }

        $timestamps = array_map(fn (Request $r) => (int) $r->header('X-Prosms-Timestamp'), $this->transport->requests);
        self::assertSame($timestamps, array_values(array_unique($timestamps)), 'Une seconde libre par envoi identique.');
        self::assertLessThanOrEqual(time() + 5, max($timestamps));
    }
}
