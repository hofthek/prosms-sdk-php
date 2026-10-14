<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Numéro refusé, non facturé. `reason` : `invalid_number`, `no_route`, `empty_text`, `text_too_long`
 * (chaîne brute, d'autres valeurs peuvent apparaître).
 */
final class Rejected
{
    public function __construct(
        public readonly string $to,
        public readonly string $reason,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::string($data, 'to'), Data::string($data, 'reason'));
    }
}
