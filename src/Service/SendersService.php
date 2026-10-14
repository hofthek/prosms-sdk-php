<?php

declare(strict_types=1);

namespace ProSms\Service;

use ProSms\Exception\ApiError;
use ProSms\Exception\ConnectionError;
use ProSms\Http\Requester;
use ProSms\Model\Sender;

/**
 * Senders utilisables (`$client->senders`).
 */
final class SendersService
{
    /** @internal */
    public function __construct(private readonly Requester $requester)
    {
    }

    /**
     * Senders approuvés du compte et senders globaux (`GET /v1/senders`, scope `senders:read`).
     *
     * @return list<Sender>
     *
     * @throws ApiError
     * @throws ConnectionError
     */
    public function list(): array
    {
        [$json] = $this->requester->request('GET', '/v1/senders');
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return array_values(array_map(Sender::fromArray(...), array_filter($data, 'is_array')));
    }
}
