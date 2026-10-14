<?php

declare(strict_types=1);

namespace ProSms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ProSms\Enum\MessageStatus;
use ProSms\Exception\AuthenticationError;
use ProSms\Exception\NotFoundError;
use ProSms\Exception\ValidationError;
use ProSms\Model\Message;
use ProSms\Model\Sender;
use ProSms\ProSms;

/**
 * Tests contre une API réelle, avec une clé de test (aucun SMS envoyé, aucun débit).
 *
 * Variables : PROSMS_TEST_BASE_URL, PROSMS_TEST_API_KEY (psk_test_…), PROSMS_TEST_SIGNING_SECRET et
 * PROSMS_TEST_SENDER facultatives. Sans elles, les tests sont sautés.
 */
final class ApiIntegrationTest extends TestCase
{
    private const PHONE = '0700000000';

    private ProSms $client;

    private ?string $sender;

    protected function setUp(): void
    {
        $baseUrl = self::env('PROSMS_TEST_BASE_URL');
        $apiKey = self::env('PROSMS_TEST_API_KEY');
        if ($baseUrl === null || $apiKey === null) {
            self::markTestSkipped('PROSMS_TEST_BASE_URL et PROSMS_TEST_API_KEY non définies.');
        }
        if (! str_starts_with($apiKey, 'psk_test_')) {
            self::fail('Les tests d\'intégration exigent une clé de test (psk_test_…).');
        }

        $this->client = new ProSms(apiKey: $apiKey, baseUrl: $baseUrl, signingSecret: self::env('PROSMS_TEST_SIGNING_SECRET'), timeout: 15);
        $this->sender = self::env('PROSMS_TEST_SENDER');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function reference(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }

    /** Attend un statut définitif ; tolère le 404 des premières secondes (écriture asynchrone). */
    private function waitForFinal(string $id, int $timeoutSeconds = 45): Message
    {
        $deadline = time() + $timeoutSeconds;
        $last = null;
        while (time() < $deadline) {
            try {
                $last = $this->client->sms->get($id);
                if ($last->isFinal()) {
                    return $last;
                }
            } catch (NotFoundError) {
                // Pas encore écrit.
            }
            usleep(1_000_000);
        }

        self::fail(sprintf('Message %s toujours « %s » après %d s.', $id, $last?->status ?? 'introuvable', $timeoutSeconds));
    }

    public function testSendThenGetUntilDelivered(): void
    {
        $reference = self::reference('sdk-php');
        $before = $this->client->balance->get();
        $result = $this->client->sms->send(to: self::PHONE, text: 'Test SDK PHP', from: $this->sender, reference: $reference);

        self::assertTrue($result->test);
        self::assertFalse($result->idempotentReplayed);
        self::assertCount(1, $result->messages);
        self::assertSame([], $result->rejected);
        self::assertSame('queued', $result->messages[0]->status);
        self::assertSame($reference, $result->messages[0]->reference);
        self::assertStringStartsWith('+225', $result->messages[0]->to);
        self::assertNotNull($this->client->lastRateLimit);

        $message = $this->waitForFinal($result->messages[0]->id);
        self::assertSame(MessageStatus::Delivered, $message->statusEnum());
        self::assertTrue($message->test);
        self::assertSame($reference, $message->reference);
        self::assertSame('Test SDK PHP', $message->text);
        self::assertNotNull($message->createdAt);
        self::assertNotNull($message->deliveredAt);
        if ($this->sender !== null) {
            self::assertSame($this->sender, $message->from);
        }

        $after = $this->client->balance->get();
        self::assertSame([$before->national, $before->international], [$after->national, $after->international], 'Une clé de test ne débite rien.');

        $page = $this->client->sms->list(reference: $reference);
        self::assertCount(1, $page);
        self::assertSame($message->id, $page->data[0]->id);
    }

    public function testListAllOnAFewItems(): void
    {
        // Trois envois à des secondes différentes : l'API encode le curseur à la seconde près alors que
        // created_at est stocké à la microseconde, et perd les messages de la même seconde que la limite
        // de page (anomalie côté API, pas côté SDK).
        $reference = self::reference('sdk-php-list');
        $expected = [];
        foreach ([self::PHONE, '0500000000', '0100000000'] as $i => $phone) {
            if ($i > 0) {
                usleep(1_100_000);
            }
            $expected = [...$expected, ...$this->client->sms->send(to: $phone, text: 'Pagination', from: $this->sender, reference: $reference)->ids()];
        }
        self::assertCount(3, $expected);

        // Écriture asynchrone : on attend que les trois messages soient listés.
        $deadline = time() + 30;
        do {
            $visible = count($this->client->sms->list(reference: $reference, perPage: 10));
            if ($visible < 3) {
                usleep(1_000_000);
            }
        } while ($visible < 3 && time() < $deadline);
        self::assertSame(3, $visible);

        // perPage 2 : trois messages répartis sur deux pages.
        $ids = [];
        foreach ($this->client->sms->listAll(reference: $reference, perPage: 2) as $message) {
            self::assertInstanceOf(Message::class, $message);
            $ids[] = $message->id;
        }
        self::assertSame(array_reverse($expected), $ids, 'Du plus récent au plus ancien, sur deux pages.');

        // Arrêt anticipé sur la liste complète de l'application.
        $count = 0;
        foreach ($this->client->sms->listAll(perPage: 2) as $message) {
            if (++$count === 2) {
                break;
            }
        }
        self::assertSame(2, $count);
    }

    public function testBalanceAndSenders(): void
    {
        $balance = $this->client->balance->get();
        self::assertGreaterThanOrEqual(0, $balance->national);
        self::assertGreaterThanOrEqual(0, $balance->international);

        $senders = $this->client->senders->list();
        self::assertNotEmpty($senders);
        self::assertContainsOnlyInstancesOf(Sender::class, $senders);
        if ($this->sender !== null) {
            self::assertContains($this->sender, array_map(fn (Sender $s) => $s->name, $senders));
        }
    }

    public function testOtp(): void
    {
        $result = $this->client->sms->sendOtp(self::PHONE, 'Votre code est 4821.', from: $this->sender, reference: self::reference('sdk-php-otp'));
        self::assertCount(1, $result->messages);

        try {
            $this->client->sms->sendOtp(array_fill(0, 11, self::PHONE), 'Code 1234', from: $this->sender);
            self::fail('Plus de 10 destinataires OTP acceptés.');
        } catch (ValidationError $e) {
            self::assertSame('otp_too_many_recipients', $e->errorCode);
        }
    }

    public function testBatch(): void
    {
        $result = $this->client->sms->sendBatch(
            messages: [
                ['to' => self::PHONE, 'text' => 'Bonjour Awa', 'reference' => self::reference('a')],
                ['to' => '0500000000', 'text' => 'Bonjour Koffi', 'reference' => self::reference('k')],
                ['to' => '12', 'text' => 'Numéro invalide'],
            ],
            from: $this->sender,
        );

        self::assertCount(2, $result->messages);
        self::assertCount(1, $result->rejected);
        self::assertSame('invalid_number', $result->rejected[0]->reason);
        self::assertSame(2, $result->segments);
    }

    public function testIdempotentReplay(): void
    {
        $key = 'sdk-php-' . bin2hex(random_bytes(8));
        $params = ['to' => self::PHONE, 'text' => 'Rejeu idempotent', 'from' => $this->sender, 'idempotencyKey' => $key];

        $first = $this->client->sms->send(...$params);
        $second = $this->client->sms->send(...$params);

        self::assertFalse($first->idempotentReplayed);
        self::assertTrue($second->idempotentReplayed);
        self::assertSame($first->ids(), $second->ids());
    }

    public function testErrors(): void
    {
        try {
            $this->client->sms->send(to: '12', text: 'x', from: $this->sender);
            self::fail('no_valid_recipient attendu.');
        } catch (ValidationError $e) {
            self::assertSame('no_valid_recipient', $e->errorCode);
            self::assertSame('invalid_number', $e->rejected()[0]->reason);
        }

        try {
            $this->client->sms->send(to: self::PHONE, text: 'x', from: 'INCONNU' . random_int(100, 999));
            self::fail('invalid_sender attendu.');
        } catch (ValidationError $e) {
            self::assertSame('invalid_sender', $e->errorCode);
        }

        try {
            $this->client->sms->get('00000000-0000-7000-8000-000000000000');
            self::fail('404 attendu.');
        } catch (NotFoundError $e) {
            self::assertSame('not_found', $e->errorCode);
        }
    }

    public function testIdenticalConsecutiveReadsPassWithSignature(): void
    {
        // Lectures identiques signées à la même seconde : acceptées (l'usage unique ne vise que les POST).
        $id = $this->client->sms->send(to: self::PHONE, text: 'Lectures identiques', from: $this->sender)->ids()[0];
        $this->waitForFinal($id);

        $first = $this->client->sms->get($id);
        $second = $this->client->sms->get($id);
        $third = $this->client->sms->get($id);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->id, $third->id);
        self::assertSame($this->client->balance->get()->national, $this->client->balance->get()->national);
    }

    public function testIdenticalConsecutiveSendsPassWithSignature(): void
    {
        // Même corps, Idempotency-Key différentes (hors signature) : deux envois distincts, signatures distinctes.
        $params = ['to' => self::PHONE, 'text' => 'Envoi identique ' . bin2hex(random_bytes(4)), 'from' => $this->sender];

        $first = $this->client->sms->send(...$params);
        $second = $this->client->sms->send(...$params);

        self::assertFalse($second->idempotentReplayed);
        self::assertNotSame($first->ids(), $second->ids());
    }

    public function testSignatureIsRequiredAndChecked(): void
    {
        if (self::env('PROSMS_TEST_SIGNING_SECRET') === null) {
            self::markTestSkipped('PROSMS_TEST_SIGNING_SECRET non définie.');
        }

        $baseUrl = (string) self::env('PROSMS_TEST_BASE_URL');
        $apiKey = (string) self::env('PROSMS_TEST_API_KEY');

        foreach ([null, 'whsec_mauvais_secret'] as $secret) {
            $client = new ProSms(apiKey: $apiKey, baseUrl: $baseUrl, signingSecret: $secret, maxRetries: 0);
            try {
                $client->balance->get();
                self::fail('Requête acceptée sans signature valide.');
            } catch (AuthenticationError $e) {
                self::assertSame('invalid_signature', $e->errorCode);
            }
        }
    }
}
