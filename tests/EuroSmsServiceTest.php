<?php

declare(strict_types=1);

namespace EuroSms\Tests;

use DateTime;
use EuroSms\Config;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\InstantMessageException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RequestException;
use EuroSms\Exception\SendException;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Result\ResultManyToMany;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Gateway\Result\ResultOneToMany;
use EuroSms\Gateway\Status\DeliveryReport;
use EuroSms\Gateway\Status\StatusRequest;
use EuroSms\Gateway\Status\StatusResponse;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface as HttpRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Every test here drives the service through a mocked HTTP client, so no test opens a network
 * connection. Endpoints are the ones listed in chapters 9.3 and 9.4 of SMS API v3.1.15.
 */
#[CoversClass(EuroSmsService::class)]
final class EuroSmsServiceTest extends TestCase
{
    private const string RESPONSE_ONE_ACCEPTED = '{
        "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
        "err_code": "ENQUEUED",
        "err_desc": "Message accepted and enqueued to send"
    }';

    /**
     * Chapter 9.3.11: what went out over Viber is answered with a "v:" prefix in front of the
     * identifier, the segment that fell back to SMS with none.
     */
    private const string RESPONSE_ONE_VIBER = '{
        "uuid": [ "v:D0E17E57-F455-430E-844D-EAD6F88A18E4", "A0E17E57-F455-430E-844D-EAD6F88A18E5" ],
        "err_code": "ENQUEUED",
        "err_desc": "Message accepted and enqueued to send"
    }';

    private const string RESPONSE_GROUP_ACCEPTED = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 2231232,
        "accepted": [
            { "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] },
            { "r": 421902121212, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] }
        ],
        "wrong_numbers": []
    }';

    private const string RESPONSE_MANY_ACCEPTED = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 3344123,
        "result": [
            { "e": "ENQUEUED", "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] },
            { "e": "ENQUEUED", "r": 421902121212, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] },
            { "e": "WRONG_NUMBER", "r": 421905000111 }
        ]
    }';

    /**
     * The answer of a delivery status call, chapters 9.4.2 and 9.4.5 of SMS API v3.1.15.
     */
    private const string RESPONSE_STATUS_ONE = '[
        {
            "rcpt": 421903622237,
            "carrier": "231.6",
            "price": 0.0265,
            "dlr_time": "2017-11-23 08:05:25",
            "sgmnt": 1,
            "dlr": "DELIVRD",
            "i": "D0E17E57-F455-430E-844D-EAD6F88A18E4",
            "snd": "2017-11-23 08:05:21",
            "err_code": "OK",
            "f": null
        }
    ]';

    private const string RESPONSE_STATUS_GROUP = '{
        "dlrs": [
            { "rcpt": 421903622237, "i": "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07", "sgmnt": 1, "dlr": "DELIVRD" },
            { "rcpt": 421902121212, "i": "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7", "sgmnt": 1, "dlr": "EXPIRED" }
        ],
        "count": 2,
        "err_code": "OK"
    }';

    private MockClientFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MockClientFactory;
    }

    public function testTheServiceRefusesToStartWithoutAnIntegrationKey(): void
    {
        $config = new Config;
        $config->setId('2-A2gHjk');

        $this->expectException(ConfigException::class);

        new EuroSmsService($config);
    }

    /**
     * The integration id is asked for the same way the key is, chapter 4.1. A configuration
     * carrying neither used to pass the constructor and only fall over at the first send, on an
     * uninitialized property no catch of this library reaches.
     */
    public function testTheServiceRefusesToStartWithoutAnIntegrationId(): void
    {
        $config = new Config;
        $config->setKey('Gh-s7-J6');

        $this->expectException(ConfigException::class);

        new EuroSmsService($config);
    }

    /**
     * A PSR-18 client hands back what the gateway answered instead of throwing at it, chapter 3
     * of the standard. A send the gateway refused outright therefore has to be turned into the
     * exception the caller has always been able to catch, rather than read as an answer.
     * @return void
     */
    public function testASendTheGatewayRefusedOutrightRaisesASendException(): void
    {
        $service = $this->service([MockClientFactory::json('{"err_code":"INTERNAL_ERROR"}', 500)]);

        $this->expectException(SendException::class);
        $this->expectExceptionCode(500);

        (void)$service->sendOne($this->oneMessage('Testovacia sprava'));
    }

    /**
     * A status call is the one that would suffer most from a refusal read as an answer: an empty
     * list of reports is what the gateway says when nothing changed, so a gateway that is down
     * would look exactly like a quiet one.
     * @return void
     */
    public function testAStatusCallTheGatewayRefusedOutrightRaisesASendException(): void
    {
        $service = $this->service([MockClientFactory::json('', 503)]);

        $this->expectException(SendException::class);
        $this->expectExceptionCode(503);

        (void)$service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');
    }

    /**
     * Chapter 9.3.1 lists neither "sch" nor "ttl" among the fields of a message of a transaction,
     * and the root of the transaction has a default for neither, so a scheduled message put into
     * one would go out at once. It is refused instead of being sent with its schedule missing.
     * @return void
     */
    public function testAScheduledMessageIsRefusedByATransaction(): void
    {
        $message = $this->message('Prva sprava', ['+421903622237']);
        $message->setScheduleDateTime(new DateTime('2027-01-10 12:30'));

        $collection = new MessageCollection;
        $collection->offsetSet($message->getId(), $message);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_MESSAGE_NOT_SCHEDULABLE);

        (void)$service->sendManyToMany($collection);
    }

    /**
     * @return void
     */
    public function testAMessageWithATimeToLiveIsRefusedByATransaction(): void
    {
        $message = $this->message('Prva sprava', ['+421903622237']);
        $message->setTtl(600);

        $collection = new MessageCollection;
        $collection->offsetSet($message->getId(), $message);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $this->expectException(RequestException::class);

        (void)$service->sendManyToMany($collection);
    }

    /**
     * The sender every message of a transaction falls back to, chapter 9.3.8, was the one of
     * whichever message happened to name a sender first — so a message that named none went out,
     * and was signed, under the name of a different message entirely. A message with no sender is
     * refused instead, the way a single send has always refused one.
     * @return void
     */
    public function testAMessageWithoutASenderIsRefusedByATransaction(): void
    {
        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237');

        $nameless = new Message;
        $nameless->setContent('Prva sprava');
        $nameless->setRecipientCollection($recipients);

        $named = $this->message('Druha sprava', ['+421902121212']);
        $named->setSenderName('Iny');

        $collection = new MessageCollection;
        $collection->offsetSet($nameless->getId(), $nameless);
        $collection->offsetSet($named->getId(), $named);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NOT_DEFINED);

        (void)$service->sendManyToMany($collection);
    }

    /**
     * The one thing a whole transaction can be spread over time by is "dur", chapter 9.2.1, so a
     * message carrying only that is not refused and the root carries it.
     * @return void
     */
    public function testALengthTheSendingIsSpreadOverIsStillAccepted(): void
    {
        $message = $this->message('Prva sprava', ['+421903622237']);
        $message->setDuration('01:00');

        $collection = new MessageCollection;
        $collection->offsetSet($message->getId(), $message);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        (void)$service->sendManyToMany($collection);

        self::assertSame('01:00', $this->factory->requestBody()['dur']);
    }

    /**
     * There is one "dur" and it belongs to the root, chapter 9.2.1, so two messages naming two
     * different lengths cannot both be honoured. The first one used to win and the second was
     * dropped without a word — the very thing a schedule is refused for. A message naming none
     * still falls back to the one the root carries.
     * @return void
     */
    public function testTwoMessagesSpreadOverDifferentLengthsAreRefusedByATransaction(): void
    {
        $first = $this->message('Prva sprava', ['+421903622237']);
        $first->setDuration('01:00');

        $second = $this->message('Druha sprava', ['+421902121212']);
        $second->setDuration('02:00');

        $collection = new MessageCollection;
        $collection->offsetSet($first->getId(), $first);
        $collection->offsetSet($second->getId(), $second);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_DURATION_AMBIGUOUS);

        (void)$service->sendManyToMany($collection);
    }

    /**
     * The same length twice over is no disagreement, and a message naming none is none either.
     * @return void
     */
    public function testMessagesAgreeingOnTheLengthTravelAsOneTransaction(): void
    {
        $first = $this->message('Prva sprava', ['+421903622237']);
        $first->setDuration('01:00');

        $second = $this->message('Druha sprava', ['+421902121212']);
        $second->setDuration('01:00');

        $third = $this->message('Tretia sprava', ['+421905000111']);

        $collection = new MessageCollection;
        $collection->offsetSet($first->getId(), $first);
        $collection->offsetSet($second->getId(), $second);
        $collection->offsetSet($third->getId(), $third);

        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        (void)$service->sendManyToMany($collection);

        self::assertSame('01:00', $this->factory->requestBody()['dur']);
    }

    /**
     * What the gateway did answer is read the way it always was: a refusal it states in the body
     * of an accepted call is a failed send, not an exception.
     * @return void
     */
    public function testARefusalTheGatewayStatesInTheBodyIsStillAnAnswer(): void
    {
        $service = $this->service([MockClientFactory::json('{
            "err_code": "WRONG_NUMBER",
            "err_list": [ { "err_code": "WRONG_NUMBER", "err_desc": "Wrong recipient number" } ]
        }')]);

        $result = $service->sendOne($this->oneMessage('Testovacia sprava'));

        self::assertSame([], $result->getSent());
        self::assertCount(1, $result->getFailed());
    }

    public function testSendOneUsesTheInjectedClientAndTheDocumentedEndpoint(): void
    {
        $this->sendOne();

        self::assertSame(1, $this->factory->requestCount());
        self::assertSame('POST', $this->factory->requestMethod());
        self::assertSame('/api/v3/send/one', $this->factory->requestUri());
    }

    public function testTestModeSwapsTheSendEndpointForTheTestOne(): void
    {
        $this->sendOne(testMode: true);

        self::assertSame('/api/v3/test/one', $this->factory->requestUri());
    }

    /**
     * The library talks PSR-18, so a client that is not Guzzle at all drives it just as well.
     * Nothing of the transport is asked of it beyond the one method the standard states.
     * @return void
     */
    public function testAnyPsrHttpClientCanBeHandedOver(): void
    {
        $client = new class implements ClientInterface {
            /** @var HttpRequestInterface|null $request */
            public ?HttpRequestInterface $request = null;

            /**
             * @param HttpRequestInterface $request
             * @return ResponseInterface
             */
            #[\Override]
            public function sendRequest(HttpRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return MockClientFactory::json('{
                    "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
                    "err_code": "ENQUEUED",
                    "err_desc": "Message accepted and enqueued to send"
                }');
            }
        };

        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');

        $result = (new EuroSmsService($config, $client))->sendOne($this->oneMessage('Testovacia sprava'));

        self::assertNotNull($client->request);
        self::assertSame('POST', $client->request->getMethod());
        self::assertSame('as.eurosms.com', $client->request->getUri()->getHost());
        self::assertSame('/api/v3/send/one', $client->request->getUri()->getPath());
        self::assertSame('application/json', $client->request->getHeaderLine('Content-Type'));
        self::assertCount(1, $result->getSent());
    }

    public function testTheBodyPutOnTheWireIsTheDocumentedOneObject(): void
    {
        $this->sendOne();

        self::assertSame([
            'iid' => '2-A2gHjk',
            'rsp' => 'basic',
            'sgn' => '6f56060b6b7db97ca25782b771cca0a65077bd5b',
            'rcpt' => 421903622237,
            'sndr' => 'RZi',
            'txt' => 'Testovacia sprava',
        ], $this->factory->requestBody());
    }

    public function testAnAcceptedMessageEndsUpAmongTheSentOnes(): void
    {
        $result = $this->sendOne();

        $sent = $result->getSent();
        self::assertCount(1, $sent);

        $entry = reset($sent);
        self::assertSame(421903622237, $entry[0]['number']);
        self::assertSame(['D0E17E57-F455-430E-844D-EAD6F88A18E4'], $entry[0]['uuid']);
        self::assertSame([], $result->getFailed());
        self::assertSame([], $result->getDenied());
    }

    public function testSendOneToManyPostsTheGroupEndpointOnce(): void
    {
        $this->sendOneToMany();

        self::assertSame(1, $this->factory->requestCount());
        self::assertSame('/api/v3/send/o2m', $this->factory->requestUri());
    }

    public function testTheSameNumberIsNotSentToTwice(): void
    {
        $this->sendOneToMany();

        self::assertSame([421903622237, 421902121212], $this->factory->requestBody()['rcpts']);
    }

    public function testEveryAcceptedNumberOfAGroupEndsUpAmongTheSentOnes(): void
    {
        $sent = $this->sendOneToMany()->getSent();
        self::assertCount(1, $sent);

        $entry = reset($sent);
        self::assertSame([421903622237, 421902121212], array_column($entry, 'number'));
    }

    public function testSendManyToManyPutsEveryMessageIntoOneRequest(): void
    {
        $this->sendManyToMany();

        self::assertSame(1, $this->factory->requestCount());
        self::assertSame('/api/v3/send/m2m', $this->factory->requestUri());
        self::assertCount(3, $this->factory->requestBody()['msgs']);
    }

    public function testTestModeSwapsTheManyToManyEndpointForTheTestOne(): void
    {
        $this->sendManyToMany(testMode: true);

        self::assertSame('/api/v3/test/m2m', $this->factory->requestUri());
    }

    public function testEveryMessageOfTheGroupCarriesItsOwnTextAndItsOwnSignature(): void
    {
        $this->sendManyToMany();

        $messages = $this->factory->requestBody()['msgs'];

        self::assertSame(['Prva sprava', 'Druha sprava', 'Tretia sprava'], array_column($messages, 'txt'));
        self::assertCount(3, array_unique(array_column($messages, 'sgn')));
        self::assertSame('RZi', $this->factory->requestBody()['dsndr']);
    }

    /**
     * One transaction carries a thousand messages at most, chapter 9.3.1, so a bigger collection
     * goes out as more than one request.
     */
    public function testACollectionBiggerThanOneTransactionIsSplitIntoBatchesOfAThousand(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED),
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)
        ]);

        $messageCollection = new MessageCollection;

        for ($i = 0; $i <= 1000; $i++) {
            $message = $this->message('Sprava ' . $i, [sprintf('+4219030%05d', $i)]);
            $messageCollection->offsetSet($message->getId(), $message);
        }

        (void)$service->sendManyToMany($messageCollection);

        self::assertSame(2, $this->factory->requestCount());
        self::assertCount(1000, $this->factory->requestBody()['msgs']);
        self::assertCount(1, $this->factory->requestBody(1)['msgs']);
    }

    public function testTheAnswerOfTheGroupIsSortedIntoTheSentAndTheFailedNumbers(): void
    {
        $result = $this->sendManyToMany();

        $sent = $result->getSent();
        $failed = $result->getFailed();

        self::assertSame([421903622237, 421902121212], array_column(reset($sent), 'number'));
        self::assertSame([421905000111], array_column(reset($failed), 'number'));
        self::assertSame([3344123, 3344123], array_column(reset($sent), 'group_id'));
        self::assertSame([], $result->getDenied());
    }

    /**
     * What fills a transaction is how many messages it sends, not how many entries it lists:
     * two entries of six hundred numbers each are twelve hundred messages and do not fit into
     * one transaction.
     */
    public function testWhatFillsATransactionIsTheNumberOfMessagesNotOfEntries(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED),
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)
        ]);

        $messageCollection = new MessageCollection;

        foreach (['Prva sprava' => 0, 'Druha sprava' => 600] as $content => $offset) {
            $numbers = [];

            for ($i = 0; $i < 600; $i++) {
                $numbers[] = sprintf('+4219030%05d', $offset + $i);
            }

            $message = $this->message($content, $numbers);
            $messageCollection->offsetSet($message->getId(), $message);
        }

        (void)$service->sendManyToMany($messageCollection);

        self::assertSame(2, $this->factory->requestCount());
        self::assertCount(1, $this->factory->requestBody()['msgs']);
        self::assertCount(1, $this->factory->requestBody(1)['msgs']);
    }

    /**
     * Every send starts over, so the message of an earlier call is not posted a second time.
     */
    public function testASecondSendOnTheSameServicePostsOnlyItsOwnMessage(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED),
            MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED)
        ]);

        (void)$service->sendOne($this->oneMessage('Prva sprava'));
        $result = $service->sendOne($this->oneMessage('Druha sprava'));

        self::assertSame(2, $this->factory->requestCount());
        self::assertSame('Druha sprava', $this->factory->requestBody(1)['txt']);
        self::assertCount(1, $result->getRequestCollection());
        self::assertCount(1, $result->getSent());
    }

    /**
     * A group send after a single one carries only the messages of the group; the single message
     * is not posted again, and to the group endpoint at that.
     */
    public function testAGroupSendAfterASingleOneCarriesOnlyItsOwnMessages(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED),
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)
        ]);

        (void)$service->sendOne($this->oneMessage('Prva sprava'));

        $messageCollection = new MessageCollection;
        $message = $this->message('Druha sprava', ['+421902121212']);
        $messageCollection->offsetSet($message->getId(), $message);

        (void)$service->sendManyToMany($messageCollection);

        self::assertSame(2, $this->factory->requestCount());
        self::assertSame('/api/v3/send/one', $this->factory->requestUri());
        self::assertSame('/api/v3/send/m2m', $this->factory->requestUri(1));
        self::assertCount(1, $this->factory->requestBody(1)['msgs']);
    }

    /**
     * Chapter 9.4.1: the status of one message is read with GET, at an address that is nothing
     * but the identifier the send answered with.
     */
    public function testTheStatusOfOneMessageIsReadWithGetFromTheDocumentedEndpoint(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_ONE)]);

        (void)$service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');

        self::assertSame('GET', $this->factory->requestMethod());
        self::assertSame('/api/v3/status/one/D0E17E57-F455-430E-844D-EAD6F88A18E4', $this->factory->requestUri());
    }

    public function testTheStatusOfOneMessageIsHandedOverAsReportsAndNotAsARawBody(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_ONE)]);

        $status = $service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');

        self::assertInstanceOf(StatusResponse::class, $status);
        self::assertCount(1, $status);
        self::assertTrue($status->getReports()[0]->isDelivered());
        self::assertSame(421903622237, $status->getReports()[0]->getRecipient());
    }

    /**
     * A long message is split into segments and every one of them is reported on its own,
     * chapter 9.4.1, so the caller is handed one report per segment.
     */
    public function testASegmentedMessageIsReportedSegmentBySegment(): void
    {
        $service = $this->service([MockClientFactory::json('[
            { "i": "D0E17E57-F455-430E-844D-EAD6F88A18E4", "sgmnt": 1, "dlr": "DELIVRD" },
            { "i": "510b310a-ea3e-11e8-b780-87afcd091a1a", "sgmnt": 2, "dlr": "DELIVRD" }
        ]')]);

        $status = $service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');

        self::assertSame([1, 2], array_map(
            static fn (DeliveryReport $report): ?int => $report->getSegment(),
            $status->getReports()
        ));
    }

    /**
     * Chapter 9.4.1: an identifier the gateway knows nothing about is answered with nothing to
     * report, and that is an answer the caller reads rather than an exception it catches.
     */
    public function testAnUnknownIdentifierIsAnsweredWithAnEmptyResultAndNotWithAnException(): void
    {
        $service = $this->service([MockClientFactory::json('[]')]);

        self::assertTrue($service->getStatusOne('nieco-co-neexistuje')->isEmpty());
    }

    /**
     * Chapter 9.4.4: the group is asked by the integration id, the group_id and the signature
     * of that group_id.
     */
    public function testTheStatusOfAGroupIsAskedWithTheSignedGroupId(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_GROUP)]);

        $status = $service->getStatusGroup(3344123);

        self::assertSame('GET', $this->factory->requestMethod());
        self::assertSame(
            '/api/v3/status/group/2-A2gHjk/3344123/a42603048b95af159552277b0069b27be7f89370',
            $this->factory->requestUri()
        );
        self::assertCount(2, $status);
    }

    /**
     * The group a send was filed under is what the delivery status of that send is asked by, so
     * the result of a send has to hand it over.
     */
    public function testTheGroupOfASendIsWhatTheStatusOfThatSendIsAskedBy(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED),
            MockClientFactory::json(self::RESPONSE_STATUS_GROUP)
        ]);

        $messageCollection = new MessageCollection;
        $message = $this->message('Prva sprava', ['+421903622237']);
        $messageCollection->offsetSet($message->getId(), $message);

        $result = $service->sendManyToMany($messageCollection);

        self::assertSame([3344123], $result->getGroupIds());

        (void)$service->getStatusGroup($result->getGroupIds()[0]);

        self::assertStringStartsWith('/api/v3/status/group/2-A2gHjk/3344123/', $this->factory->requestUri(1));
    }

    /**
     * Chapter 9.4.3: the bulk status is asked by a transaction number, and a number reused from
     * an earlier call would read an answer that was already handed out, so every call brings a
     * new one.
     */
    public function testEveryBulkStatusCallCarriesATransactionNumberOfItsOwn(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_STATUS_GROUP),
            MockClientFactory::json(self::RESPONSE_STATUS_GROUP)
        ]);

        (void)$service->getStatusAny();
        (void)$service->getStatusAny();

        self::assertSame('GET', $this->factory->requestMethod());
        self::assertNotSame($this->factory->requestUri(), $this->factory->requestUri(1));

        foreach ([0, 1] as $index) {
            $parts = explode('/', trim($this->factory->requestUri($index), '/'));

            self::assertSame(['api', 'v3', 'status', 'any', '2-A2gHjk'], array_slice($parts, 0, 5));
            self::assertGreaterThanOrEqual(StatusRequest::TRANSACTION_MIN_LENGTH, strlen($parts[5]));
            self::assertLessThanOrEqual(StatusRequest::TRANSACTION_MAX_LENGTH, strlen($parts[5]));
        }
    }

    /**
     * The mistake this is here for. Chapter 9.4.3 reports a final status once and not again, so a
     * call whose answer never arrived — a timeout, a 502, a process that died holding it — took
     * its delivery reports with it: asked again under a fresh number, the call reads whatever
     * changed since, and what it had already reported is not among it. The service always
     * generated the number and never let the caller name one, so there was no way to ask the same
     * call twice, and the length check StatusRequest::any() carries could not be reached at all.
     */
    public function testABulkStatusCallCanBeAskedAgainUnderTheSameTransactionNumber(): void
    {
        $service = $this->service([
            MockClientFactory::json(self::RESPONSE_STATUS_GROUP),
            MockClientFactory::json(self::RESPONSE_STATUS_GROUP)
        ]);

        (void)$service->getStatusAny('nightly-run-2026-09-03');
        (void)$service->getStatusAny('nightly-run-2026-09-03');

        self::assertSame($this->factory->requestUri(), $this->factory->requestUri(1));
        self::assertStringStartsWith(
            '/api/v3/status/any/2-A2gHjk/nightly-run-2026-09-03/',
            $this->factory->requestUri()
        );
    }

    /**
     * The length of chapter 9.4.3 is what the gateway takes, and a number outside it is refused
     * here rather than sent to be refused there.
     * @param string $transaction
     */
    #[DataProvider('provideTransactionNumbersOutsideTheDocumentedLength')]
    public function testABulkStatusCallRefusesATransactionNumberTheGatewayWouldNotTake(string $transaction): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_GROUP)]);

        $this->expectException(RequestException::class);

        (void)$service->getStatusAny($transaction);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTransactionNumbersOutsideTheDocumentedLength(): array
    {
        return [
            'one character short' => [str_repeat('a', StatusRequest::TRANSACTION_MIN_LENGTH - 1)],
            'one character long' => [str_repeat('a', StatusRequest::TRANSACTION_MAX_LENGTH + 1)]
        ];
    }

    /**
     * A caller with nothing to recover names nothing and is given a number of its own, which is
     * what every call did before there was anything to name.
     */
    public function testABulkStatusCallThatNamesNoTransactionStillGetsOne(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_GROUP)]);

        (void)$service->getStatusAny();

        $parts = explode('/', trim($this->factory->requestUri(), '/'));

        self::assertGreaterThanOrEqual(StatusRequest::TRANSACTION_MIN_LENGTH, strlen($parts[5]));
    }

    /**
     * A status operation has no test counterpart, chapter 9.4, so test mode leaves it where it
     * is instead of sending it somewhere that answers nothing.
     */
    public function testTestModeLeavesTheStatusEndpointWhereItIs(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_ONE)], testMode: true);

        (void)$service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');

        self::assertSame('/api/v3/status/one/D0E17E57-F455-430E-844D-EAD6F88A18E4', $this->factory->requestUri());
    }

    /**
     * Chapter 10.2.1: the Viber variant goes out in an "im" object of its own, next to the text
     * the gateway falls back to; the flags of chapter 14.1 are what makes it try Viber at all.
     */
    public function testTheViberVariantOfAMessageGoesOutInsideTheImObject(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED)]);

        $message = $this->oneMessage('Testovacia sprava');
        $message->getFlag()->addViber();
        $message->setInstantMessage($this->instantMessage());

        (void)$service->sendOne($message);

        $body = $this->factory->requestBody();

        self::assertSame([
            'sndr' => 'RZi Viber',
            'ttl' => 600,
            'msg' => 'Testovacia sprava cez Viber',
        ], $body['im']);
        self::assertSame(512, $body['flgs']);
    }

    public function testAMessageWithoutAViberVariantPutsNoImObjectOnTheWire(): void
    {
        $this->sendOne();

        self::assertArrayNotHasKey('im', $this->factory->requestBody());
    }

    public function testTheViberSegmentsOfASentMessageAreTellableFromTheSmsOnes(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_ONE_VIBER)]);

        $sent = $service->sendOne($this->oneMessage('Testovacia sprava'))->getSent();
        $entry = reset($sent);

        self::assertSame([
            'v:D0E17E57-F455-430E-844D-EAD6F88A18E4',
            'A0E17E57-F455-430E-844D-EAD6F88A18E5',
        ], $entry[0]['uuid']);
        self::assertSame(['v:D0E17E57-F455-430E-844D-EAD6F88A18E4'], $entry[0]['viber']);
    }

    /**
     * The Viber sender and the time to live of the first message that names any become the
     * defaults of the whole transaction, the same way the default sender name is taken. The
     * messages that name none carry no "im" object of their own.
     */
    public function testTheRootOfAGroupCarriesTheViberDefaultsOfTheFirstMessageThatNamesThem(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $messageCollection = new MessageCollection;

        $first = $this->message('Prva sprava', ['+421903622237']);
        $first->setInstantMessage($this->instantMessage());
        $messageCollection->offsetSet($first->getId(), $first);

        $second = $this->message('Druha sprava', ['+421902121212']);
        $messageCollection->offsetSet($second->getId(), $second);

        (void)$service->sendManyToMany($messageCollection);

        $body = $this->factory->requestBody();

        self::assertSame('RZi Viber', $body['dimsndr']);
        self::assertSame(600, $body['dimttl']);
        self::assertSame([
            'sndr' => 'RZi Viber',
            'ttl' => 600,
            'msg' => 'Testovacia sprava cez Viber',
        ], $body['msgs'][0]['im']);
        self::assertArrayNotHasKey('im', $body['msgs'][1]);
    }

    public function testAGroupWithoutASingleViberVariantCarriesNoDefaultsOfItsOwn(): void
    {
        $this->sendManyToMany();

        $body = $this->factory->requestBody();

        self::assertArrayNotHasKey('dimsndr', $body);
        self::assertArrayNotHasKey('dimttl', $body);
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
     * @param bool $testMode
     * @return ResultManyToMany
     */
    private function sendManyToMany(bool $testMode = false): ResultManyToMany
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)], $testMode);

        $messageCollection = new MessageCollection;

        foreach ([
            'Prva sprava' => ['+421903622237'],
            'Druha sprava' => ['+421902121212', '0902 121 212'],
            'Tretia sprava' => ['+421905000111']
        ] as $content => $numbers) {
            $message = $this->message($content, $numbers);
            $messageCollection->offsetSet($message->getId(), $message);
        }

        return $service->sendManyToMany($messageCollection);
    }

    /**
     * @param string $content
     * @param string[] $numbers
     * @return Message
     */
    private function message(string $content, array $numbers): Message
    {
        $recipients = new RecipientCollection;

        foreach ($numbers as $number) {
            $recipients[] = new Recipient($number);
        }

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent($content);
        $message->setRecipientCollection($recipients);

        return $message;
    }

    /**
     * @param bool $testMode
     * @return ResultOne
     */
    private function sendOne(bool $testMode = false): ResultOne
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED)], $testMode);

        return $service->sendOne($this->oneMessage('Testovacia sprava'));
    }

    /**
     * A message addressed to the one number the documentation works its example with.
     * @param string $content
     * @return Message
     */
    private function oneMessage(string $content): Message
    {
        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent($content);
        $message->setRecipient(new Recipient('+421903622237'));

        return $message;
    }

    /**
     * @return ResultOneToMany
     */
    private function sendOneToMany(): ResultOneToMany
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_GROUP_ACCEPTED)]);

        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237');
        $recipients[] = new Recipient('+421902121212');
        $recipients[] = new Recipient('0903 622 237');

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent('Testovacia sprava');
        $message->setRecipientCollection($recipients);

        return $service->sendOneToMany($message);
    }

    /**
     * @param array<int, ResponseInterface> $responses
     * @param bool $testMode
     * @return EuroSmsService
     * @throws ConfigException
     */
    private function service(array $responses, bool $testMode = false): EuroSmsService
    {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');
        $config->setTestMode($testMode);

        return new EuroSmsService($config, $this->factory->create($responses));
    }
}
