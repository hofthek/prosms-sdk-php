# ProSMS pour PHP

SDK PHP officiel de l'[API publique ProSMS](https://prosms.ci) (`/v1`) : envoi de SMS et de codes à usage unique, suivi des messages, solde, senders et vérification des webhooks.

- PHP 8.1 ou plus récent, extensions `curl` et `json`, aucune autre dépendance.
- Réessais automatiques sûrs, grâce à l'`Idempotency-Key`.
- Signature HMAC des requêtes et vérification des webhooks.
- Objets en lecture seule et énumérations.

> **À utiliser côté serveur uniquement.** La clé API est un secret : elle ne doit jamais apparaître dans un navigateur, une application mobile ou un dépôt de code. Voir [Mises en garde](#mises-en-garde).

## Sommaire

- [Installation](#installation)
- [Démarrage rapide](#démarrage-rapide)
- [Configuration](#configuration)
- [Méthodes](#méthodes)
- [Codes à usage unique (OTP)](#codes-à-usage-unique-otp)
- [Envoi programmé](#envoi-programmé)
- [Envoi en lot](#envoi-en-lot)
- [Pagination](#pagination)
- [Gestion des erreurs](#gestion-des-erreurs)
- [Idempotence et réessais](#idempotence-et-réessais)
- [Signature des requêtes](#signature-des-requêtes)
- [Webhooks](#webhooks)
- [Clés de test](#clés-de-test)
- [Débit](#débit)
- [Transport HTTP personnalisé](#transport-http-personnalisé)
- [Mises en garde](#mises-en-garde)
- [Développement](#développement)

## Installation

```sh
composer require prosms/prosms-php
```

## Démarrage rapide

```php
<?php

require 'vendor/autoload.php';

use ProSms\ProSms;

$prosms = new ProSms(apiKey: getenv('PROSMS_API_KEY'));

$result = $prosms->sms->send(
    to: '0700000000',
    text: 'Votre commande 1234 est prête.',
    from: 'BOUTIQUE',
    reference: 'commande-1234',
);

echo $result->messages[0]->id;     // 0192f1b2-…
echo $result->messages[0]->status; // queued
```

Les paramètres s'écrivent en **arguments nommés** (`to:`, `text:`…), en camelCase. Le SDK les traduit vers le snake_case de l'API (`callbackUrl` devient `callback_url`). Un tableau associatif fonctionne aussi, grâce à la décomposition : `$prosms->sms->send(...$params)`.

## Configuration

```php
$prosms = new ProSms(
    apiKey: 'psk_live_…',               // ou variable d'environnement PROSMS_API_KEY
    baseUrl: 'https://api.prosms.ci',   // ou PROSMS_BASE_URL ; origine sans /v1
    signingSecret: 'whsec_…',           // ou PROSMS_SIGNING_SECRET ; signe chaque requête
    timeout: 30.0,                      // secondes, par tentative
    maxRetries: 2,                      // réessais automatiques
);
```

| Option | Défaut | Rôle |
|---|---|---|
| `apiKey` | `PROSMS_API_KEY` | Clé `psk_live_…` ou `psk_test_…`, obligatoire. |
| `baseUrl` | `PROSMS_BASE_URL`, sinon `https://api.prosms.ci` | Origine de l'API, sans `/v1`. |
| `signingSecret` | `PROSMS_SIGNING_SECRET`, sinon aucun | Secret de l'application : signe les requêtes, et sert par défaut à vérifier les webhooks. |
| `timeout` | `30.0` | Délai maximal par tentative, en secondes. |
| `maxRetries` | `2` | Nombre de réessais automatiques. |
| `transport` | curl | Transport HTTP (voir [Transport HTTP personnalisé](#transport-http-personnalisé)). |

Une clé qui ne commence ni par `psk_live_` ni par `psk_test_` est refusée tout de suite (`InvalidArgumentError`), avant tout appel réseau. `$prosms->isTest` vaut `true` pour une clé de test.

## Méthodes

| Méthode | Requête | Retour |
|---|---|---|
| `$prosms->sms->send(...)` | `POST /v1/sms` | `SendResult` |
| `$prosms->sms->sendOtp(...)` | `POST /v1/sms` (`type: otp`) | `SendResult` |
| `$prosms->sms->sendBatch(...)` | `POST /v1/sms/batch` | `SendResult` |
| `$prosms->sms->get($id)` | `GET /v1/sms/{id}` | `Message` |
| `$prosms->sms->list(...)` | `GET /v1/sms` | `MessagePage` (une page) |
| `$prosms->sms->listAll(...)` | `GET /v1/sms`, page après page | générateur de `Message` |
| `$prosms->balance->get()` | `GET /v1/balance` | `Balance` |
| `$prosms->senders->list()` | `GET /v1/senders` | `list<Sender>` |
| `$prosms->webhooks->verify(...)` | aucune (calcul local) | `WebhookEvent` |
| `ProSms\Webhook::verify(...)` | aucune (statique, sans client) | `WebhookEvent` |

### `sms->send` : un même texte à un ou plusieurs numéros

```php
$result = $prosms->sms->send(
    to: ['0700000000', '+2250500000000'],   // chaîne ou liste (1 000 numéros au plus)
    text: 'Bonjour, votre colis arrive demain.', // 400 caractères au plus
    from: 'BOUTIQUE',                     // facultatif : sender par défaut de l'application
    reference: 'colis-987',               // facultatif, 64 caractères au plus
    callbackUrl: 'https://exemple.ci/hooks/sms', // facultatif : URL des webhooks de ces messages
);

foreach ($result->messages as $message) {
    echo "{$message->to} : {$message->id} ({$message->segments} segment(s), {$message->carrier})\n";
}
foreach ($result->rejected as $rejected) {
    echo "Refusé : {$rejected->to} ({$rejected->reason})\n"; // invalid_number, no_route…
}
echo $result->cost->national;   // unités SMS par solde
echo $result->segments;         // segments acceptés au total
var_dump($result->test);        // true avec une clé de test
var_dump($result->ids());       // identifiants des messages acceptés
```

Les numéros invalides ne font pas échouer l'envoi : ils sont listés dans `rejected`, sans être facturés. Si **aucun** numéro n'est accepté, l'API répond `422 no_valid_recipient` (voir [Gestion des erreurs](#gestion-des-erreurs)).

### `sms->get` : statut d'un message

```php
$message = $prosms->sms->get('0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03');

echo $message->status;                         // queued, sent, delivered, failed, cancelled
echo $message->deliveredAt?->format(DATE_ATOM); // DateTimeImmutable en UTC, ou null
echo $message->error;                          // code d'erreur si failed

if ($message->statusEnum() === \ProSms\Enum\MessageStatus::Delivered) {
    // …
}
$message->isFinal(); // delivered, failed ou cancelled
```

`status` garde toujours la chaîne brute de l'API : un statut ajouté plus tard ne fait pas planter le SDK, et `statusEnum()` renvoie alors `null`.

Un message tout juste accepté peut répondre `404` pendant une à deux secondes, car son écriture est asynchrone. Préférez les [webhooks](#webhooks) à une interrogation en boucle.

### `sms->list` : une page de messages

```php
use ProSms\Enum\MessageStatus;

$page = $prosms->sms->list(
    status: MessageStatus::Failed,   // ou 'failed'
    reference: 'commande-1234',
    to: '0700000000',
    fromDate: new DateTimeImmutable('-7 days'), // ou '2026-10-01'
    toDate: '2026-10-13',
    perPage: 100,                    // 1 à 100, 50 par défaut
);

foreach ($page as $message) {
    echo $message->id, ' ', $message->status, "\n";
}
$page->nextCursor; // à passer en `cursor:` pour la page suivante, null en fin de liste
```

### `balance->get` : soldes

```php
$balance = $prosms->balance->get();
echo "National : {$balance->national} SMS, international : {$balance->international} SMS";
```

### `senders->list` : senders utilisables

```php
foreach ($prosms->senders->list() as $sender) {
    echo $sender->name, $sender->isDefault ? ' (par défaut)' : '', "\n";
}
```

## Codes à usage unique (OTP)

`sendOtp` envoie avec `type: otp`. Ces messages passent par une file dédiée, traitée avant tout autre trafic.

```php
$code = random_int(100000, 999999);

$prosms->sms->sendOtp('0700000000', "Votre code ProSMS est {$code}. Il expire dans 5 minutes.", from: 'BOUTIQUE');

// Équivalent :
$prosms->sms->send(to: '0700000000', text: "…", type: \ProSms\Enum\SmsType::Otp);
```

Règles de l'API :
- **10 destinataires au plus** par requête, sinon `422 otp_too_many_recipients` ;
- **pas d'envoi programmé** : `scheduleAt` est refusé avec `type: otp` ;
- **uniquement pour des codes** (connexion, paiement, vérification de numéro), jamais pour du marketing ou des notifications. La file est partagée par tous les clients, et la plateforme peut désactiver une application qui détourne ce type ;
- au-delà de 600 destinataires OTP par minute et par compte, les messages partent quand même, par la file prioritaire standard.

## Envoi programmé

`scheduleAt` accepte une date PHP (`DateTimeInterface`), convertie en UTC ISO 8601, ou une chaîne ISO 8601 envoyée telle quelle. La date doit être dans le futur, à moins de 31 jours.

```php
$prosms->sms->send(
    to: '0700000000',
    text: 'Rappel : rendez-vous demain à 9 h.',
    scheduleAt: new DateTimeImmutable('tomorrow 08:00', new DateTimeZone('Africa/Abidjan')),
);

$prosms->sms->send(to: '0700000000', text: '…', scheduleAt: '2026-10-20T08:00:00Z');
```

## Envoi en lot

`sendBatch` envoie un texte personnalisé par destinataire, en une seule requête de 1 000 messages au plus.

```php
$result = $prosms->sms->sendBatch(
    messages: [
        ['to' => '0700000000', 'text' => 'Bonjour Awa, votre facture est disponible.', 'reference' => 'facture-a1'],
        ['to' => '0500000000', 'text' => 'Bonjour Koffi, votre facture est disponible.', 'reference' => 'facture-k2'],
    ],
    from: 'BOUTIQUE',
    callbackUrl: 'https://exemple.ci/hooks/sms', // s'applique à tout le lot
    scheduleAt: new DateTimeImmutable('+1 hour'),  // idem
);
```

Une requête de 100 destinataires au plus part dans la file prioritaire. Au-delà, elle est traitée comme un envoi en masse. Pour une vraie campagne, utilisez le back-office.

## Pagination

`listAll` renvoie un **générateur paresseux** : les pages sont demandées une à une, au fil de l'itération. Les filtres sont les mêmes que pour `list`.

```php
foreach ($prosms->sms->listAll(status: 'failed', fromDate: '2026-10-01') as $message) {
    echo $message->to, ' ', $message->error, "\n";
}

// Arrêt anticipé : aucune page de plus n'est demandée.
$recent = [];
foreach ($prosms->sms->listAll(perPage: 20) as $message) {
    $recent[] = $message;
    if (count($recent) === 50) {
        break;
    }
}
```

Pour paginer à la main, passez `nextCursor` à `list(cursor: …)` jusqu'à ce qu'il vaille `null`.

## Gestion des erreurs

Toutes les erreurs du SDK héritent de `ProSms\Exception\ProSmsError` :

```
ProSmsError
├── ApiError                     status, errorCode, getMessage(), errors, headers, body, json, requestId
│   ├── AuthenticationError      401
│   ├── PermissionDeniedError    403
│   ├── NotFoundError            404
│   ├── ConflictError            409
│   ├── InsufficientBalanceError 402
│   ├── ValidationError          422 (+ rejected())
│   ├── RateLimitError           429 (+ retryAfter)
│   └── ServerError              5xx
├── ConnectionError              réseau, DNS, TLS
│   └── TimeoutError             délai dépassé
├── WebhookSignatureError        webhook refusé
└── InvalidArgumentError         configuration ou paramètre invalide (avant tout appel)
```

`errorCode` porte le `code` de l'API tel quel. `getCode()` renvoie la même chaîne, comme `PDOException`. Vous pouvez donc brancher sur le code plutôt que sur la classe :

```php
use ProSms\Exception\ApiError;
use ProSms\Exception\ConnectionError;
use ProSms\Exception\InsufficientBalanceError;
use ProSms\Exception\ValidationError;

try {
    $prosms->sms->send(to: $phone, text: $text, from: 'BOUTIQUE');
} catch (InsufficientBalanceError $e) {
    // Rechargez le compte.
} catch (ValidationError $e) {
    match ($e->errorCode) {
        'validation_failed' => print_r($e->errors),      // ['text' => ['Le champ text est obligatoire.']]
        'no_valid_recipient' => print_r($e->rejected()), // motifs par numéro
        'invalid_sender', 'otp_too_many_recipients' => error_log($e->getMessage()),
        default => throw $e,
    };
} catch (ApiError $e) {
    error_log("ProSMS {$e->status} {$e->errorCode} : {$e->getMessage()} (référence : {$e->requestId})");
} catch (ConnectionError $e) {
    // Réseau indisponible, même après les réessais.
}
```

| HTTP | Classe | `errorCode` |
|---|---|---|
| 401 | `AuthenticationError` | `invalid_api_key`, `expired_api_key`, `invalid_signature` |
| 402 | `InsufficientBalanceError` | `insufficient_balance` |
| 403 | `PermissionDeniedError` | `insufficient_scope`, `ip_not_allowed`, `application_inactive`, `account_suspended`, `account_deletion_pending` |
| 404 | `NotFoundError` | `not_found` |
| 405 | `ApiError` | `method_not_allowed` |
| 409 | `ConflictError` | `idempotency_conflict` |
| 422 | `ValidationError` | `validation_failed` (+ `errors`), `invalid_sender`, `no_valid_recipient` (+ `rejected()`), `otp_too_many_recipients` |
| 429 | `RateLimitError` | `rate_limited` (+ `retryAfter`) |
| 5xx | `ServerError` | `server_error`, ou `http_error` si la réponse n'est pas du JSON |

`requestId` est l'identifiant de la requête : l'en-tête `X-Request-Id` de la réponse, à défaut le champ `request_id` du corps d'erreur, ou `null` s'il est absent. En cas de problème, communiquez `$e->requestId` au support : il permet de retrouver l'appel, même quand aucun message n'a été créé.

## Idempotence et réessais

Chaque `POST` porte un en-tête `Idempotency-Key`. Si vous n'en fournissez pas, le SDK génère un UUID v4 **une fois par appel**, et le réutilise pour tous les réessais de cet appel. Si une réponse se perd en route, le réessai ne renvoie donc jamais les SMS : l'API rejoue la réponse initiale.

Passez votre propre clé pour vous protéger aussi contre un double envoi entre deux exécutions de votre code, par exemple un job relancé :

```php
$result = $prosms->sms->send(
    to: '0700000000',
    text: 'Votre commande 1234 est expédiée.',
    idempotencyKey: 'commande-1234-expedition', // 255 caractères au plus, valable 24 h
);

if ($result->idempotentReplayed) {
    // Déjà envoyé : réponse initiale rejouée, aucun SMS renvoyé.
}
```

Si la même clé est réutilisée avec un autre corps, ou pendant que la requête initiale est encore en cours, l'API répond `409 idempotency_conflict` (`ConflictError`). Cette erreur n'est jamais réessayée.

**Réessais automatiques** (au plus `maxRetries`, 2 par défaut) :
- erreur réseau ou délai dépassé ;
- `429` : attente de `Retry-After` secondes (60 s au plus) ;
- `500`, `502`, `503`, `504` : backoff exponentiel avec gigue (0,5 s, 1 s, 2 s…, plafonné à 8 s).

Les autres erreurs `4xx` ne sont jamais réessayées. Avec `maxRetries: 0`, aucun réessai n'a lieu.

## Signature des requêtes

Si l'option « Exiger la signature » est activée sur l'application, passez son secret (`whsec_…`, affiché à la création ou à la rotation) :

```php
$prosms = new ProSms(apiKey: getenv('PROSMS_API_KEY'), signingSecret: getenv('PROSMS_SIGNING_SECRET'));
```

Le SDK ajoute alors à chaque tentative `X-Prosms-Timestamp` et `X-Prosms-Signature` :

```
hex(hmac_sha256(secret, timestamp + "\n" + MÉTHODE + "\n" + chemin_avec_query + "\n" + hex(sha256(corps))))
```

Le corps et l'URL sont construits une seule fois : ce qui est signé est exactement ce qui part. L'horloge du serveur qui appelle l'API doit être à l'heure (± 300 s, NTP recommandé).

Pour les `POST`, l'API n'accepte chaque signature qu'une fois (anti-rejeu). Or deux requêtes identiques, avec la même méthode, le même chemin et le même corps, signées à la même seconde ont la même signature. C'est le cas d'un réessai rapide, ou du même SMS envoyé deux fois de suite.

Le client garde donc en mémoire les signatures de la seconde en cours. Si une requête identique a déjà été signée à cet horodatage, il avance l'horodatage d'une seconde, jusqu'à en trouver une libre. Les requêtes différentes gardent l'heure réelle : rien ne dérive, même à fort débit.

Cette mémoire est propre à chaque instance de `ProSms`. Entre processus distincts (workers PHP-FPM, jobs), évitez d'envoyer exactement le même `POST` à la même seconde.

`ProSms\RequestSignature::compute($secret, $timestamp, $method, $path, $body)` calcule la signature si vous en avez besoin ailleurs.

## Webhooks

À chaque changement de statut (`sent`, `delivered`, `failed`), ProSMS envoie un `POST` signé vers le `callback_url` du message, ou à défaut vers celui de l'application :

```
X-Prosms-Signature: t=1760175000,v1=<hex hmac_sha256(secret, "1760175000." + corps brut)>
X-Prosms-Event: message.status
X-Prosms-Delivery: <uuid identique pour toutes les tentatives>
```

`verify` contrôle la signature (en temps constant), refuse un horodatage décalé de plus de 300 s, puis décode le JSON. En cas d'échec, il lève `WebhookSignatureError` : répondez alors `400`.

> **Passez le corps brut**, octet pour octet, tel qu'il a été reçu. Ne passez jamais un tableau décodé puis réencodé (`json_encode($request->all())`) : le moindre écart, comme un `\/` ou un espace, invalide la signature.

### PHP natif

```php
<?php

require 'vendor/autoload.php';

use ProSms\Exception\WebhookSignatureError;
use ProSms\Webhook;

$payload = file_get_contents('php://input'); // corps brut

try {
    $event = Webhook::verify(
        $payload,
        $_SERVER['HTTP_X_PROSMS_SIGNATURE'] ?? null,
        getenv('PROSMS_SIGNING_SECRET'),
        deliveryId: $_SERVER['HTTP_X_PROSMS_DELIVERY'] ?? null,
    );
} catch (WebhookSignatureError $e) {
    http_response_code(400);
    exit;
}

if ($event->message !== null) { // event = message.status
    // Dédoublonnez avec $event->deliveryId (livraison « au moins une fois »).
    // $event->message->id, ->status, ->reference, ->error, ->occurredAt
}

http_response_code(204);
```

### Laravel

```php
// routes/api.php (pas de middleware CSRF sur les routes API)
use Illuminate\Http\Request;
use ProSms\Exception\WebhookSignatureError;
use ProSms\Webhook;

Route::post('/hooks/sms', function (Request $request) {
    try {
        $event = Webhook::verify(
            $request->getContent(),                 // corps brut, pas $request->all()
            $request->header('X-Prosms-Signature'),
            config('services.prosms.signing_secret'),
            deliveryId: $request->header('X-Prosms-Delivery'),
        );
    } catch (WebhookSignatureError) {
        abort(400);
    }

    if ($event->message !== null) {
        // Répondez vite : traitez l'événement dans un job.
        ProcessSmsStatus::dispatch($event->message->id, $event->message->status, $event->deliveryId);
    }

    return response()->noContent();
});
```

Avec un client configuré, `$prosms->webhooks->verify($payload, $header)` utilise le `signingSecret` du client, qui est le même secret d'application.

Autres points :
- **Rotation du secret** : `verify` accepte une liste de secrets, l'ancien et le nouveau, le temps de la transition : `Webhook::verify($payload, $header, [$nouveau, $ancien])`.
- **Événement de test** : le bouton « Envoyer un test » du back-office envoie `webhook.test` (`$event->isTest()`, `$event->data['application_id']`).
- **Tester votre point d'entrée** : `Webhook::sign($payload, $secret)` produit un en-tête `X-Prosms-Signature` valide.
- **Ordre et doublons** : l'ordre d'arrivée n'est pas garanti, fiez-vous à `occurredAt`. Un même événement peut arriver plusieurs fois : dédoublonnez avec `X-Prosms-Delivery`.

## Clés de test

Une clé `psk_test_…` passe par la même validation que le mode réel, mais les messages sont **simulés** : rien n'est envoyé, rien n'est débité, et ils passent `delivered` après quelques secondes. Les webhooks sont déclenchés comme en production, et `type: otp` est validé de la même façon.

```php
$prosms = new ProSms(apiKey: 'psk_test_…');
$prosms->isTest; // true

$result = $prosms->sms->send(to: '0700000000', text: 'Essai');
$result->test;   // true ; le solde ne bouge pas
```

Utilisez une clé de test en développement et en intégration continue, et une clé `psk_live_…` en production seulement.

## Débit

Chaque application a un débit maximal (50 requêtes par seconde par défaut). Après chaque appel, `$prosms->lastRateLimit` donne l'état relevé sur la dernière réponse :

```php
$prosms->balance->get();
$prosms->lastRateLimit?->limit;     // 50
$prosms->lastRateLimit?->remaining; // 49
```

Au-delà, l'API répond `429`. Le SDK attend `Retry-After` puis réessaie. Une fois les réessais épuisés, il lève `RateLimitError` (`$e->retryAfter`).

## Transport HTTP personnalisé

Par défaut, le SDK utilise curl et réutilise la connexion entre les appels. Vous pouvez passer des options curl (proxy, certificats…) :

```php
use ProSms\Http\CurlTransport;

$prosms = new ProSms(transport: new CurlTransport([CURLOPT_PROXY => 'http://proxy.local:3128']));
```

Pour passer par votre client PSR-18 (Guzzle, Symfony HttpClient…), utilisez l'adaptateur `Psr18Transport`. Il ne suit pas les redirections, et son délai d'attente se règle sur votre client :

```php
use ProSms\Http\Psr18Transport;

$factory = new \Nyholm\Psr7\Factory\Psr17Factory(); // RequestFactory + StreamFactory
$prosms = new ProSms(transport: new Psr18Transport(new \GuzzleHttp\Client(['timeout' => 30]), $factory));
```

Vous pouvez aussi implémenter l'interface `ProSms\Http\Transport`, avec une seule méthode `send(Request): Response`. C'est utile pour vos propres tests.

## Mises en garde

- **La clé API et le secret de signature sont des secrets serveur.** Ne les mettez jamais dans du JavaScript envoyé au navigateur, dans une application mobile, ni dans un dépôt Git. Rangez-les dans des variables d'environnement ou un coffre-fort de secrets. Pour une application mobile, faites passer les envois par votre propre serveur.
- Si une clé fuit, révoquez-la tout de suite dans le back-office, puis créez-en une autre.
- Limitez les droits de chaque clé (scopes `sms:send`, `sms:read`, `balance:read`, `senders:read`) et, si possible, les adresses IP autorisées de l'application.
- Activez « Exiger la signature » sur l'application : une clé volée ne suffit plus pour envoyer des SMS.
- Vérifiez **toujours** la signature des webhooks avant d'agir sur leur contenu.

## Développement

```sh
composer install
vendor/bin/phpunit --testsuite unit   # sans réseau (faux transport)
```

Les tests d'intégration visent une API réelle avec une clé de test. Ils sont sautés si les variables suivantes ne sont pas définies :

```sh
PROSMS_TEST_BASE_URL=http://localhost:8090 \
PROSMS_TEST_API_KEY=psk_test_… \
PROSMS_TEST_SIGNING_SECRET=whsec_… \
PROSMS_TEST_SENDER=BOUTIQUE \
vendor/bin/phpunit --testsuite integration
```

`PROSMS_TEST_SIGNING_SECRET` et `PROSMS_TEST_SENDER` sont facultatives.

## Licence

MIT. Voir [LICENSE](LICENSE).
