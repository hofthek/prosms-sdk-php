<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Résultat d'un envoi (`POST /v1/sms` ou `/v1/sms/batch`).
 */
final class SendResult
{
    /**
     * @param list<AcceptedMessage> $messages
     * @param list<Rejected>        $rejected
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $rejected,
        /** Segments acceptés au total. */
        public readonly int $segments,
        public readonly Cost $cost,
        public readonly bool $test,
        /** Vrai si la réponse est rejouée pour une `Idempotency-Key` déjà vue (aucun SMS renvoyé). */
        public readonly bool $idempotentReplayed = false,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data Contenu de `data`.
     */
    public static function fromArray(array $data, bool $idempotentReplayed = false): self
    {
        return new self(
            messages: array_map(AcceptedMessage::fromArray(...), Data::list($data, 'messages')),
            rejected: array_map(Rejected::fromArray(...), Data::list($data, 'rejected')),
            segments: Data::int($data, 'segments'),
            cost: Cost::fromArray(Data::array($data, 'cost')),
            test: Data::bool($data, 'test'),
            idempotentReplayed: $idempotentReplayed,
        );
    }

    /**
     * Identifiants des messages acceptés.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (AcceptedMessage $m): string => $m->id, $this->messages);
    }
}
