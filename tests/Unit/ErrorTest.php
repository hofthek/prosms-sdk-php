<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use ProSms\Exception\ApiError;
use ProSms\Exception\AuthenticationError;
use ProSms\Exception\ConflictError;
use ProSms\Exception\InsufficientBalanceError;
use ProSms\Exception\NotFoundError;
use ProSms\Exception\PermissionDeniedError;
use ProSms\Exception\ProSmsError;
use ProSms\Exception\RateLimitError;
use ProSms\Exception\ServerError;
use ProSms\Exception\ValidationError;
use ProSms\Tests\Support\TestCase;

final class ErrorTest extends TestCase
{
    /**
     * @param class-string<ApiError> $class
     */
    #[DataProvider('statuses')]
    public function testErrorClassByStatus(int $status, string $code, string $class): void
    {
        $client = $this->client(maxRetries: 0);
        $this->transport->push($status, ['message' => 'Message lisible.', 'code' => $code], ['X-Request-Id' => 'req-1']);

        try {
            $client->balance->get();
            self::fail('Erreur attendue.');
        } catch (ApiError $e) {
            self::assertSame($class, $e::class);
            self::assertInstanceOf(ProSmsError::class, $e);
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
            self::assertSame($code, $e->getCode(), 'getCode() renvoie le code de l\'API, comme PDOException.');
            self::assertSame('Message lisible.', $e->getMessage());
            self::assertSame('req-1', $e->headers['x-request-id']);
            self::assertSame('req-1', $e->requestId);
            self::assertSame(['message' => 'Message lisible.', 'code' => $code], $e->json);
            self::assertJson($e->body);
        }
    }

    /** @return iterable<string, array{int, string, class-string<ApiError>}> */
    public static function statuses(): iterable
    {
        yield '401 invalid_api_key' => [401, 'invalid_api_key', AuthenticationError::class];
        yield '401 invalid_signature' => [401, 'invalid_signature', AuthenticationError::class];
        yield '402' => [402, 'insufficient_balance', InsufficientBalanceError::class];
        yield '403 insufficient_scope' => [403, 'insufficient_scope', PermissionDeniedError::class];
        yield '403 account_deletion_pending' => [403, 'account_deletion_pending', PermissionDeniedError::class];
        yield '404' => [404, 'not_found', NotFoundError::class];
        yield '405' => [405, 'method_not_allowed', ApiError::class];
        yield '409' => [409, 'idempotency_conflict', ConflictError::class];
        yield '422' => [422, 'invalid_sender', ValidationError::class];
        yield '429' => [429, 'rate_limited', RateLimitError::class];
        yield '500' => [500, 'server_error', ServerError::class];
        yield '503' => [503, 'server_error', ServerError::class];
    }

    public function testRequestIdFromHeaderThenBody(): void
    {
        $client = $this->client(maxRetries: 0);
        $this->transport
            ->push(401, ['message' => 'Clé invalide.', 'code' => 'invalid_api_key', 'request_id' => 'corps-1234'], ['X-Request-Id' => 'entete-1234'])
            ->push(422, ['message' => 'Invalide.', 'code' => 'validation_failed', 'request_id' => '0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03'])
            ->push(404, ['message' => 'Introuvable.', 'code' => 'not_found'])
            ->push(502, null, ['Content-Type' => 'text/html', 'X-Request-Id' => 'passerelle-42'], '<html>Bad Gateway</html>');

        $requestIds = [];
        for ($i = 0; $i < 4; $i++) {
            try {
                $client->balance->get();
                self::fail('Erreur attendue.');
            } catch (ApiError $e) {
                $requestIds[] = $e->requestId;
                if ($i === 0) {
                    self::assertSame('Clé invalide.', $e->getMessage(), 'Le message n\'est pas modifié.');
                }
            }
        }

        self::assertSame(['entete-1234', '0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03', null, 'passerelle-42'], $requestIds);
        self::assertNull((new ApiError('m', 400, 'x'))->requestId);
    }

    public function testValidationErrorsByField(): void
    {
        $client = $this->client();
        $this->transport->push(422, [
            'message' => 'Les données envoyées sont invalides.',
            'code' => 'validation_failed',
            'errors' => ['text' => ['Le champ text est obligatoire.'], 'to.0' => ['Trop long.']],
        ]);

        try {
            $client->sms->send(to: '07', text: '');
            self::fail('ValidationError attendue.');
        } catch (ValidationError $e) {
            self::assertSame('validation_failed', $e->errorCode);
            self::assertSame(['text' => ['Le champ text est obligatoire.'], 'to.0' => ['Trop long.']], $e->errors);
            self::assertSame([], $e->rejected());
        }
    }

    public function testNoValidRecipientExposesRejections(): void
    {
        $client = $this->client();
        $this->transport->push(422, [
            'message' => "Aucun destinataire valide : rien n'a été envoyé.",
            'code' => 'no_valid_recipient',
            'data' => ['messages' => [], 'rejected' => [['to' => '12', 'reason' => 'invalid_number']], 'segments' => 0, 'cost' => ['national' => 0, 'international' => 0], 'test' => true],
        ]);

        try {
            $client->sms->send(to: '12', text: 'x');
            self::fail('ValidationError attendue.');
        } catch (ValidationError $e) {
            self::assertSame('no_valid_recipient', $e->errorCode);
            self::assertCount(1, $e->rejected());
            self::assertSame('12', $e->rejected()[0]->to);
            self::assertSame('invalid_number', $e->rejected()[0]->reason);
        }
    }

    public function testNonJsonErrorFallsBackToHttpError(): void
    {
        $client = $this->client(maxRetries: 0);
        $this->transport->push(502, null, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>');

        try {
            $client->balance->get();
            self::fail('ServerError attendue.');
        } catch (ServerError $e) {
            self::assertSame('http_error', $e->errorCode);
            self::assertSame(502, $e->status);
            self::assertStringContainsString('502', $e->getMessage());
            self::assertSame('<html>Bad Gateway</html>', $e->body);
            self::assertNull($e->json);
        }
    }

    public function testUnreadableSuccessResponse(): void
    {
        $client = $this->client();
        $this->transport->push(200, null, [], 'pas du json');

        try {
            $client->balance->get();
            self::fail('ApiError attendue.');
        } catch (ApiError $e) {
            self::assertSame(ApiError::class, $e::class);
            self::assertSame('invalid_response', $e->errorCode);
        }
    }
}
