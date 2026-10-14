<?php

declare(strict_types=1);

namespace ProSms;

use Closure;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Http\CurlTransport;
use ProSms\Http\Requester;
use ProSms\Http\Transport;
use ProSms\Model\RateLimit;
use ProSms\Service\BalanceService;
use ProSms\Service\SendersService;
use ProSms\Service\SmsService;
use ProSms\Service\WebhooksService;

/**
 * Client de l'API publique ProSMS (`/v1`). À utiliser côté serveur uniquement : la clé API est secrète.
 *
 * ```php
 * $client = new ProSms(apiKey: 'psk_test_…');
 * $result = $client->sms->send(to: '0700000000', text: 'Bonjour');
 * ```
 *
 * @property-read RateLimit|null $lastRateLimit Débit relevé sur la dernière réponse (null si absent).
 */
final class ProSms
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://api.prosms.ci';

    public readonly SmsService $sms;

    public readonly BalanceService $balance;

    public readonly SendersService $senders;

    public readonly WebhooksService $webhooks;

    /** Vrai pour une clé `psk_test_` (envois simulés, sans débit). */
    public readonly bool $isTest;

    public readonly string $baseUrl;

    private readonly Requester $requester;

    /**
     * @param string|null                $apiKey        Clé `psk_live_…` ou `psk_test_…` ; à défaut `PROSMS_API_KEY`.
     * @param string|null                $baseUrl       Origine de l'API, sans `/v1` ; à défaut `PROSMS_BASE_URL`,
     *                                                  puis `https://api.prosms.ci`.
     * @param string|null                $signingSecret Secret de l'application (`whsec_…`) pour signer chaque
     *                                                  requête ; à défaut `PROSMS_SIGNING_SECRET`.
     * @param float                      $timeout       Délai maximal par tentative, en secondes.
     * @param int                        $maxRetries    Réessais automatiques (réseau, 429, 500/502/503/504).
     * @param Transport|null             $transport     Transport HTTP (par défaut : curl).
     * @param (Closure(float): void)|null $sleep        Attente entre deux tentatives (tests, coroutines) ;
     *                                                  par défaut `usleep`.
     *
     * @throws InvalidArgumentError configuration invalide (clé absente ou mal formée…)
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $signingSecret = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?Transport $transport = null,
        ?Closure $sleep = null,
    ) {
        $apiKey = trim($apiKey ?? self::env('PROSMS_API_KEY') ?? '');
        if ($apiKey === '') {
            throw new InvalidArgumentError('Clé API ProSMS manquante : passez apiKey ou définissez la variable d\'environnement PROSMS_API_KEY.');
        }
        if (! str_starts_with($apiKey, 'psk_live_') && ! str_starts_with($apiKey, 'psk_test_')) {
            throw new InvalidArgumentError('Clé API ProSMS invalide : elle doit commencer par « psk_live_ » ou « psk_test_ ».');
        }

        $baseUrl = rtrim(trim($baseUrl ?? self::env('PROSMS_BASE_URL') ?? self::DEFAULT_BASE_URL), '/');
        if (str_ends_with($baseUrl, '/v1')) {
            $baseUrl = substr($baseUrl, 0, -3);
        }
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true) || parse_url($baseUrl, PHP_URL_HOST) === null) {
            throw new InvalidArgumentError(sprintf('baseUrl invalide (« %s ») : URL http(s) attendue, par exemple https://api.prosms.ci.', $baseUrl));
        }

        $signingSecret ??= self::env('PROSMS_SIGNING_SECRET');
        if ($signingSecret === '') {
            $signingSecret = null;
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentError('timeout doit être strictement positif (en secondes).');
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentError('maxRetries ne peut pas être négatif.');
        }

        $this->isTest = str_starts_with($apiKey, 'psk_test_');
        $this->baseUrl = $baseUrl;
        $this->requester = new Requester(
            $apiKey,
            $baseUrl,
            $signingSecret,
            $timeout,
            $maxRetries,
            $transport ?? new CurlTransport(),
            $sleep ?? static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            },
        );

        $this->sms = new SmsService($this->requester);
        $this->balance = new BalanceService($this->requester);
        $this->senders = new SendersService($this->requester);
        $this->webhooks = new WebhooksService($signingSecret);
    }

    public function __get(string $name): mixed
    {
        if ($name === 'lastRateLimit') {
            return $this->requester->lastRateLimit;
        }

        throw new \LogicException(sprintf('Propriété inconnue : %s::$%s.', self::class, $name));
    }

    public function __isset(string $name): bool
    {
        return $name === 'lastRateLimit' && $this->requester->lastRateLimit !== null;
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
