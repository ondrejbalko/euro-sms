<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Request;

use DateTime;
use DateTimeZone;
use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Enums\ResponseFormatEnum;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestAbstract;
use EuroSms\Gateway\Request\RequestManyToMany;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Request\RequestOneToMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parameters the root object of a request carries, chapter 9.2.1 of SMS API v3.1.15 plus the
 * "end" of the worked example of chapter 10.2.1: how much of an answer is asked for, when the
 * sending starts, how long it is spread over, when it is over and what the messages of a group
 * transaction fall back to.
 */
#[CoversClass(RequestAbstract::class)]
#[CoversClass(RequestManyToMany::class)]
#[CoversClass(RequestOne::class)]
#[CoversClass(RequestOneToMany::class)]
#[CoversClass(ResponseFormatEnum::class)]
#[CoversClass(Message::class)]
final class RootParametersTest extends TestCase
{
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string SENDER = 'RZi';
    private const string TEXT = 'Testovacia sprava';

    /**
     * The gateway itself falls back to the basic answer, chapter 9.2.1, so that is what a single
     * message asks for until the caller asks for more.
     */
    public function testASingleMessageAsksForTheConfiguredAnswer(): void
    {
        self::assertSame(ResponseFormatEnum::BASIC->value, $this->requestOne()->getData()['rsp']);
    }

    public function testTheConfiguredAnswerReachesTheBodyOfASingleMessage(): void
    {
        $config = $this->config();
        $config->setResponseFormat(ResponseFormatEnum::FULL);

        self::assertSame(ResponseFormatEnum::FULL->value, $this->requestOne($config)->getData()['rsp']);
    }

    /**
     * The "accepted" and "wrong_numbers" lists the result of a group send is read number by
     * number from only come with the full answer, chapter 9.3.4.1, so the configuration cannot
     * take them away.
     */
    public function testAGroupSendAsksForTheFullAnswerWhateverIsConfigured(): void
    {
        $config = $this->config();
        $config->setResponseFormat(ResponseFormatEnum::BASIC);

        $request = $this->requestOneToMany($config);

        self::assertSame(ResponseFormatEnum::FULL, $request->getResponseFormat());
        self::assertSame(ResponseFormatEnum::FULL->value, $request->getData()['rsp']);
    }

    /**
     * The verdict per number the result of a transaction is read from is the "result" list of
     * chapter 9.3.11, which the basic answer does not carry either.
     */
    public function testATransactionAsksForTheFullAnswerWhateverIsConfigured(): void
    {
        $config = $this->config();
        $config->setResponseFormat(ResponseFormatEnum::BASIC);

        $request = $this->requestManyToMany($config);

        self::assertSame(ResponseFormatEnum::FULL, $request->getResponseFormat());
        self::assertSame(ResponseFormatEnum::FULL->value, $request->getData()['rsp']);
    }

    public function testTheDurationOfTheSendingReachesTheGroupRequest(): void
    {
        $request = $this->requestOneToMany();
        $request->setDuration('01:00');

        self::assertSame('01:00', $request->getData()['dur']);
    }

    public function testTheDurationOfTheSendingReachesTheTransaction(): void
    {
        $request = $this->requestManyToMany();
        $request->setDuration('01:00');

        self::assertSame('01:00', $request->getData()['dur']);
    }

    public function testARequestThatSpreadsNothingCarriesNoDuration(): void
    {
        self::assertArrayNotHasKey('dur', $this->requestOneToMany()->getData());
        self::assertArrayNotHasKey('dur', $this->requestManyToMany()->getData());
    }

    /**
     * @param string $duration
     * @return void
     * @throws RequestException
     */
    #[DataProvider('malformedDurations')]
    public function testADurationWrittenAnyOtherWayNeverReachesTheGateway(string $duration): void
    {
        $this->expectException(RequestException::class);

        $this->requestOneToMany()->setDuration($duration);
    }

    /**
     * @param string $duration
     * @return void
     * @throws MessageException
     */
    #[DataProvider('malformedDurations')]
    public function testAMessageRefusesADurationWrittenAnyOtherWay(string $duration): void
    {
        $this->expectException(MessageException::class);

        (new Message)->setDuration($duration);
    }

    public function testAMessageKeepsADurationWrittenAsHoursAndMinutes(): void
    {
        $message = new Message;
        $message->setDuration('11:59');

        self::assertSame('11:59', $message->getDuration());
    }

    public function testAMessageThatSpreadsNothingNamesNoDuration(): void
    {
        self::assertNull((new Message)->getDuration());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedDurations(): array
    {
        return [
            'hours without a leading zero' => ['1:00'],
            'more minutes than an hour has' => ['01:60'],
            'no separator at all' => ['0100'],
            'seconds on top' => ['01:00:00'],
            'a moment in time' => ['2018-01-10 12:30'],
            'nothing at all' => [''],
            'a trailing newline' => ["01:00\n"],
            'a trailing carriage return and newline' => ["01:00\r\n"],
        ];
    }

    public function testTheEndOfTheSendingReachesTheGroupRequest(): void
    {
        $request = $this->requestOneToMany();
        $request->setEnd(new DateTime('2018-01-10 13:30', new DateTimeZone('Europe/Bratislava')));

        self::assertSame('2018-01-10 13:30', $request->getData()['end']);
    }

    public function testAGroupRequestWithoutAnEndCarriesNone(): void
    {
        self::assertNull($this->requestOneToMany()->getEnd());
        self::assertArrayNotHasKey('end', $this->requestOneToMany()->getData());
    }

    public function testAMessageReadsItsEndInItsOwnTimeZone(): void
    {
        $message = new Message;
        $message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
        $message->setEnd(new DateTime('2018-01-10 12:30', new DateTimeZone('UTC')));

        self::assertSame('2018-01-10 13:30', $message->getEnd()?->format('Y-m-d H:i'));
    }

    /**
     * Chapter 9.3.3 states that a group send ignores "sch", so the moment the sending starts is
     * written as "start" and nothing else.
     */
    public function testAGroupRequestIsNeverScheduledWithSch(): void
    {
        $request = $this->requestOneToMany();
        $request->setScheduleDateTime(new DateTime('2018-01-10 12:30', new DateTimeZone('Europe/Bratislava')));

        $data = $request->getData();

        self::assertArrayNotHasKey('sch', $data);
        self::assertSame('2018-01-10 12:30', $data['start']);
    }

    /**
     * Chapter 9.3.8: what a message shares with the whole transaction is stated once in the root,
     * so a message that asks for nothing of its own repeats neither the flags nor the sender.
     */
    public function testAMessageWithoutValuesOfItsOwnFallsBackToTheDefaultsOfTheTransaction(): void
    {
        $request = $this->requestManyToMany();
        $request->setDefaultSenderName(self::SENDER);

        $data = $request->getData();

        self::assertSame(0, $data['dflgs']);
        self::assertSame(self::SENDER, $data['dsndr']);
        self::assertArrayNotHasKey('flgs', $data['msgs'][0]);
        self::assertArrayNotHasKey('sndr', $data['msgs'][0]);
    }

    /**
     * A message that names no sender at all is signed with the one of the whole transaction,
     * because that is the name the gateway will send it under.
     */
    public function testAMessageWithoutASenderOfItsOwnIsSignedWithTheDefaultOne(): void
    {
        $message = new RequestOneToMany($this->config());
        $message->setRecipientCollection($this->recipients());
        $message->setContent(self::TEXT, new Flag);

        $request = new RequestManyToMany($this->config());
        $request->setDefaultSenderName(self::SENDER);
        $request->addRequest($message);

        $named = $this->requestManyToMany();
        $named->setDefaultSenderName(self::SENDER);

        $data = $request->getData();
        $expected = $named->getData();

        self::assertArrayNotHasKey('sndr', $data['msgs'][0]);
        self::assertSame($expected['msgs'][0]['sgn'], $data['msgs'][0]['sgn']);
    }

    public function testAMessageWithNeitherAnOwnNorADefaultSenderIsRefused(): void
    {
        $message = new RequestOneToMany($this->config());
        $message->setRecipientCollection($this->recipients());
        $message->setContent(self::TEXT, new Flag);

        $request = new RequestManyToMany($this->config());
        $request->addRequest($message);

        $this->expectException(MessageException::class);

        $request->getData();
    }

    /**
     * @param Config|null $config
     * @return RequestOne
     * @throws RequestException
     * @throws RecipientException
     */
    private function requestOne(?Config $config = null): RequestOne
    {
        $request = new RequestOne($config ?? $this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient('+421903622237'));
        $request->setContent(self::TEXT, new Flag);

        return $request;
    }

    /**
     * @param Config|null $config
     * @return RequestOneToMany
     * @throws RequestException
     * @throws RecipientException
     */
    private function requestOneToMany(?Config $config = null): RequestOneToMany
    {
        $request = new RequestOneToMany($config ?? $this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipientCollection($this->recipients());
        $request->setContent(self::TEXT, new Flag);

        return $request;
    }

    /**
     * @param Config|null $config
     * @return RequestManyToMany
     * @throws RequestException
     * @throws RecipientException
     */
    private function requestManyToMany(?Config $config = null): RequestManyToMany
    {
        $request = new RequestManyToMany($config ?? $this->config());
        $request->addRequest($this->requestOneToMany($config));

        return $request;
    }

    /**
     * @return RecipientCollection
     * @throws RecipientException
     */
    private function recipients(): RecipientCollection
    {
        $collection = new RecipientCollection;
        $collection[] = new Recipient('+421903622237');
        $collection[] = new Recipient('+420766121212');

        return $collection;
    }

    /**
     * @return Config
     */
    private function config(): Config
    {
        $config = new Config;
        $config->setId(self::INTEGRATION_ID);
        $config->setKey(self::INTEGRATION_KEY);

        return $config;
    }
}
