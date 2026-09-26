<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Request;

use DateTime;
use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\InstantMessageException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestManyToMany;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Request\RequestOneToMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The request shape comes from chapter 9.3.8 of SMS API v3.1.15: one root object with the
 * integration defaults and an array of messages, each of them signed on its own the way
 * chapter 9.2.2 signs a standalone message.
 */
#[CoversClass(RequestManyToMany::class)]
final class RequestManyToManyTest extends TestCase
{
    private const string IDENTIFIER = '21C334C7-CD7E-45D7-AA88-FC9C58A7FF47';
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string OTHER_IDENTIFIER = 'F0A2E9B1-6D44-4C7A-9E31-2B8C5D0A7E63';
    private const string SENDER = 'RZi';
    private const string OTHER_SENDER = 'Iny';
    private const string SIGNATURE_ONE = 'e85abc6a030cbb90fe4f0890c36eea9ccf285e22';
    private const string SIGNATURE_TWO = 'f12927108007d3ebf527b728efce1bc931c40b96';
    private const string SIGNATURE_TWO_SECOND = '55cc62e6b35f6d3b3c2398a89908bee24b89f3c3';
    private const string SIGNATURE_THREE = '1e97f252e17457ba64732d82de441dcb5ab7dfc4';
    private const string VIBER_SIGNATURE_ONE = 'cdc7e4059fb098c1503ba969d8e1b84a8197934e';

    /**
     * A transaction is not built for one message and is never given a message identifier, while
     * every other request is. Reading it off the interface used to be a fatal Error over a typed
     * property that was never written to — the very thing every other reader of this class guards
     * against — so a caller walking the request collection of a group send could not ask.
     * @return void
     */
    public function testATransactionHasNoMessageIdentifierOfItsOwn(): void
    {
        self::assertNull($this->request()->getMessageId());
    }

    public function testTheRootObjectCarriesTheDefaultsAndEveryMessage(): void
    {
        $request = $this->request();
        $request->setDefaultSenderName(self::SENDER);

        self::assertSame([
            'iid' => self::INTEGRATION_ID,
            'dflgs' => 0,
            'dsndr' => self::SENDER,
            'rsp' => 'full',
            'msgs' => [
                [
                    'sgn' => self::SIGNATURE_ONE,
                    'rcpt' => 421903622237,
                    'txt' => 'Prva sprava',
                ],
                [
                    'sgn' => self::SIGNATURE_TWO,
                    'rcpt' => 421902121212,
                    'flgs' => 1,
                    'txt' => 'Druha sprava',
                ],
                [
                    'sgn' => self::SIGNATURE_TWO_SECOND,
                    'rcpt' => 420766121212,
                    'flgs' => 1,
                    'txt' => 'Druha sprava',
                ],
                [
                    'sgn' => self::SIGNATURE_THREE,
                    'rcpt' => 421905000111,
                    'sndr' => self::OTHER_SENDER,
                    'txt' => 'Tretia sprava',
                ],
            ],
        ], $request->getData());
    }

    /**
     * Every entry is signed from its own sender, its one number and its own text, so two entries
     * of one group never share a signature — not even two written out of the same message.
     */
    public function testEveryMessageIsSignedOnItsOwn(): void
    {
        $signatures = array_column($this->request()->getData()['msgs'], 'sgn');

        self::assertSame([self::SIGNATURE_ONE, self::SIGNATURE_TWO, self::SIGNATURE_TWO_SECOND, self::SIGNATURE_THREE], $signatures);
        self::assertCount(4, array_unique($signatures));
    }

    /**
     * An entry of a transaction is addressed to exactly one number. The gateway answers "rcpts"
     * inside an entry with NO_RCPT — in an answer whose code still reads ENQUEUED, so the message
     * was dropped without a failure — and a list under "rcpt" with ERR_OTHER. A message handed
     * over for several recipients is written out once for each of them.
     */
    public function testAMessageForSeveralRecipientsIsWrittenOutOncePerNumber(): void
    {
        $messages = $this->request()->getData()['msgs'];

        self::assertSame([421903622237, 421902121212, 420766121212, 421905000111], array_column($messages, 'rcpt'));

        foreach ($messages as $message) {
            self::assertArrayNotHasKey('rcpts', $message);
            self::assertIsInt($message['rcpt']);
        }
    }

    /**
     * Flags of its own are written out, otherwise a message would silently take over the ones of
     * the whole group. Only flags that are the default ones anyway are left to the root.
     */
    public function testTheFlagsOfAMessageAreWrittenOutWhenTheyDifferFromTheDefaultOnes(): void
    {
        $request = $this->request();
        $flag = new Flag;
        $flag->addReceipt();
        $request->setDefaultFlag($flag);

        $data = $request->getData();

        self::assertSame(1, $data['dflgs']);
        self::assertSame(0, $data['msgs'][0]['flgs']);
        self::assertArrayNotHasKey('flgs', $data['msgs'][1]);
    }

    /**
     * Chapter 9.3.1 does not list "sch" among the fields of a message of a group, and neither
     * the schedule nor the time to live of a standalone request may leak into it.
     */
    public function testAMessageOfAGroupIsNeitherScheduledNorGivenATimeToLive(): void
    {
        $message = new RequestOneToMany($this->config());
        $message->setSenderName(self::SENDER);
        $message->setRecipientCollection($this->recipients([421903622237]));
        $message->setContent('Prva sprava', new Flag);
        $message->setTtl(600);
        $message->setScheduleDateTime(new DateTime('2018-01-10 12:30'));

        $request = new RequestManyToMany($this->config());
        $request->addRequest($message);

        self::assertSame(['sgn', 'rcpt', 'sndr', 'txt'], array_keys($request->getData()['msgs'][0]));
    }

    public function testAGroupWithoutASenderOfItsOwnLeavesTheDefaultOut(): void
    {
        self::assertArrayNotHasKey('dsndr', $this->request()->getData());
    }

    public function testAGroupWithoutASingleMessageIsRefused(): void
    {
        $this->expectException(RequestException::class);

        (new RequestManyToMany($this->config()))->getData();
    }

    /**
     * A message signs itself, chapter 9.2.2, without building its own request, and the signature
     * it hands out is exactly the one its body carries.
     */
    public function testAMessageSignsItselfWithoutBuildingItsOwnRequest(): void
    {
        $message = new RequestOneToMany($this->config());
        $message->setSenderName(self::SENDER);
        $message->setRecipientCollection($this->recipients([421902121212, 420766121212]));
        $message->setContent('Druha sprava', new Flag);

        $signed = $message->sign();

        self::assertSame($signed, $message->getSign());
        self::assertSame($message->getData()['sgn'], $signed);
    }

    /**
     * The one message a single request carries signs the same string a list of one does, so both
     * are signed by the same code and answer alike.
     */
    public function testASingleRecipientSignsTheSameWayAListOfOneDoes(): void
    {
        $one = new RequestOne($this->config());
        $one->setSenderName(self::SENDER);
        $one->setRecipient(new Recipient('+421903622237'));
        $one->setContent('Prva sprava', new Flag);

        $list = new RequestOneToMany($this->config());
        $list->setSenderName(self::SENDER);
        $list->setRecipientCollection($this->recipients([421903622237]));
        $list->setContent('Prva sprava', new Flag);

        self::assertSame($list->sign(), $one->sign());
    }

    /**
     * Signing is still refused for what cannot be signed: the sender is part of the string
     * chapter 9.2.2 composes, so a message that names none has nothing to sign.
     */
    public function testAMessageWithNoSenderCannotSignItself(): void
    {
        $message = new RequestOneToMany($this->config());
        $message->setRecipientCollection($this->recipients([421902121212]));
        $message->setContent('Druha sprava', new Flag);

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NOT_DEFINED);

        $message->sign();
    }

    public function testEveryNumberOfTheGroupIsListedInTheOrderTheMessagesWereAdded(): void
    {
        self::assertSame([
            421903622237,
            421902121212,
            420766121212,
            421905000111,
        ], $this->request()->getRecipients());
    }

    /**
     * Chapter 9.2: the key of a recipient travels under "f" beside the number of its entry. The
     * object of a one-to-many list is answered with ERR_OTHER inside a transaction. Every entry
     * keeps the signature it had without the key, because chapter 9.2.2 composes it out of the
     * number.
     */
    public function testAMessageOfAGroupNamesTheKeyOfItsRecipientBesideTheNumber(): void
    {
        $one = new RequestOne($this->config());
        $one->setSenderName(self::SENDER);
        $one->setRecipient(new Recipient('+421903622237', RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER));
        $one->setContent('Prva sprava', new Flag);

        $collection = new RecipientCollection;
        $collection[] = new Recipient('+421902121212', RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::OTHER_IDENTIFIER);
        $collection[] = new Recipient('+420766121212');

        $two = new RequestOneToMany($this->config());
        $two->setSenderName(self::SENDER);
        $two->setRecipientCollection($collection);
        $two->setContent('Druha sprava', new Flag);

        $request = new RequestManyToMany($this->config());
        $request->addRequest($one);
        $request->addRequest($two);

        $messages = $request->getData()['msgs'];

        self::assertSame(['sgn', 'rcpt', 'f', 'sndr', 'txt'], array_keys($messages[0]));
        self::assertSame(421903622237, $messages[0]['rcpt']);
        self::assertSame(self::IDENTIFIER, $messages[0]['f']);
        self::assertSame(421902121212, $messages[1]['rcpt']);
        self::assertSame(self::OTHER_IDENTIFIER, $messages[1]['f']);
        self::assertSame(420766121212, $messages[2]['rcpt']);
        self::assertArrayNotHasKey('f', $messages[2]);
        self::assertSame([self::SIGNATURE_ONE, self::SIGNATURE_TWO, self::SIGNATURE_TWO_SECOND], array_column($messages, 'sgn'));
        self::assertSame([421903622237, 421902121212, 420766121212], $request->getRecipients());
    }

    public function testTheGroupKnowsHowManyMessagesItCarries(): void
    {
        self::assertSame(3, $this->request()->getMessageCount());
        self::assertCount(3, $this->request()->getRequests());
    }

    /**
     * The gateway answers "dimsndr" and "dimttl" with WRONG_KEY, so the Viber defaults of the
     * transaction never reach its root.
     */
    public function testTheRootCarriesNoViberDefaults(): void
    {
        $request = $this->request();
        $request->setDefaultInstantMessage($this->instantMessage());

        $data = $request->getData();

        self::assertArrayNotHasKey('dimsndr', $data);
        self::assertArrayNotHasKey('dimttl', $data);
    }

    /**
     * The root carries no default text, so the text of the variant handed over as the default
     * is not written out next to the sender and the time to live.
     */
    public function testTheRootDefaultsCarryNoTextOfTheirOwn(): void
    {
        $request = $this->request();
        $request->setDefaultInstantMessage($this->instantMessage());

        self::assertArrayNotHasKey('msg', $request->getData());
    }

    public function testAGroupWithoutViberDefaultsLeavesThemOut(): void
    {
        $data = $this->request()->getData();

        self::assertArrayNotHasKey('dimsndr', $data);
        self::assertArrayNotHasKey('dimttl', $data);
    }

    /**
     * A message that names a variant of its own carries it whole, the sender and the time to live
     * it does not name taken from the defaults of the transaction, and the messages that name none
     * carry no "im" object at all.
     */
    public function testAMessageCarriesItsOwnViberVariantCompletedByTheDefaults(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setContent('Prva sprava cez Viber');

        $request = $this->request();
        $request->setDefaultInstantMessage($this->instantMessage());
        $request->getRequests()[0]->setInstantMessage($instantMessage);

        $messages = $request->getData()['msgs'];

        self::assertSame(['sndr' => 'RZi Viber', 'ttl' => 600, 'msg' => 'Prva sprava cez Viber'], $messages[0]['im']);
        self::assertArrayNotHasKey('im', $messages[1]);
    }

    /**
     * An entry asking to go out over Viber is signed over its own number, its text and the Viber
     * text after it, the same way a single message is.
     */
    public function testAnEntryAskingForViberSignsTheViberTextAfterItsOwn(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setContent('Prva sprava cez Viber');

        $flag = new Flag;
        $flag->addViber();

        $one = new RequestOne($this->config());
        $one->setSenderName(self::SENDER);
        $one->setRecipient(new Recipient('+421903622237'));
        $one->setContent('Prva sprava', $flag);
        $one->setInstantMessage($instantMessage);

        $request = new RequestManyToMany($this->config());
        $request->addRequest($one);

        self::assertSame(self::VIBER_SIGNATURE_ONE, $request->getData()['msgs'][0]['sgn']);
    }

    /**
     * The default of the group is not written into the messages, otherwise a message would
     * carry a Viber sender it never asked for.
     */
    public function testTheRootDefaultsDoNotLeakIntoTheMessages(): void
    {
        $request = $this->request();
        $request->setDefaultInstantMessage($this->instantMessage());

        foreach ($request->getData()['msgs'] as $message) {
            self::assertArrayNotHasKey('im', $message);
        }
    }

    /**
     * The name the whole transaction falls back to is the same field one message names under
     * "sndr", chapters 9.2.1 and 9.3.8, so it is answered for the same way: refused rather than
     * cut. It used to be cut to eleven characters, and the transaction then went out under
     * something the caller never wrote.
     */
    public function testTheDefaultSenderNameIsRefusedRatherThanCut(): void
    {
        $request = $this->request();

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_TOO_LONG);

        $request->setDefaultSenderName(str_repeat('S', 20));
    }

    /**
     * Diacritics are outside the set chapter 9.2.1 lists here for exactly the reason they are
     * outside it on a message: a name the gateway cannot write may take the delivery of every
     * message of the transaction down with it.
     */
    public function testADefaultSenderNameWithDiacriticsIsRefused(): void
    {
        $request = $this->request();

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_INVALID);

        $request->setDefaultSenderName('Zahradníc');
    }

    /**
     * A name the chapter does allow is kept as it was written and reaches the body under "dsndr".
     */
    public function testADefaultSenderNameOutOfTheAllowedSetIsKept(): void
    {
        $request = $this->request();
        $request->setDefaultSenderName('My-Shop v.2');

        self::assertSame('My-Shop v.2', $request->getData()['dsndr']);
    }

    public function testTheBodyPutOnTheWireIsTheDataItself(): void
    {
        $request = $this->request();

        self::assertSame($request->getData(), $request->jsonSerialize());
    }

    /**
     * The transaction itself is a wrapper and has nothing of its own to say, so everything it has
     * to tell the caller comes from the messages inside it — and both questions have to be
     * answered off the same list. Answering hasWarnings() out of the wrapper's own empty list said
     * no about a transaction whose getWarnings() listed a text of five segments, and a caller
     * asking the cheap question first never read the answer.
     */
    public function testTheTransactionReportsTheWarningsOfTheMessagesItCarries(): void
    {
        $long = new RequestOneToMany($this->config());
        $long->setSenderName(self::SENDER);
        $long->setRecipientCollection($this->recipients([421903622237]));
        $long->setContent(str_repeat('a', 613), new Flag);

        $request = new RequestManyToMany($this->config());
        $request->addRequest($long);

        self::assertCount(1, $request->getWarnings());
        self::assertTrue($request->hasWarnings());
    }

    /**
     * A transaction whose every message fits says nothing, so an empty list and a plain no are
     * the answers a send that is fine to make gets.
     */
    public function testATransactionOfMessagesThatFitSaysNothing(): void
    {
        $request = $this->request();

        self::assertSame([], $request->getWarnings());
        self::assertFalse($request->hasWarnings());
    }

    /**
     * @return RequestManyToMany
     * @throws RecipientException
     * @throws RequestException
     */
    private function request(): RequestManyToMany
    {
        $receipt = new Flag;
        $receipt->addReceipt();

        $one = new RequestOne($this->config());
        $one->setSenderName(self::SENDER);
        $one->setRecipient(new Recipient('+421903622237'));
        $one->setContent('Prva sprava', new Flag);

        $two = new RequestOneToMany($this->config());
        $two->setSenderName(self::SENDER);
        $two->setRecipientCollection($this->recipients([421902121212, 420766121212]));
        $two->setContent('Druha sprava', $receipt);

        $three = new RequestOneToMany($this->config());
        $three->setSenderName(self::OTHER_SENDER);
        $three->setRecipientCollection($this->recipients([421905000111]));
        $three->setContent('Tretia sprava', new Flag);

        $request = new RequestManyToMany($this->config());
        $request->addRequest($one);
        $request->addRequest($two);
        $request->addRequest($three);

        return $request;
    }

    /**
     * @return InstantMessage
     * @throws InstantMessageException
     */
    private function instantMessage(): InstantMessage
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName('RZi Viber');
        $instantMessage->setTtl(600);
        $instantMessage->setContent('Testovacia sprava cez Viber');

        return $instantMessage;
    }

    /**
     * @param int[] $numbers
     * @return RecipientCollection
     * @throws RecipientException
     */
    private function recipients(array $numbers): RecipientCollection
    {
        $collection = new RecipientCollection;

        foreach ($numbers as $number) {
            $collection[] = new Recipient('+' . $number);
        }

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
