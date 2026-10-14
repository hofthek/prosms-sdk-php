<?php

declare(strict_types=1);

namespace ProSms\Enum;

/**
 * Statuts connus d'un message. Les modèles gardent la chaîne brute (`$message->status`) : une valeur
 * ajoutée plus tard par l'API ne casse rien, `MessageStatus::tryFrom()` renvoie alors null.
 */
enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Statut définitif : le message n'évoluera plus. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Cancelled => true,
            self::Queued, self::Sent => false,
        };
    }
}
