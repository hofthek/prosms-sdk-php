<?php

declare(strict_types=1);

namespace ProSms\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ProSms\ProSms;

abstract class TestCase extends BaseTestCase
{
    public const API_KEY = 'psk_test_abcdEFGH.0123456789abcdefghijABCDEFGHIJ0123456789';

    protected FakeTransport $transport;

    /** @var list<float> Attentes demandées par les réessais. */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeps = [];
    }

    protected function client(?string $signingSecret = null, int $maxRetries = 2, string $apiKey = self::API_KEY, string $baseUrl = 'https://api.prosms.test'): ProSms
    {
        return new ProSms(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            signingSecret: $signingSecret,
            maxRetries: $maxRetries,
            transport: $this->transport,
            sleep: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /** @return array<string, mixed> */
    protected static function submitted(int $count = 1, bool $test = true): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = ['id' => '0192f1b2-0000-7000-8000-00000000000' . $i, 'to' => '+225070000000' . $i, 'status' => 'queued', 'segments' => 1, 'carrier' => 'orange', 'reference' => null];
        }

        return ['data' => [
            'messages' => $messages,
            'rejected' => [],
            'segments' => $count,
            'cost' => ['national' => $test ? 0 : $count, 'international' => 0],
            'test' => $test,
        ]];
    }

    /** @return array<string, mixed> */
    protected static function message(string $id = '0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03', string $status = 'delivered'): array
    {
        return [
            'id' => $id,
            'to' => '+2250700000000',
            'from' => 'BOUTIQUE',
            'text' => 'Bonjour',
            'status' => $status,
            'segments' => 1,
            'carrier' => 'orange',
            'reference' => 'commande-1234',
            'operator_reference' => 'test-5e8f2a6c1d03',
            'error' => null,
            'test' => true,
            'scheduled_at' => null,
            'created_at' => '2026-10-11T09:30:00Z',
            'sent_at' => '2026-10-11T09:30:01Z',
            'delivered_at' => $status === 'delivered' ? '2026-10-11T09:30:04Z' : null,
            'failed_at' => null,
        ];
    }
}
