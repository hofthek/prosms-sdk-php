<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Sender utilisable par l'application. `isDefault` : celui utilisé quand `from` est omis.
 */
final class Sender
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isDefault,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::string($data, 'name'), Data::bool($data, 'is_default'));
    }
}
