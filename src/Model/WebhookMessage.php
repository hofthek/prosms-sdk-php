<?php

declare(strict_types=1);

namespace ProSms\Model;

use DateTimeImmutable;
use ProSms\Enum\MessageStatus;

/**
 * Contenu typé d'un événement `message.status`.
 */
final class WebhookMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        /** `sent`, `delivered` ou `failed` (chaîne brute). */
        public readonly string $status,
        public readonly ?string $reference,
        /** Code d'erreur de l'opérateur (ou `failed`) quand `status` = `failed`, sinon null. */
        public readonly ?string $error,
        public readonly ?DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Data::string($data, 'id'),
            to: Data::string($data, 'to'),
            status: Data::string($data, 'status'),
            reference: Data::nullableString($data, 'reference'),
            error: Data::nullableString($data, 'error'),
            occurredAt: Data::date($data, 'occurred_at'),
        );
    }

    /** Statut typé, ou null si inconnu du SDK. */
    public function statusEnum(): ?MessageStatus
    {
        return MessageStatus::tryFrom($this->status);
    }
}
