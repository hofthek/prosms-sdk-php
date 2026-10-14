<?php

declare(strict_types=1);

namespace ProSms\Http;

use ProSms\Exception\ConnectionError;
use ProSms\Exception\TimeoutError;

/**
 * Transport HTTP du SDK. Implémentation par défaut : {@see CurlTransport}.
 *
 * Un transport envoie la requête telle quelle (sans suivre les redirections) et renvoie la réponse,
 * quel que soit son statut. Il ne lève une exception qu'en cas d'échec réseau.
 */
interface Transport
{
    /**
     * @throws TimeoutError    délai dépassé
     * @throws ConnectionError erreur réseau, DNS ou TLS
     */
    public function send(Request $request): Response;
}
