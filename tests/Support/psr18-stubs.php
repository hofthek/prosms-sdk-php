<?php

declare(strict_types=1);

// Interfaces PSR-18 minimales pour tester l'adaptateur sans installer psr/http-client.

namespace Psr\Http\Client;

if (! interface_exists(ClientExceptionInterface::class)) {
    interface ClientExceptionInterface extends \Throwable
    {
    }
}

if (! interface_exists(NetworkExceptionInterface::class)) {
    interface NetworkExceptionInterface extends ClientExceptionInterface
    {
    }
}
