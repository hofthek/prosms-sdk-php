<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * 403 : accès refusé (`insufficient_scope`, `ip_not_allowed`, `application_inactive`, `account_suspended`, `account_deletion_pending`).
 */
class PermissionDeniedError extends ApiError
{
}
