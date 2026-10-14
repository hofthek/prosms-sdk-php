<?php

declare(strict_types=1);

namespace ProSms\Model;

use ProSms\Enum\WebhookEventType;

/**
 * Webhook vérifié. `message` est renseigné quand `event` = `message.status`.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data Objet `data` décodé, tel quel.
     */
    public function __construct(
        /** `message.status` ou `webhook.test` (chaîne brute). */
        public readonly string $event,
        public readonly array $data,
        /** Identifiant de livraison (`X-Prosms-Delivery`) s'il a été passé à `verify`. */
        public readonly ?string $deliveryId = null,
        public readonly ?WebhookMessage $message = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload Corps décodé.
     */
    public static function fromArray(array $payload, ?string $deliveryId = null): self
    {
        $event = Data::string($payload, 'event');
        $data = Data::array($payload, 'data');

        return new self(
            event: $event,
            data: $data,
            deliveryId: $deliveryId,
            message: $event === WebhookEventType::MessageStatus->value ? WebhookMessage::fromArray($data) : null,
        );
    }

    /** Type typé, ou null si l'événement est inconnu du SDK. */
    public function type(): ?WebhookEventType
    {
        return WebhookEventType::tryFrom($this->event);
    }

    public function isTest(): bool
    {
        return $this->event === WebhookEventType::WebhookTest->value;
    }
}
