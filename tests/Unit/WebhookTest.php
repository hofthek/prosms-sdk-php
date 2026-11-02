<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ProSms\Enum\MessageStatus;
use ProSms\Enum\WebhookEventType;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Exception\WebhookSignatureError;
use ProSms\Tests\Support\TestCase;
use ProSms\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const VECTOR_BODY = '{"event":"webhook.test","data":{"application_id":"app_1","occurred_at":"2025-10-11T09:30:00Z"}}';

    private const VECTOR_HEADER = 't=1760175000,v1=1e1e2425462766e0abcd0e7a77190b1620b65181e2848bac93c912c415fa465b';

    /** Tolérance couvrant l'écart entre le vecteur figé (2025) et l'horloge. */
    private static function vectorTolerance(): int
    {
        return abs(time() - 1760175000) + 60;
    }

    private static function statusBody(): string
    {
        return '{"event":"message.status","data":{"id":"0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03","to":"+2250700000000","status":"delivered","reference":"commande-1234","error":null,"occurred_at":"2026-10-11T09:30:04Z"}}';
    }

    public function testSharedWebhookVector(): void
    {
        self::assertSame(self::VECTOR_HEADER, Webhook::sign(self::VECTOR_BODY, self::SECRET, 1760175000));

        $event = Webhook::verify(self::VECTOR_BODY, self::VECTOR_HEADER, self::SECRET, self::vectorTolerance());

        self::assertSame('webhook.test', $event->event);
        self::assertSame(WebhookEventType::WebhookTest, $event->type());
        self::assertTrue($event->isTest());
        self::assertSame(['application_id' => 'app_1', 'occurred_at' => '2025-10-11T09:30:00Z'], $event->data);
        self::assertNull($event->message);
        self::assertNull($event->deliveryId);
    }

    public function testVectorIsExpiredWithDefaultTolerance(): void
    {
        $this->expectException(WebhookSignatureError::class);
        $this->expectExceptionMessage('expiré');

        Webhook::verify(self::VECTOR_BODY, self::VECTOR_HEADER, self::SECRET);
    }

    public function testValidMessageStatusEventIsTyped(): void
    {
        $body = self::statusBody();

        $event = Webhook::verify($body, Webhook::sign($body, self::SECRET), self::SECRET, deliveryId: 'd-1');

        self::assertSame('message.status', $event->event);
        self::assertSame('d-1', $event->deliveryId);
        self::assertNotNull($event->message);
        self::assertSame('0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03', $event->message->id);
        self::assertSame('+2250700000000', $event->message->to);
        self::assertSame(MessageStatus::Delivered, $event->message->statusEnum());
        self::assertSame('commande-1234', $event->message->reference);
        self::assertNull($event->message->error);
        self::assertSame('2026-10-11T09:30:04+00:00', $event->message->occurredAt?->format(DATE_ATOM));
        self::assertSame('delivered', $event->data['status']);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $body = self::statusBody();
        $header = Webhook::sign($body, self::SECRET);

        $this->expectException(WebhookSignatureError::class);
        $this->expectExceptionMessage('invalide');

        Webhook::verify(str_replace('delivered', 'failed', $body), $header, self::SECRET);
    }

    public function testReencodedBodyIsRejected(): void
    {
        // Décoder puis réencoder change les octets (ici « \/ ») : il faut le corps brut.
        $body = '{"event":"webhook.test","data":{"url":"https:\/\/exemple.ci"}}';
        $header = Webhook::sign($body, self::SECRET);
        $reencoded = json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES);

        $this->expectException(WebhookSignatureError::class);
        Webhook::verify((string) $reencoded, $header, self::SECRET);
    }

    public function testWrongSecretIsRejected(): void
    {
        $body = self::statusBody();

        $this->expectException(WebhookSignatureError::class);
        Webhook::verify($body, Webhook::sign($body, 'whsec_autre'), self::SECRET);
    }

    public function testExpiredTimestampIsRejected(): void
    {
        $body = self::statusBody();
        $header = Webhook::sign($body, self::SECRET, time() - 301);

        try {
            Webhook::verify($body, $header, self::SECRET);
            self::fail('Webhook expiré accepté.');
        } catch (WebhookSignatureError $e) {
            self::assertStringContainsString('expiré', $e->getMessage());
        }

        // Une tolérance plus large l'accepte ; un horodatage dans le futur est aussi borné.
        self::assertSame('message.status', Webhook::verify($body, $header, self::SECRET, tolerance: 600)->event);
        $this->expectException(WebhookSignatureError::class);
        Webhook::verify($body, Webhook::sign($body, self::SECRET, time() + 400), self::SECRET);
    }

    public function testRotatedSecretAcceptsAnyMatchingV1(): void
    {
        $body = self::statusBody();
        $t = time();
        $old = hash_hmac('sha256', $t . '.' . $body, 'whsec_ancien');
        $new = hash_hmac('sha256', $t . '.' . $body, self::SECRET);

        // Plusieurs v1 dans l'en-tête : une seule doit correspondre.
        self::assertSame('message.status', Webhook::verify($body, "t={$t},v1={$old},v1={$new}", self::SECRET)->event);
        self::assertSame('message.status', Webhook::verify($body, "t={$t}, v1={$new}, v1={$old}", 'whsec_ancien')->event);

        // Côté récepteur, une liste de secrets couvre la période de rotation.
        self::assertSame('message.status', Webhook::verify($body, "t={$t},v1={$new}", ['whsec_ancien', self::SECRET])->event);

        $this->expectException(WebhookSignatureError::class);
        Webhook::verify($body, "t={$t},v1={$old}", ['whsec_autre', self::SECRET]);
    }

    #[DataProvider('malformedHeaders')]
    public function testMalformedHeadersAreRejected(?string $header): void
    {
        $this->expectException(WebhookSignatureError::class);

        Webhook::verify(self::statusBody(), $header, self::SECRET);
    }

    /** @return iterable<string, array{string|null}> */
    public static function malformedHeaders(): iterable
    {
        $v1 = str_repeat('a', 64);
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'sans t' => ["v1={$v1}"];
        yield 't non numérique' => ["t=abc,v1={$v1}"];
        yield 'sans v1' => ['t=' . time()];
        yield 'format libre' => ['nimporte quoi'];
        yield 'v0 seulement' => ['t=' . time() . ",v0={$v1}"];
    }

    public function testInvalidJsonAfterValidSignature(): void
    {
        $body = '{pas du json';

        $this->expectException(WebhookSignatureError::class);
        $this->expectExceptionMessage('JSON');
        Webhook::verify($body, Webhook::sign($body, self::SECRET), self::SECRET);
    }

    public function testEmptySecretIsAConfigurationError(): void
    {
        $this->expectException(InvalidArgumentError::class);
        Webhook::verify('{}', Webhook::sign('{}', 'x'), '');
    }

    public function testClientVerifyDefaultsToSigningSecret(): void
    {
        $body = self::statusBody();
        $header = Webhook::sign($body, self::SECRET);

        $event = $this->client(signingSecret: self::SECRET)->webhooks->verify($body, $header, deliveryId: 'd-2');
        self::assertSame('d-2', $event->deliveryId);

        self::assertSame('message.status', $this->client()->webhooks->verify($body, $header, self::SECRET)->event);

        $this->expectException(InvalidArgumentError::class);
        $this->client()->webhooks->verify($body, $header);
    }

    public function testUnknownEventIsKept(): void
    {
        $body = '{"event":"message.created","data":{"id":"x"}}';

        $event = Webhook::verify($body, Webhook::sign($body, self::SECRET), self::SECRET);

        self::assertSame('message.created', $event->event);
        self::assertNull($event->type());
        self::assertNull($event->message);
        self::assertSame(['id' => 'x'], $event->data);
    }
}
