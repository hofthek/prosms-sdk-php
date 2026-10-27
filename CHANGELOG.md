# Journal des modifications

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Le projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

### Ajouté

- `Message::$operatorReference` : référence attribuée par l'opérateur à l'envoi (`operator_reference` de l'API), `null` tant que le message n'est pas transmis.
- `WebhookMessage::$operatorReference` : la même référence dans les webhooks `message.status`.

## [1.0.0] - 2026-10-13

Première version publique.

### Ajouté

- Client `ProSms\ProSms`. Configuration par arguments nommés ou par variables d'environnement : `PROSMS_API_KEY`, `PROSMS_BASE_URL` et `PROSMS_SIGNING_SECRET`.
- Contrôle du format de la clé avant tout appel réseau, et indicateur `isTest`.
- Envoi :
  - `sms->send` ;
  - `sms->sendOtp` (`type: otp`) ;
  - `sms->sendBatch`, avec envoi programmé (`scheduleAt`, converti en UTC) et URL de rappel.
- Suivi :
  - `sms->get` ;
  - `sms->list` (une page) ;
  - `sms->listAll` (générateur paresseux, page après page).
- Compte : `balance->get` et `senders->list`.
- Modèles en lecture seule :
  - `SendResult`, `AcceptedMessage`, `Rejected`, `Cost` ;
  - `Message` et `MessagePage` ;
  - `Balance`, `Sender` et `RateLimit` ;
  - `WebhookEvent` et `WebhookMessage`.
- Énumérations `MessageStatus`, `SmsType` et `WebhookEventType`. Les valeurs inconnues de l'API restent conservées en chaîne brute.
- `Idempotency-Key` automatique (UUID v4), une par appel et réutilisée à chaque réessai. `SendResult::$idempotentReplayed` signale une réponse rejouée.
- Réessais automatiques sur erreur réseau, `429` (avec `Retry-After`, 60 s au plus), `500`, `502`, `503` et `504`. Backoff exponentiel avec gigue, plafonné à 8 s.
- Signature HMAC des requêtes (`signingSecret`). L'horodatage avance d'une seconde, jusqu'à une seconde libre, seulement pour une requête identique déjà signée à la même seconde. Le serveur refuse en effet le rejeu d'une signature de POST. Les autres requêtes gardent l'heure réelle.
- Vérification des webhooks :
  - `ProSms\Webhook::verify`, statique ;
  - `$client->webhooks->verify`.

  Elle compare en temps constant, applique une tolérance de 300 s par défaut et accepte plusieurs `v1` ou plusieurs secrets (rotation). `Webhook::sign` sert aux tests.
- Hiérarchie d'erreurs :
  - `ProSmsError` ;
  - `ApiError` et ses sous-classes par statut ;
  - `ConnectionError` et `TimeoutError` ;
  - `WebhookSignatureError` ;
  - `InvalidArgumentError`.

  Le `code` de l'API est exposé dans `errorCode` et par `getCode()`.
- `lastRateLimit` : débit relevé sur la dernière réponse.
- Transports :
  - `CurlTransport` par défaut (connexion réutilisée, sans redirections) ;
  - adaptateur `Psr18Transport` ;
  - interface `Transport`, injectable.
