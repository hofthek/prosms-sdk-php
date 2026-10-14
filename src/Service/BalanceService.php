<?php

declare(strict_types=1);

namespace ProSms\Service;

use ProSms\Exception\ApiError;
use ProSms\Exception\ConnectionError;
use ProSms\Http\Requester;
use ProSms\Model\Balance;

/**
 * Soldes du compte (`$client->balance`).
 */
final class BalanceService
{
    /** @internal */
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Soldes disponibles en unités SMS (`GET /v1/balance`, scope `balance:read`).
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function get(): Balance
    {
        [$json] = $this->requester->request('GET', '/v1/balance');

        return Balance::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);
    }
}
