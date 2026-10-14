<?php

declare(strict_types=1);

namespace ProSms\Exception;

use ProSms\Model\Rejected;

/**
 * 422 : requête refusée (`validation_failed` avec `errors`, `invalid_sender`, `no_valid_recipient`,
 * `otp_too_many_recipients`).
 */
class ValidationError extends ApiError
{
    /**
     * Numéros refusés et motifs, pour `no_valid_recipient` (`data.rejected`) ; liste vide sinon.
     *
     * @return list<Rejected>
     */
    public function rejected(): array
    {
        $rejected = $this->json['data']['rejected'] ?? [];

        return is_array($rejected)
            ? array_values(array_map(Rejected::fromArray(...), array_filter($rejected, 'is_array')))
            : [];
    }
}
