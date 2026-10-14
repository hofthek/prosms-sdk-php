<?php

declare(strict_types=1);

namespace ProSms\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Une page de `GET /v1/sms`. Itérable : `foreach ($page as $message)`.
 *
 * @implements IteratorAggregate<int, Message>
 */
final class MessagePage implements IteratorAggregate, Countable
{
    /**
     * @param list<Message> $data
     */
    public function __construct(
        public readonly array $data,
        /** Curseur de la page suivante, null en fin de liste. */
        public readonly ?string $nextCursor,
        public readonly ?string $prevCursor = null,
        public readonly ?int $perPage = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $body Corps complet `{ data, meta }`.
     */
    public static function fromArray(array $body): self
    {
        $meta = Data::array($body, 'meta');
        $nextCursor = Data::nullableString($meta, 'next_cursor');
        $prevCursor = Data::nullableString($meta, 'prev_cursor');

        return new self(
            data: array_map(Message::fromArray(...), Data::list($body, 'data')),
            nextCursor: $nextCursor === '' ? null : $nextCursor,
            prevCursor: $prevCursor === '' ? null : $prevCursor,
            perPage: isset($meta['per_page']) ? Data::int($meta, 'per_page') : null,
        );
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    /** @return Traversable<int, Message> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
