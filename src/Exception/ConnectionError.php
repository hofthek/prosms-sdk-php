<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * Échec réseau (DNS, connexion, TLS) : aucune réponse HTTP reçue.
 */
class ConnectionError extends ProSmsError
{
}
