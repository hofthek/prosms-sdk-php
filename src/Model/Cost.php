<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Unités SMS de l'envoi, par solde. Avec une clé de test, rien n'est débité.
 */
final class Cost
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
