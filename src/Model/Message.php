<?php

declare(strict_types=1);

namespace ProSms\Model;

use DateTimeImmutable;
use ProSms\Enum\MessageStatus;

/**
 * Message envoyé (`GET /v1/sms/{id}`, `GET /v1/sms`). Dates en UTC, null si absentes.
 */
final class Message
{
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $from,
        public readonly string $text,
        /** Chaîne brute de l'API (voir {@see MessageStatus}). */
        public readonly string $status,
        public readonly int $segments,
        public readonly ?string $carrier,
        public readonly ?string $reference,
        /** Identifiant attribué par l'opérateur à l'envoi (null tant que le message n'est pas transmis). */
        public readonly ?string $operatorReference,
        public readonly ?string $error,
        public readonly bool $test,
        public readonly ?DateTimeImmutable $scheduledAt,
        public readonly ?DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly ?DateTimeImmutable $deliveredAt,
        public readonly ?DateTimeImmutable $failedAt,
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
            from: Data::string($data, 'from'),
            text: Data::string($data, 'text'),
            status: Data::string($data, 'status'),
            segments: Data::int($data, 'segments'),
            carrier: Data::nullableString($data, 'carrier'),
            reference: Data::nullableString($data, 'reference'),
            operatorReference: Data::nullableString($data, 'operator_reference'),
            error: Data::nullableString($data, 'error'),
            test: Data::bool($data, 'test'),
            scheduledAt: Data::date($data, 'scheduled_at'),
            createdAt: Data::date($data, 'created_at'),
            sentAt: Data::date($data, 'sent_at'),
            deliveredAt: Data::date($data, 'delivered_at'),
            failedAt: Data::date($data, 'failed_at'),
        );
    }

    /** Statut typé, ou null si l'API renvoie une valeur inconnue du SDK. */
    public function statusEnum(): ?MessageStatus
    {
        return MessageStatus::tryFrom($this->status);
    }

    /** Vrai si le statut est définitif (`delivered`, `failed`, `cancelled`). */
    public function isFinal(): bool
    {
        return $this->statusEnum()?->isFinal() ?? false;
    }
}
