<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * Webhook refusé : signature absente, mal formée, invalide ou expirée, ou corps JSON illisible.
 */
class WebhookSignatureError extends ProSmsError
{
}
