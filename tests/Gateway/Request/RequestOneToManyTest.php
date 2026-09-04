<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Request;

use DateTime;
use DateTimeZone;
use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Request\RequestManyToMany;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Request\RequestOneToMany;
use EuroSms\Gateway\Request\RequestRecipientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The request shape comes from chapter 9.3.3 of SMS API v3.1.15. The signature is the worked
 * multi-recipient example of chapter 9.2.2: the recipients are concatenated in the order they
 * are listed, between the sender and the text.
 */
#[CoversClass(RequestOneToMany::class)]
final class RequestOneToManyTest extends TestCase
{
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string IDENTIFIER = '21C334C7-CD7E-45D7-AA88-FC9C58A7FF47';
    private const string SENDER = 'RZi';
    private const string TEXT = 'Testovacia sprava';
    private const string SIGNATURE = 'fa02681d79dfe591300a7f181837a58d630be5ad';

    public function testBodyCarriesEveryFieldOfTheDocumentedGroupRequest(): void
    {
        $request = $this->request();
        $request->setTtl(600);
        $request->setScheduleDateTime(new DateTime('2018-01-10 12:30', new DateTimeZone('Europe/Bratislava')));

        self::assertSame([
            'iid' => self::INTEGRATION_ID,
            'rsp' => 'full',
            'sgn' => self::SIGNATURE,
            'rcpts' => [421903622237, 420766121212],
            'flgs' => 1,
            'ttl' => 600,
            'sndr' => self::SENDER,
            'start' => '2018-01-10 12:30',
            'txt' => self::TEXT,
        ], $request->getData());
    }

    /**
     * Chapter 9.3.3: a message addressed to more than one number names them all at once,
     * under "rcpts" rather than under the "rcpt" of a single one.
     */
    public function testEveryNumberIsNamedTheWayAMessageOfAGroupNamesThem(): void
    {
        self::assertSame(['rcpts' => [421903622237, 420766121212]], $this->request()->getRecipientData());
    }

    public function testARequestWithoutRecipientsNamesNone(): void
    {
        $this->expectException(RecipientException::class);

        (new RequestOneToMany($this->config()))->getRecipientData();
    }

    public function testSignatureCoversEveryRecipientInTheOrderTheyAreListed(): void
    {
        $request = $this->request();
        $request->getData();

        self::assertSame(self::SIGNATURE, $request->getSign());
    }

    /**
     * Chapter 9.2: a recipient the calling system knows by a key of its own is named as an object,
     * and the two shapes stand side by side in the one list — a request is not made to choose
     * between naming every recipient by a key and naming none of them.
     */
    public function testAKeyedAndAPlainRecipientStandSideBySideInOneList(): void
    {
        self::assertSame([
            'rcpts' => [
                ['r' => 421903622237, 'f' => self::IDENTIFIER],
                420766121212,
            ],
        ], $this->mixedRequest()->getRecipientData());
    }

    public function testTheMixedListIsWhatTheBodyCarries(): void
    {
        self::assertSame([
            ['r' => 421903622237, 'f' => self::IDENTIFIER],
            420766121212,
        ], $this->mixedRequest()->getData()['rcpts']);
    }

    /**
     * Chapter 9.2.2 composes the signature out of the numbers alone, in the order they are listed,
     * so the keys leave it exactly where it was.
     */
    public function testTheKeysOfTheRecipientsLeaveTheSignatureAlone(): void
    {
        $request = $this->mixedRequest();
        $request->getData();

        self::assertSame(self::SIGNATURE, $request->getSign());
    }

    public function testRecipientsAreExposedAsPlainNumbers(): void
    {
        self::assertSame([421903622237, 420766121212], $this->request()->getRecipients());
    }

    /**
     * The numbers are what reports a request back, so they stay plain whatever key a recipient
     * carries; the keys are read off the recipients themselves.
     */
    public function testKeyedRecipientsAreStillExposedAsPlainNumbers(): void
    {
        $request = $this->mixedRequest();

        self::assertSame([421903622237, 420766121212], $request->getRecipients());
        self::assertSame(
            [self::IDENTIFIER, null],
            array_map(
                static fn (Recipient $recipient): ?string => $recipient->getIdentifier(),
                array_values($request->getRecipientCollection()->all())
            )
        );
    }

    public function testTheRecipientsOfARequestThatGotNoneAreEmpty(): void
    {
        self::assertCount(0, (new RequestOneToMany($this->config()))->getRecipientCollection());
    }

    /**
     * The collection is cloned on the way in, so a recipient added to it afterwards does not
     * reach a request that was already built from it.
     */
    public function testARecipientAddedAfterwardsDoesNotReachTheRequest(): void
    {
        $collection = $this->recipients();

        $request = new RequestOneToMany($this->config());
        $request->setRecipientCollection($collection);
        $collection[] = new Recipient('+421905000111');

        self::assertSame([421903622237, 420766121212], $request->getRecipients());
    }

    /**
     * A request that never got its numbers lists none, rather than blowing up on the very path
     * that reports a request the gateway never answered.
     */
    public function testRecipientsAreEmptyUntilTheyAreSet(): void
    {
        self::assertSame([], (new RequestOneToMany($this->config()))->getRecipients());
    }

    public function testBodyWithoutRecipientsIsRefused(): void
    {
        $request = new RequestOneToMany($this->config());
        $request->setSenderName(self::SENDER);
        $request->setContent(self::TEXT, new Flag);

        $this->expectException(RecipientException::class);

        $request->getData();
    }

    public function testBodyWithoutASenderNameIsRefused(): void
    {
        $request = new RequestOneToMany($this->config());
        $request->setRecipientCollection($this->recipients());
        $request->setContent(self::TEXT, new Flag);

        $this->expectException(MessageException::class);

        $request->getData();
    }

    /**
     * A group request carries the Viber variant the same way a standalone one does, chapter 10.2.1.
     */
    public function testTheViberVariantIsWrittenIntoTheGroupRequest(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName('RZi Viber');
        $instantMessage->setTtl(600);
        $instantMessage->setContent('Testovacia sprava cez Viber');

        $request = $this->request();
        $request->setInstantMessage($instantMessage);

        self::assertSame([
            'sndr' => 'RZi Viber',
            'ttl' => 600,
            'msg' => 'Testovacia sprava cez Viber',
        ], $request->getData()['im']);
    }

    public function testAGroupRequestWithoutAViberVariantCarriesNoImObject(): void
    {
        self::assertArrayNotHasKey('im', $this->request()->getData());
    }

    /**
     * A field that carries nothing is left out of the body, but a text of "0" carries something:
     * it is the whole message, and the signature was composed over it. Dropping it would post a
     * request whose own signature does not match what it says.
     * @return void
     */
    public function testAMessageWhoseWholeTextIsZeroStillCarriesIt(): void
    {
        $request = new RequestOneToMany($this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipientCollection($this->recipients());
        $request->setContent('0', new Flag);

        $data = $request->getData();

        self::assertArrayHasKey('txt', $data);
        self::assertSame('0', $data['txt']);
        self::assertSame($request->getSign(), $data['sgn']);
    }

    /**
     * The flags are the one field that really is left out when they are the plain default of
     * zero, chapter 9.3.3 — that has to survive the text of "0" being kept.
     * @return void
     */
    public function testTheDefaultFlagsAreStillLeftOutOfTheBody(): void
    {
        $request = new RequestOneToMany($this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipientCollection($this->recipients());
        $request->setContent(self::TEXT, new Flag);

        self::assertArrayNotHasKey('flgs', $request->getData());
    }

    /**
     * Chapter 9.3.3 states a request of its own, not a variation on the single one: a group
     * request neither is a RequestOne nor inherits its single-recipient methods. The three
     * requests only share what a request as such carries.
     * @return void
     */
    public function testAGroupRequestIsNoKindOfASingleOne(): void
    {
        self::assertFalse(is_subclass_of(RequestOneToMany::class, RequestOne::class));
        self::assertFalse(is_subclass_of(RequestManyToMany::class, RequestOne::class));
        self::assertFalse(is_subclass_of(RequestManyToMany::class, RequestOneToMany::class));
    }

    /**
     * A single recipient is handed out only where there is one, so the request that names a
     * whole list of them does not answer for it at all.
     * @return void
     */
    public function testAGroupRequestHasNoSingleRecipientToHandOut(): void
    {
        self::assertFalse(method_exists(RequestOneToMany::class, 'getRecipient'));
        self::assertFalse(method_exists(RequestOneToMany::class, 'setRecipient'));
        self::assertNotInstanceOf(RequestRecipientInterface::class, $this->request());
        self::assertInstanceOf(RequestRecipientInterface::class, new RequestOne($this->config()));
    }

    /**
     * Whatever the three requests do share is stated by the one interface all of them implement.
     * @return void
     */
    public function testEveryRequestImplementsTheOneCommonInterface(): void
    {
        self::assertInstanceOf(RequestInterface::class, new RequestOne($this->config()));
        self::assertInstanceOf(RequestInterface::class, $this->request());
        self::assertInstanceOf(RequestInterface::class, new RequestManyToMany($this->config()));
    }

    /**
     * @return RequestOneToMany
     * @throws RequestException
     */
    private function request(): RequestOneToMany
    {
        $flag = new Flag;
        $flag->addReceipt();

        $request = new RequestOneToMany($this->config());
        $request->setMessageId('9fbe1e6a-1f7c-4c5f-8a0d-5b6d1f2a3c44');
        $request->setSenderName(self::SENDER);
        $request->setRecipientCollection($this->recipients());
        $request->setContent(self::TEXT, $flag);

        return $request;
    }

    /**
     * The same request, addressed to one recipient carrying a key of its own and one carrying none.
     * @return RequestOneToMany
     * @throws RequestException
     */
    private function mixedRequest(): RequestOneToMany
    {
        $flag = new Flag;
        $flag->addReceipt();

        $collection = new RecipientCollection;
        $collection[] = new Recipient('+421903622237', RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER);
        $collection[] = new Recipient('+420766121212');

        $request = new RequestOneToMany($this->config());
        $request->setMessageId('9fbe1e6a-1f7c-4c5f-8a0d-5b6d1f2a3c44');
        $request->setSenderName(self::SENDER);
        $request->setRecipientCollection($collection);
        $request->setContent(self::TEXT, $flag);

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
