<?php

declare(strict_types=1);

namespace ProSms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use ProSms\Enum\MessageStatus;
use ProSms\Enum\SmsType;
use ProSms\Exception\InvalidArgumentError;
use ProSms\Model\AcceptedMessage;
use ProSms\Model\Message;
use ProSms\Model\Sender;
use ProSms\Tests\Support\TestCase;

final class SmsTest extends TestCase
{
    public function testSendSerializesParametersInSnakeCaseAndDropsNulls(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted(2));

        $client->sms->send(
            to: ['0700000000', '+2250500000000'],
            text: 'Votre commande est prête ✅',
            from: 'BOUTIQUE',
            reference: 'commande-1234',
            callbackUrl: 'https://exemple.ci/hooks/sms',
            scheduleAt: new DateTimeImmutable('2026-10-12 08:00:00', new DateTimeZone('Africa/Abidjan')),
        );

        $request = $this->transport->last();
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.prosms.test/v1/sms', $request->url);
        self::assertSame([
            'from' => 'BOUTIQUE',
            'to' => ['0700000000', '+2250500000000'],
            'text' => 'Votre commande est prête ✅',
            'reference' => 'commande-1234',
            'schedule_at' => '2026-10-12T08:00:00Z',
            'callback_url' => 'https://exemple.ci/hooks/sms',
        ], $this->transport->lastJson());
        // JSON compact, Unicode et barres obliques non échappés.
        self::assertStringContainsString('"text":"Votre commande est prête ✅"', (string) $request->body);
        self::assertStringContainsString('"callback_url":"https://exemple.ci/hooks/sms"', (string) $request->body);
    }

    public function testSendMinimalBodyKeepsSingleRecipientAsString(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted());

        $client->sms->send(to: '0700000000', text: 'Bonjour');

        self::assertSame('{"to":"0700000000","text":"Bonjour"}', $this->transport->last()->body);
    }

    public function testScheduleAtIsConvertedToUtc(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted())->push(202, self::submitted());

        $client->sms->send(to: '07', text: 'x', scheduleAt: new DateTimeImmutable('2026-10-12T10:00:00+02:00'));
        self::assertSame('2026-10-12T08:00:00Z', $this->transport->lastJson()['schedule_at']);

        $client->sms->send(to: '07', text: 'x', scheduleAt: '2026-10-12T08:00:00+00:00');
        self::assertSame('2026-10-12T08:00:00+00:00', $this->transport->lastJson()['schedule_at'], 'Une chaîne est envoyée telle quelle.');
    }

    public function testSendMapsTheResponse(): void
    {
        $client = $this->client();
        $this->transport->push(202, ['data' => [
            'messages' => [['id' => 'abc', 'to' => '+2250700000000', 'status' => 'queued', 'segments' => 2, 'carrier' => 'orange', 'reference' => 'r1']],
            'rejected' => [['to' => '12', 'reason' => 'invalid_number']],
            'segments' => 2,
            'cost' => ['national' => 2, 'international' => 0],
            'test' => false,
        ]]);

        $result = $client->sms->send(to: ['0700000000', '12'], text: 'Bonjour');

        self::assertCount(1, $result->messages);
        self::assertInstanceOf(AcceptedMessage::class, $result->messages[0]);
        self::assertSame('abc', $result->messages[0]->id);
        self::assertSame('+2250700000000', $result->messages[0]->to);
        self::assertSame(2, $result->messages[0]->segments);
        self::assertSame('orange', $result->messages[0]->carrier);
        self::assertSame('r1', $result->messages[0]->reference);
        self::assertSame('12', $result->rejected[0]->to);
        self::assertSame('invalid_number', $result->rejected[0]->reason);
        self::assertSame(2, $result->segments);
        self::assertSame(2, $result->cost->national);
        self::assertSame(0, $result->cost->international);
        self::assertFalse($result->test);
        self::assertFalse($result->idempotentReplayed);
        self::assertSame(['abc'], $result->ids());
    }

    public function testIdempotentReplayedHeader(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted(), ['Idempotent-Replayed' => 'true']);

        $result = $client->sms->send(to: '07', text: 'x', idempotencyKey: 'commande-1234');

        self::assertTrue($result->idempotentReplayed);
        self::assertSame('commande-1234', $this->transport->last()->header('Idempotency-Key'));
    }

    public function testSendOtpSetsTypeOtp(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted());

        $client->sms->sendOtp('0700000000', 'Votre code est 4821', from: 'BOUTIQUE');

        self::assertSame(['from' => 'BOUTIQUE', 'to' => '0700000000', 'text' => 'Votre code est 4821', 'type' => 'otp'], $this->transport->lastJson());
    }

    public function testTypeAcceptsEnumOrString(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted())->push(202, self::submitted());

        $client->sms->send(to: '07', text: 'x', type: SmsType::Standard);
        self::assertSame('standard', $this->transport->lastJson()['type']);

        $client->sms->send(to: '07', text: 'x', type: 'otp');
        self::assertSame('otp', $this->transport->lastJson()['type']);
    }

    public function testSendBatchSerializesMessages(): void
    {
        $client = $this->client();
        $this->transport->push(202, self::submitted(2));

        $client->sms->sendBatch(
            messages: [
                ['to' => '0700000000', 'text' => 'Bonjour Awa', 'reference' => 'a1'],
                ['to' => '0500000000', 'text' => 'Bonjour Koffi', 'reference' => null],
            ],
            from: 'BOUTIQUE',
            callbackUrl: 'https://exemple.ci/hooks/sms',
            scheduleAt: new DateTimeImmutable('2026-10-12T08:00:00Z'),
        );

        self::assertSame('https://api.prosms.test/v1/sms/batch', $this->transport->last()->url);
        self::assertSame([
            'from' => 'BOUTIQUE',
            'messages' => [
                ['to' => '0700000000', 'text' => 'Bonjour Awa', 'reference' => 'a1'],
                ['to' => '0500000000', 'text' => 'Bonjour Koffi'],
            ],
            'schedule_at' => '2026-10-12T08:00:00Z',
            'callback_url' => 'https://exemple.ci/hooks/sms',
        ], $this->transport->lastJson());
        self::assertNotNull($this->transport->last()->header('Idempotency-Key'));
    }

    public function testClientSideValidationHappensBeforeAnyRequest(): void
    {
        $client = $this->client();
        $cases = [
            fn () => $client->sms->send(to: [], text: 'x'),
            fn () => $client->sms->send(to: [7], text: 'x'),
            fn () => $client->sms->send(to: '07', text: 'x', idempotencyKey: ''),
            fn () => $client->sms->sendBatch(messages: []),
            fn () => $client->sms->sendBatch(messages: [['to' => '07']]),
            fn () => $client->sms->get(''),
            fn () => $client->sms->list(perPage: 101),
            fn () => $client->sms->list(perPage: 0),
            fn () => $client->sms->listAll(perPage: 500),
        ];
        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail("Cas n°{$i} accepté à tort.");
            } catch (InvalidArgumentError) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->transport->requests);
    }

    public function testGetMapsAMessageWithDates(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => self::message()]);

        $message = $client->sms->get('0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03');

        self::assertSame('https://api.prosms.test/v1/sms/0192f1b2-7c3e-7a10-9d4b-5e8f2a6c1d03', $this->transport->last()->url);
        self::assertInstanceOf(Message::class, $message);
        self::assertSame('BOUTIQUE', $message->from);
        self::assertSame('delivered', $message->status);
        self::assertSame(MessageStatus::Delivered, $message->statusEnum());
        self::assertTrue($message->isFinal());
        self::assertTrue($message->test);
        self::assertNull($message->error);
        self::assertNull($message->scheduledAt);
        self::assertNull($message->failedAt);
        self::assertSame('2026-10-11T09:30:04+00:00', $message->deliveredAt?->format(DATE_ATOM));
        self::assertSame('2026-10-11T09:30:00+00:00', $message->createdAt?->format(DATE_ATOM));
    }

    public function testGetEncodesTheIdentifier(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => self::message()]);

        $client->sms->get('a/b?c');

        self::assertSame('https://api.prosms.test/v1/sms/a%2Fb%3Fc', $this->transport->last()->url);
    }

    public function testUnknownStatusIsKeptRaw(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => ['status' => 'expired'] + self::message()]);

        $message = $client->sms->get('id');

        self::assertSame('expired', $message->status);
        self::assertNull($message->statusEnum());
        self::assertFalse($message->isFinal());
    }

    public function testListSerializesFilters(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => [self::message()], 'meta' => ['per_page' => 20, 'next_cursor' => 'abc', 'prev_cursor' => null]]);

        $page = $client->sms->list(
            status: MessageStatus::Delivered,
            reference: 'commande 1234',
            to: '0700',
            fromDate: new DateTimeImmutable('2026-10-01'),
            toDate: '2026-10-11',
            perPage: 20,
            cursor: 'xyz',
        );

        self::assertSame(
            'https://api.prosms.test/v1/sms?status=delivered&reference=commande%201234&to=0700&from_date=2026-10-01&to_date=2026-10-11&per_page=20&cursor=xyz',
            $this->transport->last()->url,
        );
        self::assertCount(1, $page);
        self::assertSame('abc', $page->nextCursor);
        self::assertNull($page->prevCursor);
        self::assertSame(20, $page->perPage);
        self::assertTrue($page->hasMore());
        self::assertSame([$page->data[0]], iterator_to_array($page));
    }

    public function testListWithoutFiltersHasNoQueryString(): void
    {
        $client = $this->client();
        $this->transport->push(200, ['data' => [], 'meta' => ['per_page' => 50, 'next_cursor' => null, 'prev_cursor' => null]]);

        $page = $client->sms->list();

        self::assertSame('https://api.prosms.test/v1/sms', $this->transport->last()->url);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->hasMore());
    }

    public function testBalanceAndSenders(): void
    {
        $client = $this->client();
        $this->transport
            ->push(200, ['data' => ['national' => 12000, 'international' => 300]])
            ->push(200, ['data' => [['name' => 'PROSMS', 'is_default' => true], ['name' => 'BOUTIQUE', 'is_default' => false]]]);

        $balance = $client->balance->get();
        self::assertSame(12000, $balance->national);
        self::assertSame(300, $balance->international);

        $senders = $client->senders->list();
        self::assertSame('https://api.prosms.test/v1/senders', $this->transport->last()->url);
        self::assertCount(2, $senders);
        self::assertInstanceOf(Sender::class, $senders[0]);
        self::assertSame('PROSMS', $senders[0]->name);
        self::assertTrue($senders[0]->isDefault);
        self::assertFalse($senders[1]->isDefault);
    }
}
