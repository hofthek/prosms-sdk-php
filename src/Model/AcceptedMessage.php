<?php

declare(strict_types=1);

namespace ProSms\Model;

/**
 * Message accepté par `POST /v1/sms` ou `/v1/sms/batch` (statut `queued`).
 */
final class AcceptedMessage
{
    public function __construct(
        public readonly string $id,
        /** Numéro normalisé (E.164). */
        public readonly string $to,
        public readonly string $status,
        public readonly int $segments,
        public readonly ?string $carrier,
        public readonly ?string $reference,
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
            segments: Data::int($data, 'segments'),
            carrier: Data::nullableString($data, 'carrier'),
            reference: Data::nullableString($data, 'reference'),
        );
    }
}
