<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * 401 : clé API absente, invalide, expirée ou signature refusée (`invalid_api_key`, `expired_api_key`, `invalid_signature`).
 */
class AuthenticationError extends ApiError
{
}
