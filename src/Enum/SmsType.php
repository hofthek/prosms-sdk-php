<?php

declare(strict_types=1);

namespace ProSms\Enum;

/**
 * Type d'envoi. `Otp` est réservé aux codes à usage unique (file dédiée, 10 destinataires au plus,
 * pas de programmation).
 */
enum SmsType: string
{
    case Standard = 'standard';
    case Otp = 'otp';
}
