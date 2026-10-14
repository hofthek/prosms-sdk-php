<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * Configuration ou paramètre invalide, détecté avant tout appel réseau (clé mal formée, `perPage` > 100…).
 */
class InvalidArgumentError extends ProSmsError
{
}
