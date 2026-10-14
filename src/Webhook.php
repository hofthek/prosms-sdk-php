<?php

declare(strict_types=1);

namespace ProSms;

use JsonException;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Exception\WebhookSignatureError;
use ProSms\Model\WebhookEvent;

/**
 * Vérification des webhooks ProSMS, sans client ni clé API.
 *
 * En-tête reçu : `X-Prosms-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<corps brut>")>`.
 */
final class Webhook
{
    /** Tolérance par défaut sur l'horodatage, en secondes. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Vérifie la signature d'un webhook puis décode son corps.
     *
     * Passez le **corps brut** reçu (`file_get_contents('php://input')`, `$request->getContent()`),
     * jamais un tableau déjà décodé puis réencodé : un octet de différence invalide la signature.
     *
     * @param string               $payload         Corps brut de la requête.
     * @param string|null          $signatureHeader Valeur de l'en-tête `X-Prosms-Signature`.
     * @param string|list<string>  $secret          Secret de l'application (`whsec_…`) ; une liste est
     *                                              acceptée pendant une rotation (ancien et nouveau).
     * @param int                  $tolerance       Écart maximal, en secondes, entre `t` et l'heure locale.
     * @param string|null          $deliveryId      Valeur de `X-Prosms-Delivery`, recopiée dans l'événement.
     *
     * @throws WebhookSignatureError signature absente, mal formée, invalide ou expirée ; corps illisible
     */
    public static function verify(
        string $payload,
        ?string $signatureHeader,
        string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?string $deliveryId = null,
    ): WebhookEvent {
        $secrets = array_values(array_filter((array) $secret, static fn ($s): bool => is_string($s) && $s !== ''));
        if ($secrets === []) {
            throw new InvalidArgumentError('Secret de webhook manquant : passez le secret de l\'application (whsec_…).');
        }

        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new WebhookSignatureError('En-tête X-Prosms-Signature absent.');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = [trim($pair[0]), trim($pair[1])];
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            throw new WebhookSignatureError('Horodatage (t=) absent ou invalide dans X-Prosms-Signature.');
        }
        if ($signatures === []) {
            throw new WebhookSignatureError('Aucune signature v1 dans X-Prosms-Signature.');
        }
        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureError(sprintf(
                'Webhook expiré : horodatage hors de la tolérance de %d s (horloge du serveur à vérifier, ou rejeu).',
                $tolerance,
            ));
        }

        $valid = false;
        foreach ($secrets as $candidate) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $candidate);
            foreach ($signatures as $signature) {
                // Pas de court-circuit : temps constant quelle que soit la signature qui correspond.
                $valid = hash_equals($expected, $signature) || $valid;
            }
        }
        if (! $valid) {
            throw new WebhookSignatureError('Signature de webhook invalide : vérifiez le secret et passez le corps brut.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WebhookSignatureError('Corps du webhook illisible (JSON invalide).', 0, $e);
        }
        if (! is_array($decoded)) {
            throw new WebhookSignatureError('Corps du webhook inattendu : objet JSON attendu.');
        }

        return WebhookEvent::fromArray($decoded, $deliveryId);
    }

    /**
     * Calcule l'en-tête `X-Prosms-Signature` d'un corps (utile pour tester votre point d'entrée).
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
