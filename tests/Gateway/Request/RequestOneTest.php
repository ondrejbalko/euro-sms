<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Request;

use DateTime;
use DateTimeZone;
use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\InstantMessageException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Request\RequestOne;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The request shape comes from chapter 9.3.1, the signature from chapters 4.2.1 and 9.2.2
 * of SMS API v3.1.15. The integration id, key, sender, recipient and text below are the ones
 * the documentation works its own example with, so the expected signature is quoted verbatim.
 */
#[CoversClass(RequestOne::class)]
final class RequestOneTest extends TestCase
{
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string IDENTIFIER = '21C334C7-CD7E-45D7-AA88-FC9C58A7FF47';
    private const string SENDER = 'RZi';
    private const string RECIPIENT = '+421903622237';
    private const string TEXT = 'Testovacia sprava';
    private const string UNICODE_TEXT = 'Ďakujeme za objednávku, tovar posielame ešte dnes a doručenie čakajte do dvoch dní.';
    private const string SIGNATURE = '6f56060b6b7db97ca25782b771cca0a65077bd5b';
    private const string VIBER_SENDER = 'RZi Viber';
    private const string VIBER_TEXT = 'Ďakujeme za objednávku, tovar posielame ešte dnes.';

    public function testBodyCarriesEveryFieldOfTheDocumentedOneRequest(): void
    {
        $request = $this->request();
        $request->setTtl(600);
        $request->setScheduleDateTime(new DateTime('2018-01-10 12:30', new DateTimeZone('Europe/Bratislava')));

        self::assertSame([
            'iid' => self::INTEGRATION_ID,
            'rsp' => 'basic',
            'sgn' => self::SIGNATURE,
            'rcpt' => 421903622237,
            'flgs' => 1,
            'ttl' => 600,
            'sndr' => self::SENDER,
            'sch' => '2018-01-10 12:30',
            'txt' => self::TEXT,
        ], $request->getData());
    }

    /**
     * Chapter 9.3.1: a message of a group transaction names the one number it is addressed to
     * under "rcpt", the same field a standalone request writes into its own body.
     */
    public function testTheOneNumberIsNamedTheWayAMessageOfAGroupNamesIt(): void
    {
        self::assertSame(['rcpt' => 421903622237], $this->request()->getRecipientData());
    }

    public function testARequestWithoutARecipientNamesNone(): void
    {
        $this->expectException(RecipientException::class);

        (new RequestOne($this->config()))->getRecipientData();
    }

    /**
     * Asked for a recipient it was never given, a request says so the way every other path of it
     * says it, rather than blowing up on a property that was never written to.
     */
    public function testARequestWithoutARecipientHasNoneToHandOut(): void
    {
        $this->expectException(RecipientException::class);
        $this->expectExceptionCode(RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);

        (new RequestOne($this->config()))->getRecipient();
    }

    /**
     * Chapter 9.2: a recipient the calling system knows by a key of its own is named as an object
     * pairing the number under "r" with that key under "f", instead of as the bare number.
     */
    public function testARecipientCarryingAKeyIsNamedAsAnObject(): void
    {
        self::assertSame([
            'rcpt' => ['r' => 421903622237, 'f' => self::IDENTIFIER],
        ], $this->identifiedRequest()->getRecipientData());
    }

    public function testTheObjectOfAKeyedRecipientIsWhatTheBodyCarries(): void
    {
        self::assertSame(
            ['r' => 421903622237, 'f' => self::IDENTIFIER],
            $this->identifiedRequest()->getData()['rcpt']
        );
    }

    /**
     * Chapter 9.2.2 composes the signature out of the sender, the number and the text, and the
     * key is none of the three, so a request carrying one is signed exactly as it was without it.
     */
    public function testTheKeyOfARecipientLeavesTheSignatureAlone(): void
    {
        $request = $this->identifiedRequest();
        $request->getData();

        self::assertSame(self::SIGNATURE, $request->getSign());
    }

    /**
     * What reports the request back reads numbers, so a keyed recipient is listed as the plain
     * number the rest of the library already knows it by.
     */
    public function testAKeyedRecipientIsStillListedAsAPlainNumber(): void
    {
        self::assertSame([421903622237], $this->identifiedRequest()->getRecipients());
    }

    public function testSignatureMatchesTheWorkedExampleFromTheDocumentation(): void
    {
        $request = $this->request();
        $request->getData();

        self::assertSame(self::SIGNATURE, $request->getSign());
    }

    public function testJsonSerializationIsTheRequestBody(): void
    {
        $request = $this->request();

        self::assertSame($request->getData(), $request->jsonSerialize());
    }

    /**
     * Chapter 9.2.2: an unset flgs is read by the gateway as zero, so the library leaves it out.
     */
    public function testTheDefaultFlagIsLeftOutOfTheBody(): void
    {
        $request = new RequestOne($this->config());
        $request->setMessageId('c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30');
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::TEXT, new Flag);

        self::assertArrayNotHasKey('flgs', $request->getData());
    }

    /**
     * Chapter 14.2: a message longer than seventy characters with diacritics is a long message
     * and a unicode one at once. Those are two separate bits, so the request asks for six.
     */
    public function testLongUnicodeMessageAsksForTheLongAndTheDiacriticsBit(): void
    {
        $request = $this->unicodeRequest(new Flag);

        self::assertGreaterThan(GatewayInterface::MAX_UNICODE_MESSAGE_LENGTH, mb_strlen(self::UNICODE_TEXT));
        self::assertSame(6, $request->getData()['flgs']);
    }

    public function testLongUnicodeMessageWithAReceiptAsksForSeven(): void
    {
        $flag = new Flag;
        $flag->addReceipt();

        $request = $this->unicodeRequest($flag);

        self::assertSame(7, $request->getData()['flgs']);
    }

    /**
     * The flag handed over by the caller is cloned, so building the request leaves it alone.
     */
    public function testTheFlagOfTheCallerIsNotChangedByTheRequest(): void
    {
        $flag = new Flag;
        $flag->addReceipt();

        $this->unicodeRequest($flag);

        self::assertSame(1, $flag->getValue());
    }

    /**
     * The amount is the only way to know beforehand how many messages the text falls into, and so
     * what it will cost. Asked for before there is a text, it says so instead of answering zero.
     */
    public function testTheAmountIsRefusedUntilTheTextIsSet(): void
    {
        $request = new RequestOne($this->config());

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_MESSAGE_NOT_DEFINED);

        $request->getAmount();
    }

    /**
     * A plain text holds a hundred and sixty characters in one message; anything longer is split
     * into parts of a hundred and fifty-three, the rest of each going to the concatenation header.
     */
    public function testAPlainTextFillingOneMessageCountsAsOne(): void
    {
        $request = $this->requestWithContent(str_repeat('a', GatewayInterface::MAX_MESSAGE_LENGTH));

        self::assertSame(1, $request->getAmount());
    }

    public function testAPlainTextOfTwoHundredCharactersCountsAsTwo(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 200));

        self::assertSame(2, $request->getAmount());
    }

    /**
     * A concatenated message has no full first part: the header saying which part of which message
     * it is takes its bite out of every one of them, the first included. A text that spills just
     * past two parts of a hundred and fifty-three is therefore three messages and not two.
     */
    public function testAPlainTextSpillingPastTwoPartsCountsAsThree(): void
    {
        self::assertSame(2, $this->requestWithContent(str_repeat('a', 306))->getAmount());
        self::assertSame(3, $this->requestWithContent(str_repeat('a', 307))->getAmount());
    }

    /**
     * Diacritics make the message a unicode one, and that holds seventy characters, every further
     * part sixty-seven.
     */
    public function testAUnicodeTextFillingOneMessageCountsAsOne(): void
    {
        $request = $this->requestWithContent(str_repeat('á', GatewayInterface::MAX_UNICODE_MESSAGE_LENGTH), true);

        self::assertSame(1, $request->getAmount());
    }

    public function testAUnicodeTextOfAHundredCharactersCountsAsTwo(): void
    {
        $request = $this->requestWithContent(str_repeat('á', 100), true);

        self::assertSame(2, $request->getAmount());
    }

    public function testAUnicodeTextSpillingPastTwoPartsCountsAsThree(): void
    {
        self::assertSame(2, $this->requestWithContent(str_repeat('á', 134), true)->getAmount());
        self::assertSame(3, $this->requestWithContent(str_repeat('á', 135), true)->getAmount());
    }

    /**
     * Chapter 12.1: the alphabet of an SMS is GSM 03.38, not ASCII, and a character of it is one
     * septet however many bytes it takes in UTF-8. A hundred and sixty of them are one message,
     * five of them being two bytes long or not.
     */
    public function testAGsmTextOfMultibyteCharactersStillFillsOnlyOneMessage(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 155) . str_repeat('é', 5));

        self::assertSame(1, $request->getAmount());
    }

    /**
     * Chapter 12.2: the characters of the extension table are written as an escape followed by
     * the character itself, so every one of them takes two septets. A hundred and sixty
     * characters, ten of them extended, are a hundred and seventy septets and no longer fit.
     */
    public function testAnExtendedCharacterCountsAsTwoSeptets(): void
    {
        self::assertSame(1, $this->requestWithContent(str_repeat('a', 140) . str_repeat('{', 10))->getAmount());
        self::assertSame(2, $this->requestWithContent(str_repeat('a', 150) . str_repeat('{', 10))->getAmount());
    }

    /**
     * A hundred and fifty-five plain characters and ten euro signs are a hundred and seventy-five
     * septets, which is two messages.
     */
    public function testATextOfPlainCharactersAndEuroSignsCountsAsTwo(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 155) . str_repeat('€', 10));

        self::assertSame(2, $request->getAmount());
    }

    /**
     * Asked for nothing in particular, the request works out for itself whether the text can be
     * written in GSM 03.38. One that cannot goes out with diacritics, so it holds seventy
     * characters and carries the diacritics bit of chapter 14.1 without anyone saying so.
     */
    public function testATextWithDiacriticsIsRecognisedWithoutBeingTold(): void
    {
        $request = new RequestOne($this->config());
        $request->setMessageId('c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30');
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::UNICODE_TEXT, new Flag);

        self::assertTrue($request->isUnicode());
        self::assertSame(2, $request->getAmount());
        self::assertSame(6, $request->getData()['flgs']);
    }

    /**
     * A text of the GSM alphabet is left as it is, whatever its bytes look like: it is neither
     * counted as diacritics nor charged the seventy character limit.
     */
    public function testAGsmTextIsNotTakenForDiacritics(): void
    {
        $request = $this->requestWithContent(str_repeat('è', 160));

        self::assertFalse($request->isUnicode());
        self::assertSame(1, $request->getAmount());
    }

    /**
     * A message with diacritics is counted in positions of sixteen bits, and a character above
     * U+FFFF takes two of them: thirty-five emoji fill one message and thirty-six no longer do,
     * however few characters that is.
     */
    public function testACharacterAboveTheBasicPlaneIsChargedTheTwoPositionsItTakes(): void
    {
        self::assertSame(1, $this->requestWithContent(str_repeat('😀', 35))->getAmount());
        self::assertSame(2, $this->requestWithContent(str_repeat('😀', 36))->getAmount());
    }

    /**
     * The caller keeps the last word: a text with diacritics forced into the GSM alphabet is
     * counted as one, whatever the detection would have said.
     */
    public function testForcedGsmEncodingIsNotOverruledByTheDetection(): void
    {
        $request = $this->requestWithContent(self::UNICODE_TEXT, false);

        self::assertFalse($request->isUnicode());
    }

    /**
     * Four segments are what chapter 9.2.2 recommends staying within, so four of them are
     * nothing to say anything about.
     */
    public function testATextWithinTheRecommendedSegmentsIsNotWarnedAbout(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 612));

        self::assertSame(4, $request->getAmount());
        self::assertFalse($request->hasWarnings());
        self::assertSame([], $request->getWarnings());
    }

    /**
     * One segment more and the caller is told — and the send goes out all the same. Chapter 14.1
     * says the gateway puts no limit on the number of segments, it is the operators that
     * recommend against long ones, so refusing the send here would refuse something that is
     * allowed. The gateway answering TOO_MANY_MESSAGES after the fact is what this replaces.
     */
    public function testATextOverTheRecommendedSegmentsIsWarnedAboutAndStillSent(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 613));

        self::assertSame(5, $request->getAmount());
        self::assertTrue($request->hasWarnings());
        self::assertCount(1, $request->getWarnings());
        self::assertStringContainsString('5', $request->getWarnings()[0]);
        self::assertStringContainsString(
            (string)GatewayInterface::MAX_MESSAGE_SEGMENTS,
            $request->getWarnings()[0]
        );
    }

    /**
     * A text handed over twice is one text, and the warning about it is one warning. Counting
     * them up over every call would say the send is worse than it is.
     */
    public function testTheWarningIsNotRepeatedWhenTheTextIsSetAgain(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 613));
        $request->setContent(str_repeat('b', 613), new Flag);

        self::assertCount(1, $request->getWarnings());
    }

    /**
     * A text that fits again takes the warning away with it: what is reported is the state of
     * the request, not the history of what was written into it.
     */
    public function testAShorterTextTakesTheWarningAway(): void
    {
        $request = $this->requestWithContent(str_repeat('a', 613));
        $request->setContent(str_repeat('b', 612), new Flag);

        self::assertFalse($request->hasWarnings());
    }

    public function testEmptyContentIsRefused(): void
    {
        $request = new RequestOne($this->config());

        $this->expectException(RequestException::class);

        $request->setContent('', new Flag);
    }

    public function testBodyWithoutARecipientIsRefused(): void
    {
        $request = new RequestOne($this->config());
        $request->setSenderName(self::SENDER);
        $request->setContent(self::TEXT, new Flag);

        $this->expectException(RecipientException::class);

        $request->getData();
    }

    public function testBodyWithoutASenderNameIsRefused(): void
    {
        $request = new RequestOne($this->config());
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::TEXT, new Flag);

        $this->expectException(MessageException::class);

        $request->getData();
    }

    /**
     * A request that was never given a text says so with an exception of this library, the way a
     * missing recipient and a missing sender name do, rather than letting the uninitialized
     * property blow up with an Error no catch of this library reaches.
     */
    public function testBodyWithoutATextIsRefused(): void
    {
        $request = new RequestOne($this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_MESSAGE_NOT_DEFINED);

        $request->getData();
    }

    public function testTheTextIsRefusedUntilItIsSet(): void
    {
        $request = new RequestOne($this->config());

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_MESSAGE_NOT_DEFINED);

        $request->getContent();
    }

    /**
     * Every request is sent under an integration, chapter 4.1, so a configuration naming none is
     * refused where the request is built rather than at the gateway.
     */
    public function testARequestRefusesAConfigurationWithoutAnIntegration(): void
    {
        $config = new Config;
        $config->setKey(self::INTEGRATION_KEY);

        $this->expectException(ConfigException::class);

        new RequestOne($config);
    }

    /**
     * Chapter 10.2.1: the Viber variant travels in an "im" object of its own, next to the text
     * the gateway falls back to when Viber does not get through.
     */
    public function testTheViberVariantIsWrittenIntoTheRequest(): void
    {
        $request = $this->request();
        $request->setInstantMessage($this->instantMessage());

        self::assertSame([
            'sndr' => self::VIBER_SENDER,
            'ttl' => 600,
            'msg' => self::VIBER_TEXT,
        ], $request->getData()['im']);
    }

    public function testARequestWithoutAViberVariantCarriesNoImObject(): void
    {
        self::assertArrayNotHasKey('im', $this->request()->getData());
    }

    /**
     * Chapter 10.2.1 lets the object be left out but never sent empty, so a variant that names
     * nothing at all is left out altogether.
     */
    public function testAnEmptyViberVariantIsLeftOutRatherThanSentEmpty(): void
    {
        $request = $this->request();
        $request->setInstantMessage(new InstantMessage);

        self::assertArrayNotHasKey('im', $request->getData());
    }

    /**
     * The variant handed over by the caller is cloned, so a later change to it does not reach
     * the request that was already built from it.
     */
    public function testTheViberVariantOfTheCallerIsNotChangedByTheRequest(): void
    {
        $instantMessage = $this->instantMessage();

        $request = $this->request();
        $request->setInstantMessage($instantMessage);
        $instantMessage->setContent('Iny text');

        self::assertSame(self::VIBER_TEXT, $request->getData()['im']['msg']);
    }

    /**
     * The Viber variant is what the gateway sends over Viber with, the flags are what makes it
     * try Viber at all, so carrying one does not turn on the other.
     */
    public function testTheViberVariantDoesNotTurnOnTheViberFlagByItself(): void
    {
        $request = $this->request();
        $request->setInstantMessage($this->instantMessage());

        self::assertSame(1, $request->getData()['flgs']);
    }

    /**
     * A field that carries nothing is left out of the body, but a text of "0" carries something:
     * it is the whole message, and the signature was composed over it. Dropping it would post a
     * request whose own signature does not match what it says.
     * @return void
     */
    public function testAMessageWhoseWholeTextIsZeroStillCarriesIt(): void
    {
        $request = new RequestOne($this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent('0', new Flag);

        $data = $request->getData();

        self::assertArrayHasKey('txt', $data);
        self::assertSame('0', $data['txt']);
        self::assertSame($request->getSign(), $data['sgn']);
    }

    /**
     * The flags are the one field that really is left out when they are the plain default of
     * zero, chapter 9.3.1 — that has to survive the text of "0" being kept.
     * @return void
     */
    public function testTheDefaultFlagsAreStillLeftOutOfTheBody(): void
    {
        $request = new RequestOne($this->config());
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::TEXT, new Flag);

        self::assertArrayNotHasKey('flgs', $request->getData());
    }

    /**
     * @return InstantMessage
     * @throws InstantMessageException
     */
    private function instantMessage(): InstantMessage
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName(self::VIBER_SENDER);
        $instantMessage->setTtl(600);
        $instantMessage->setContent(self::VIBER_TEXT);

        return $instantMessage;
    }

    /**
     * @return RequestOne
     * @throws RecipientException
     * @throws RequestException
     */
    private function request(): RequestOne
    {
        $flag = new Flag;
        $flag->addReceipt();

        $request = new RequestOne($this->config());
        $request->setMessageId('c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30');
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::TEXT, $flag);

        return $request;
    }

    /**
     * The same request, addressed to a recipient the calling system knows by a key of its own.
     * @return RequestOne
     * @throws RecipientException
     * @throws RequestException
     */
    private function identifiedRequest(): RequestOne
    {
        $flag = new Flag;
        $flag->addReceipt();

        $request = new RequestOne($this->config());
        $request->setMessageId('c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30');
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER));
        $request->setContent(self::TEXT, $flag);

        return $request;
    }

    /**
     * @param Flag $flag
     * @return RequestOne
     * @throws RecipientException
     * @throws RequestException
     */
    private function unicodeRequest(Flag $flag): RequestOne
    {
        $request = new RequestOne($this->config());
        $request->setMessageId('c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30');
        $request->setSenderName(self::SENDER);
        $request->setRecipient(new Recipient(self::RECIPIENT));
        $request->setContent(self::UNICODE_TEXT, $flag, true);

        return $request;
    }

    /**
     * A request carrying nothing but its text — that is all the amount is counted from.
     * @param string $content
     * @param bool|null $isUnicode
     * @return RequestOne
     * @throws RequestException
     */
    private function requestWithContent(string $content, ?bool $isUnicode = null): RequestOne
    {
        $request = new RequestOne($this->config());
        $request->setContent($content, new Flag, $isUnicode);

        return $request;
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
