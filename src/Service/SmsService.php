<?php

declare(strict_types=1);

namespace ProSms\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use ProSms\Enum\MessageStatus;
use ProSms\Enum\SmsType;
use ProSms\Exception\ApiError;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Http\Requester;
use ProSms\Model\Message;
use ProSms\Model\MessagePage;
use ProSms\Model\SendResult;

/**
 * Envoi et suivi des SMS (`$client->sms`). Les paramètres s'utilisent en arguments nommés.
 */
final class SmsService
{
    /** @internal */
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Envoie un même texte à un ou plusieurs numéros (`POST /v1/sms`).
     *
     * @param string|list<string>           $to         Un numéro ou une liste (1 000 au plus).
     * @param string                        $text       400 caractères au plus.
     * @param string|null                   $from       Sender approuvé ; par défaut celui de l'application.
     * @param DateTimeInterface|string|null $scheduleAt Envoi programmé (converti en UTC ISO 8601).
     * @param string|null                   $idempotencyKey Générée automatiquement si absente.
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function send(
        string|array $to,
        string $text,
        ?string $from = null,
        ?string $reference = null,
        ?string $callbackUrl = null,
        SmsType|string|null $type = null,
        DateTimeInterface|string|null $scheduleAt = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        return $this->submit('/v1/sms', [
            'from' => $from,
            'to' => self::recipients($to),
            'text' => $text,
            'reference' => $reference,
            'schedule_at' => self::dateTime($scheduleAt),
            'callback_url' => $callbackUrl,
            'type' => $type instanceof SmsType ? $type->value : $type,
        ], $idempotencyKey);
    }

    /**
     * Envoie un code à usage unique (`type: otp`) : file dédiée, 10 destinataires au plus, pas de programmation.
     *
     * @param string|list<string> $to
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function sendOtp(
        string|array $to,
        string $text,
        ?string $from = null,
        ?string $reference = null,
        ?string $callbackUrl = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        return $this->send(
            to: $to,
            text: $text,
            from: $from,
            reference: $reference,
            callbackUrl: $callbackUrl,
            type: SmsType::Otp,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Envoie des textes personnalisés (`POST /v1/sms/batch`).
     *
     * @param list<array{to: string, text: string, reference?: string|null}> $messages
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function sendBatch(
        array $messages,
        ?string $from = null,
        DateTimeInterface|string|null $scheduleAt = null,
        ?string $callbackUrl = null,
        SmsType|string|null $type = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        if ($messages === []) {
            throw new InvalidArgumentError('sendBatch : la liste « messages » est vide.');
        }

        $items = [];
        foreach (array_values($messages) as $index => $message) {
            if (! is_array($message) || ! is_string($message['to'] ?? null) || ! is_string($message['text'] ?? null)) {
                throw new InvalidArgumentError(sprintf(
                    'sendBatch : le message n°%d doit être un tableau avec « to » et « text » (chaînes).',
                    $index,
                ));
            }
            $item = ['to' => $message['to'], 'text' => $message['text']];
            if (isset($message['reference'])) {
                $item['reference'] = (string) $message['reference'];
            }
            $items[] = $item;
        }

        return $this->submit('/v1/sms/batch', [
            'from' => $from,
            'messages' => $items,
            'schedule_at' => self::dateTime($scheduleAt),
            'callback_url' => $callbackUrl,
            'type' => $type instanceof SmsType ? $type->value : $type,
        ], $idempotencyKey);
    }

    /**
     * Consulte un message (`GET /v1/sms/{id}`). Un message tout juste accepté peut répondre 404
     * pendant une à deux secondes.
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function get(string $id): Message
    {
        if ($id === '') {
            throw new InvalidArgumentError('sms->get : identifiant de message vide.');
        }

        [$json] = $this->requester->request('GET', '/v1/sms/' . rawurlencode($id));

        return Message::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);
    }

    /**
     * Une page de messages de l'application (`GET /v1/sms`), du plus récent au plus ancien.
     *
     * @param DateTimeInterface|string|null $fromDate Date de début incluse (`yyyy-MM-dd`).
     * @param DateTimeInterface|string|null $toDate   Date de fin incluse (`yyyy-MM-dd`).
     * @param int|null                      $perPage  1 à 100 (50 par défaut côté API).
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function list(
        MessageStatus|string|null $status = null,
        ?string $reference = null,
        ?string $to = null,
        DateTimeInterface|string|null $fromDate = null,
        DateTimeInterface|string|null $toDate = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): MessagePage {
        $query = self::listQuery($status, $reference, $to, $fromDate, $toDate, $perPage);
        $query['cursor'] = $cursor;

        [$json] = $this->requester->request('GET', '/v1/sms', $query);

        return MessagePage::fromArray($json);
    }

    /**
     * Parcourt tous les messages, page après page, à la demande (générateur paresseux).
     *
     * ```php
     * foreach ($client->sms->listAll(status: 'failed') as $message) { … }
     * ```
     *
     * @return Generator<int, Message>
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function listAll(
        MessageStatus|string|null $status = null,
        ?string $reference = null,
        ?string $to = null,
        DateTimeInterface|string|null $fromDate = null,
        DateTimeInterface|string|null $toDate = null,
        ?int $perPage = null,
        ?string $cursor = null,
    ): Generator {
        // Validation immédiate, avant la première itération.
        $query = self::listQuery($status, $reference, $to, $fromDate, $toDate, $perPage);

        return $this->paginate($query, $cursor);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return Generator<int, Message>
     */
    private function paginate(array $query, ?string $cursor): Generator
    {
        $index = 0;
        do {
            [$json] = $this->requester->request('GET', '/v1/sms', $query + ['cursor' => $cursor]);
            $page = MessagePage::fromArray($json);
            foreach ($page->data as $message) {
                yield $index++ => $message;
            }
            // Garde-fou : un curseur qui ne bouge pas arrêterait la boucle à l'infini.
            $cursor = $page->nextCursor === $cursor ? null : $page->nextCursor;
        } while ($cursor !== null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submit(string $path, array $body, ?string $idempotencyKey): SendResult
    {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentError('idempotencyKey ne peut pas être vide.');
        }

        [$json, $response] = $this->requester->request('POST', $path, [], $body, $idempotencyKey);

        return SendResult::fromArray(
            is_array($json['data'] ?? null) ? $json['data'] : [],
            strtolower((string) $response->header('idempotent-replayed')) === 'true',
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function listQuery(
        MessageStatus|string|null $status,
        ?string $reference,
        ?string $to,
        DateTimeInterface|string|null $fromDate,
        DateTimeInterface|string|null $toDate,
        ?int $perPage,
    ): array {
        if ($perPage !== null && ($perPage < 1 || $perPage > 100)) {
            throw new InvalidArgumentError('perPage doit être compris entre 1 et 100.');
        }

        return [
            'status' => $status instanceof MessageStatus ? $status->value : $status,
            'reference' => $reference,
            'to' => $to,
            'from_date' => $fromDate instanceof DateTimeInterface ? $fromDate->format('Y-m-d') : $fromDate,
            'to_date' => $toDate instanceof DateTimeInterface ? $toDate->format('Y-m-d') : $toDate,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param string|list<string> $to
     *
     * @return string|list<string>
     */
    private static function recipients(string|array $to): string|array
    {
        if (is_string($to)) {
            return $to;
        }
        if ($to === []) {
            throw new InvalidArgumentError('« to » est vide : indiquez au moins un numéro.');
        }
        foreach ($to as $number) {
            if (! is_string($number)) {
                throw new InvalidArgumentError('« to » doit être une chaîne ou une liste de chaînes.');
            }
        }

        return array_values($to);
    }

    private static function dateTime(DateTimeInterface|string|null $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return $value;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
