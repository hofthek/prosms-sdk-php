<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * 5xx : erreur interne de l'API (`server_error`), réessayée automatiquement.
 */
class ServerError extends ApiError
{
}
