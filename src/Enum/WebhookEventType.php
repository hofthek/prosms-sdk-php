<?php

declare(strict_types=1);

namespace ProSms\Enum;

/**
 * Événements de webhook connus. `WebhookEvent::$event` garde la chaîne brute.
 */
enum WebhookEventType: string
{
    case MessageStatus = 'message.status';
    case WebhookTest = 'webhook.test';
}
