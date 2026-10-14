<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * 409 : `idempotency_conflict` (même clé avec un autre corps, ou requête initiale encore en cours). Jamais réessayée.
 */
class ConflictError extends ApiError
{
}
