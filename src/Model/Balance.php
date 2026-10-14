<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Soldes disponibles du compte, en unités SMS.
 */
final class Balance
{
    public function __construct(
        public readonly int $national,
        public readonly int $international,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::int($data, 'national'), Data::int($data, 'international'));
    }
}
