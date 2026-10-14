<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use Generator;
use ProSms\Model\Message;
use ProSms\Tests\Support\TestCase;

final class PaginationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function page(array $ids, ?string $next): array
    {
        return [
            'data' => array_map(fn (string $id): array => self::message($id), $ids),
            'meta' => ['per_page' => 2, 'next_cursor' => $next, 'prev_cursor' => null],
        ];
    }

    public function testListAllWalksEveryPageLazily(): void
    {
        $client = $this->client();
        $this->transport
            ->push(200, self::page(['m1', 'm2'], 'c2'))
            ->push(200, self::page(['m3', 'm4'], 'c3'))
            ->push(200, self::page(['m5'], null));

        $iterator = $client->sms->listAll(status: 'delivered', perPage: 2);
        self::assertInstanceOf(Generator::class, $iterator);
        self::assertSame([], $this->transport->requests, 'Aucun appel avant la première itération.');

        $ids = [];
        foreach ($iterator as $message) {
            self::assertInstanceOf(Message::class, $message);
            $ids[] = $message->id;
        }

        self::assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], $ids);
        self::assertSame([
            'https://api.prosms.test/v1/sms?status=delivered&per_page=2',
            'https://api.prosms.test/v1/sms?status=delivered&per_page=2&cursor=c2',
            'https://api.prosms.test/v1/sms?status=delivered&per_page=2&cursor=c3',
        ], array_map(fn ($r) => $r->url, $this->transport->requests));
    }

    public function testStoppingEarlyDoesNotFetchFurtherPages(): void
    {
        $client = $this->client();
        $this->transport
            ->push(200, self::page(['m1', 'm2'], 'c2'))
            ->push(200, self::page(['m3', 'm4'], 'c3'));

        $ids = [];
        foreach ($client->sms->listAll(perPage: 2) as $message) {
            $ids[] = $message->id;
            if (count($ids) === 3) {
                break;
            }
        }

        self::assertSame(['m1', 'm2', 'm3'], $ids);
        self::assertCount(2, $this->transport->requests);
    }

    public function testKeysAreSequentialAcrossPages(): void
    {
        $client = $this->client();
        $this->transport->push(200, self::page(['m1', 'm2'], 'c2'))->push(200, self::page(['m3'], null));

        self::assertSame([0, 1, 2], array_keys(iterator_to_array($client->sms->listAll())));
    }

    public function testEmptyListAndRepeatedCursorStop(): void
    {
        $client = $this->client();
        $this->transport->push(200, self::page([], null));
        self::assertSame([], iterator_to_array($client->sms->listAll()));

        // Un curseur qui ne change pas ne provoque pas de boucle infinie.
        $this->transport->push(200, self::page(['m1'], 'same'))->push(200, self::page(['m2'], 'same'));
        $ids = array_map(fn (Message $m) => $m->id, iterator_to_array($client->sms->listAll()));
        self::assertSame(['m1', 'm2'], $ids);
    }
}
