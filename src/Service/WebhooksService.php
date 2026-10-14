<?php

declare(strict_types=1);

namespace ProSms\Service;

use ProSms\Exception\InvalidArgumentError;
use ProSms\Exception\WebhookSignatureError;
use ProSms\Model\WebhookEvent;
use ProSms\Webhook;

/**
 * Vérification des webhooks (`$client->webhooks`). Équivalent de {@see Webhook::verify()}.
 */
final class WebhooksService
{
    /** @internal */
    public function __construct(private readonly ?string $signingSecret)
    {
    }

    /**
     * Vérifie un webhook reçu et renvoie l'événement décodé.
     *
     * @param string                   $payload         Corps brut de la requête.
     * @param string|null              $signatureHeader En-tête `X-Prosms-Signature`.
     * @param string|list<string>|null $secret          Secret de l'application ; par défaut le `signingSecret`
     *                                                  du client (c'est le même secret `whsec_…`).
     * @param string|null              $deliveryId      En-tête `X-Prosms-Delivery`, recopié dans l'événement.
     *
     * @throws WebhookSignatureError
     */
    public function verify(
        string $payload,
        ?string $signatureHeader,
        string|array|null $secret = null,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?string $deliveryId = null,
    ): WebhookEvent {
        $secret ??= $this->signingSecret;
        if ($secret === null) {
            throw new InvalidArgumentError('Secret de webhook manquant : passez-le à verify() ou configurez signingSecret.');
        }

        return Webhook::verify($payload, $signatureHeader, $secret, $tolerance, $deliveryId);
    }
}
