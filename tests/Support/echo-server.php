<?php

declare(strict_types=1);

// Routeur du serveur PHP intégré pour CurlTransportTest : renvoie la requête reçue en JSON.

$uri = $_SERVER['REQUEST_URI'];

if (str_starts_with($uri, '/slow')) {
    usleep(1_500_000);
}

if (str_starts_with($uri, '/redirect')) {
    header('Location: /elsewhere', true, 302);

    return;
}

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}

http_response_code(str_starts_with($uri, '/error') ? 503 : 200);
header('Content-Type: application/json');
header('X-RateLimit-Limit: 50');
header('X-RateLimit-Remaining: 7');
echo json_encode([
    'data' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $uri,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
