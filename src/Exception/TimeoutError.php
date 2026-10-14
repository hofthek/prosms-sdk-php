<?php

declare(strict_types=1);

namespace ProSms\Exception;

/**
 * Délai d'attente dépassé (option `timeout`, par tentative).
 */
class TimeoutError extends ConnectionError
{
}
