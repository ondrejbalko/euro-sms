<?php

declare(strict_types=1);

namespace EuroSms\Tests;

use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\SendException;
use EuroSms\Tests\Helpers\RecordingLogger;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;

/**
 * A send too big for one request is split into batches, and this is where it is shown that the
 * batches travel together rather than one after another, and that one of them failing leaves the
 * rest sent. No test here opens a network connection: the transport is a handler that answers
 * nothing until it is waited on, which is what makes the number of calls standing at the gateway
 * at one moment countable at all.
 */
#[CoversClass(EuroSmsService::class)]
final class EuroSmsServiceConcurrencyTest extends TestCase
{
    /**
     * How many numbers one request carries, chapter 9.2.1 of SMS API v3.1.15.
     * @var int
     */
    private const int BATCH = 1000;

    /**
     * What a call that never reached the gateway is reported as.
     * @var string
     */
    private const string REASON = 'Connection timed out';

    /**
     * What a transport that gave up on the whole send is reported as.
     * @var string
     */
    private const string REASON_POOL = 'The transport is in no state to send anything.';

    /**
     * The answer of a group send the gateway enqueued, chapter 9.3.6.
     * @var string
     */
    private const string RESPONSE_ACCEPTED = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 2231232,
        "accepted": [
            { "r": 421900000000, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] }
        ],
        "wrong_numbers": []
    }';

    /**
     * How the gateway states a refusal it answers with 400 or worse, chapter 11.
     * @var string
     */
    private const string RESPONSE_REFUSED = '{"err_code":"SYSTEM_ERROR","err_desc":"Internal error"}';

    /**
     * The answer of a transaction the gateway enqueued, chapter 9.3.11.
     * @var string
     */
    private const string RESPONSE_TRANSACTION = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 3344123,
        "result": [
            { "e": "ENQUEUED", "r": 421900000000, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] }
        ]
    }';

    /** @var array<int, array<string, mixed>> $history what the client actually put on the wire */
    private array $history = [];

    /**
     * How many calls the handler was given and has not answered yet.
     * @var int $inFlight
     */
    private int $inFlight = 0;

    /**
     * The most calls that stood at the gateway at any one moment. One means the send was serial.
     * @var int $peak
     */
    private int $peak = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->history = [];
        $this->inFlight = 0;
        $this->peak = 0;
    }

    public function testTheBatchesOfABigSendStandAtTheGatewayTogether(): void
    {
        $service = $this->service($this->accepted(5));
        $result = $service->sendOneToMany($this->message(5 * self::BATCH));

        self::assertCount(5, $result->getRequestCollection()->all());
        self::assertCount(5, $this->history);
        self::assertSame(5, $this->peak);
    }

    public function testHowManyBatchesTravelAtOnceIsTheConfigurationsToSay(): void
    {
        $service = $this->service($this->accepted(5), 2);
        $result = $service->sendOneToMany($this->message(5 * self::BATCH));

        self::assertCount(5, $this->history);
        self::assertSame(2, $this->peak);
        self::assertCount(5, $result->getSent());
    }

    public function testOneCallAtATimeSendsTheBatchesOneAfterAnother(): void
    {
        $service = $this->service($this->accepted(3), 1);
        $result = $service->sendOneToMany($this->message(3 * self::BATCH));

        self::assertCount(3, $this->history);
        self::assertSame(1, $this->peak);
        self::assertCount(3, $result->getSent());
    }

    public function testTheConfigurationRefusesToSendWithNoCallOnTheWire(): void
    {
        $config = new Config;

        $this->expectException(ConfigException::class);
        $config->setRequestConcurrency(0);
    }

    public function testTheDefaultIsTheOneTheInterfaceStates(): void
    {
        self::assertSame(5, (new Config)->getRequestConcurrency());
    }

    public function testABatchThatFailedDoesNotStopTheOthers(): void
    {
        $outcomes = $this->accepted(5);
        $outcomes[2] = $this->refusal();

        $service = $this->service($outcomes);
        $result = $service->sendOneToMany($this->message(5 * self::BATCH));

        self::assertCount(5, $this->history);
        self::assertCount(4, $result->getSent());
        self::assertCount(1, $result->getDenied());
        self::assertSame([], $result->getFailed());
    }

    public function testEveryNumberOfAFailedBatchIsDeniedWithItsReason(): void
    {
        $outcomes = $this->accepted(5);
        $outcomes[2] = $this->refusal();

        $service = $this->service($outcomes);
        $denied = $service->sendOneToMany($this->message(5 * self::BATCH))->getDenied();

        $entries = reset($denied);
        self::assertIsArray($entries);
        self::assertCount(self::BATCH, $entries);
        self::assertSame([self::REASON], array_values(array_unique(array_column($entries, 'reason'))));
    }

    public function testWhyABatchFailedIsKeptNextToTheAnswers(): void
    {
        $outcomes = $this->accepted(5);
        $outcomes[2] = $this->refusal();

        $service = $this->service($outcomes);
        $responses = $service->sendOneToMany($this->message(5 * self::BATCH))->getResponseCollection();

        self::assertTrue($responses->hasFailures());
        self::assertCount(4, $responses->all());
        self::assertSame([self::REASON], array_values($responses->getReasons()));
    }

    public function testASendInWhichNothingGotThroughIsStillAnException(): void
    {
        $service = $this->service([$this->refusal(), $this->refusal(), $this->refusal()]);

        $this->expectException(SendException::class);
        $this->expectExceptionMessage(self::REASON);

        (void)$service->sendOneToMany($this->message(3 * self::BATCH));
    }

    public function testABatchTheGatewayRefusedLeavesTheOthersSent(): void
    {
        $outcomes = $this->accepted(3);
        $outcomes[1] = new Response(500, ['Content-Type' => 'application/json'], '{"err_code":"SYSTEM_ERROR"}');

        $service = $this->service($outcomes);
        $result = $service->sendOneToMany($this->message(3 * self::BATCH));

        self::assertCount(2, $result->getSent());
        self::assertCount(1, $result->getDenied());
        self::assertSame(
            ['Gateway answered with http status 500.'],
            array_values($result->getResponseCollection()->getReasons())
        );
    }

    public function testTheRefusalOfABatchIsReadableWhereNothingWasThrown(): void
    {
        $outcomes = $this->accepted(3);
        $outcomes[1] = new Response(500, ['Content-Type' => 'application/json'], self::RESPONSE_REFUSED);

        $service = $this->service($outcomes);
        $failures = $service->sendOneToMany($this->message(3 * self::BATCH))->getResponseCollection()->getFailures();

        $failure = reset($failures);
        self::assertInstanceOf(SendException::class, $failure);
        self::assertSame(500, $failure->getHttpStatus());
        self::assertSame(self::RESPONSE_REFUSED, $failure->getResponseBody());
    }

    public function testATransactionIsSplitIntoBatchesThatTravelTogether(): void
    {
        $outcomes = [
            new Response(200, ['Content-Type' => 'application/json'], self::RESPONSE_TRANSACTION),
            $this->refusal()
        ];

        $service = $this->service($outcomes);
        $result = $service->sendManyToMany($this->messageCollection(self::BATCH + 200));

        self::assertCount(2, $this->history);
        self::assertSame(2, $this->peak);
        self::assertCount(1, $result->getSent());

        $denied = $result->getDenied();
        self::assertCount(1, $denied);

        $entries = reset($denied);
        self::assertIsArray($entries);
        self::assertCount(200, $entries);
        self::assertSame([self::REASON], array_values(array_unique(array_column($entries, 'reason'))));
    }

    /**
     * The batches are answered in whatever order the gateway answers them, so the endpoint — the
     * same string for every batch of the send — says nothing about which of them a record belongs
     * to. The record names the batch, and it is the identifier the denied list is keyed by, so the
     * numbers that never went out can be found from the log alone.
     * @return void
     */
    public function testAFailedBatchIsWrittenIntoTheLogUnderTheBatchItHappenedTo(): void
    {
        $outcomes = $this->accepted(5);
        $outcomes[2] = $this->refusal();

        $logger = new RecordingLogger;
        $service = $this->service($outcomes, logger: $logger);
        $denied = $service->sendOneToMany($this->message(5 * self::BATCH))->getDenied();

        $record = $logger->record(LogLevel::ERROR);

        self::assertCount(1, $logger->byLevel(LogLevel::ERROR));
        self::assertSame(self::REASON, $record['context']['reason']);
        self::assertSame(array_key_first($denied), $record['context']['request']);

        $posted = $this->loggedBatches($logger, 'method');
        $answered = $this->loggedBatches($logger, 'status');

        self::assertCount(5, $posted);
        self::assertCount(4, $answered);
        self::assertSame(array_keys($denied), array_values(array_diff($posted, $answered)));
    }

    public function testAClientThatKnowsNothingOfConcurrencyStillSendsEveryBatch(): void
    {
        $outcomes = $this->accepted(3);
        $outcomes[1] = $this->refusal();

        $service = $this->service($outcomes, null, $this->psrClient($outcomes));
        $result = $service->sendOneToMany($this->message(3 * self::BATCH));

        self::assertSame(0, $this->peak);
        self::assertCount(2, $result->getSent());
        self::assertCount(1, $result->getDenied());
        self::assertSame([self::REASON], array_values($result->getResponseCollection()->getReasons()));
    }

    /**
     * The mistake this is here for. What the pool throws when it is waited on is not one batch
     * failing — the two callbacks answer those — but the pool itself giving up: a middleware that
     * blew up, a decorating client that is no client, an Error out of the caller's own code. It
     * travelled straight out of the send as something no catch of this library names, against the
     * one exception the send promises, with nothing written into the log and every batch that had
     * already come back thrown away with it.
     */
    public function testWhatThePoolItselfThrowsIsStillASendException(): void
    {
        $service = $this->service([], null, $this->brokenGuzzleClient());

        $this->expectException(SendException::class);
        $this->expectExceptionMessage(self::REASON_POOL);

        (void)$service->sendOneToMany($this->message(3 * self::BATCH));
    }

    /**
     * And it is written down where a failed call is always written down, naming the batch it
     * happened to, so the numbers that never went out can be found from the log.
     */
    public function testWhatThePoolItselfThrowsIsWrittenIntoTheLogForEveryBatch(): void
    {
        $logger = new RecordingLogger;
        $service = $this->service([], null, $this->brokenGuzzleClient(), $logger);

        try {
            (void)$service->sendOneToMany($this->message(3 * self::BATCH));
        } catch (SendException) {
            // the send is expected to fail; what it wrote down on the way is what is asked here
        }

        $records = $logger->byLevel(LogLevel::ERROR);

        self::assertCount(3, $records);
        self::assertSame(
            [self::REASON_POOL],
            array_values(array_unique(array_column(array_column($records, 'context'), 'reason')))
        );
    }

    /**
     * The mistake this is here for. Reading an answer is more than reading its status: the body is
     * taken off a PSR-7 stream and decoded, and a stream that was detached or cannot be read
     * throws something that is no send exception of ours. That walked out of the whole loop, so
     * the batches after this one were never filed, the failures were never written down and the
     * caller got a RuntimeException where a partial result belonged.
     */
    public function testABodyThatCannotBeReadFailsItsOwnBatchAndNoOther(): void
    {
        $outcomes = $this->accepted(3);
        $outcomes[0] = $this->unreadable();

        $service = $this->service($outcomes);
        $result = $service->sendOneToMany($this->message(3 * self::BATCH));

        self::assertCount(2, $result->getSent());
        self::assertCount(1, $result->getDenied());
        self::assertTrue($result->getResponseCollection()->hasFailures());
    }

    /**
     * The mistake this is here for. A send that failed entirely is reported to the caller by the
     * first of its failures, and they used to be filed in two passes — everything that could not
     * be composed first, everything that did not come back afterwards. A send whose first two
     * batches timed out and whose third carried a text that would not encode was therefore thrown
     * as the encoding error, and the caller's log named a malformed text for a send that died on
     * the network.
     */
    public function testASendThatFailedEntirelyIsThrownAsItsFirstBatch(): void
    {
        $collection = $this->messageCollection(2 * self::BATCH);
        $collection->offsetSet('broken', $this->brokenMessage());

        $service = $this->service([$this->refusal(), $this->refusal()]);

        $this->expectException(SendException::class);
        $this->expectExceptionMessage(self::REASON);

        (void)$service->sendManyToMany($collection);
    }

    /**
     * The failures of one send are filed in the order the batches were built, whichever way round
     * they happened. They used to be filed in two passes, so a batch that could not be composed
     * came before batches that had failed long before it and the list read in an order no caller
     * could make sense of.
     */
    public function testTheFailuresOfASendAreFiledInTheOrderTheBatchesWereBuilt(): void
    {
        $collection = $this->messageCollection(3 * self::BATCH);
        $collection->offsetSet('broken', $this->brokenMessage());

        $service = $this->service([
            $this->refusal(),
            new Response(200, ['Content-Type' => 'application/json'], self::RESPONSE_TRANSACTION),
            $this->refusal()
        ]);

        $result = $service->sendManyToMany($collection);

        $requestIds = array_keys($result->getRequestCollection()->all());

        self::assertCount(4, $requestIds);
        self::assertSame(
            [$requestIds[0], $requestIds[2], $requestIds[3]],
            array_keys($result->getResponseCollection()->getFailures())
        );
    }

    /**
     * As many answers of an enqueued group send as there are batches to answer.
     * @param int $count
     * @return list<Response|Throwable>
     */
    private function accepted(int $count): array
    {
        $responses = [];

        for ($i = 0; $i < $count; $i++) {
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], self::RESPONSE_ACCEPTED);
        }

        return $responses;
    }

    /**
     * A transport that takes a call, counts it as standing, and answers it only once somebody
     * waits on it. A serial send waits on every call as it makes it and never has two standing;
     * a concurrent one hands over as many as it is allowed to before waiting on the first.
     * @param list<Response|Throwable> $outcomes what each call is answered with, in order
     * @return \Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    private function handler(array $outcomes): \Closure
    {
        return function (RequestInterface $request, array $options) use (&$outcomes): PromiseInterface {
            $outcome = array_shift($outcomes);
            $this->inFlight++;
            $this->peak = max($this->peak, $this->inFlight);

            $promise = new Promise(function () use (&$promise, $outcome): void {
                $this->inFlight--;

                if ($outcome instanceof Throwable) {
                    $promise->reject($outcome);

                    return;
                }

                $promise->resolve($outcome);
            });

            return $promise;
        };
    }

    /**
     * The batches one kind of debug record was written for, told apart by the field only that
     * kind carries: a record of a call on its way out states the method, one of an answer the
     * status it came back with.
     * @param RecordingLogger $logger
     * @param string $field
     * @return list<string>
     */
    private function loggedBatches(RecordingLogger $logger, string $field): array
    {
        $batches = [];

        foreach ($logger->byLevel(LogLevel::DEBUG) as $record) {
            if (array_key_exists($field, $record['context']) && is_string($record['context']['request'])) {
                $batches[] = $record['context']['request'];
            }
        }

        return $batches;
    }

    /**
     * One message addressed to as many numbers as asked for, all of them different so that none
     * is dropped as a duplicate before the batches are cut.
     * @param int $recipients
     * @return Message
     * @throws RecipientException
     */
    private function message(int $recipients): Message
    {
        $collection = new RecipientCollection;

        for ($i = 0; $i < $recipients; $i++) {
            $collection[] = new Recipient(sprintf('+4219%08d', $i));
        }

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent('Testovacia sprava');
        $message->setRecipientCollection($collection);

        return $message;
    }

    /**
     * As many one-number messages as asked for, which is what a transaction counts towards the
     * thousand of chapter 9.3.8.
     * @param int $messages
     * @return MessageCollection
     * @throws RecipientException
     */
    private function messageCollection(int $messages): MessageCollection
    {
        $collection = new MessageCollection;

        for ($i = 0; $i < $messages; $i++) {
            $recipients = new RecipientCollection;
            $recipients[] = new Recipient(sprintf('+4219%08d', $i));

            $message = new Message;
            $message->setSenderName('RZi');
            $message->setContent('Testovacia sprava');
            $message->setRecipientCollection($recipients);

            $collection->offsetSet($message->getId(), $message);
        }

        return $collection;
    }

    /**
     * A PSR-18 client and nothing more: it states one call at a time and knows no promises, which
     * is what a caller handing over a transport of its own may well hand over.
     * @param list<Response|Throwable> $outcomes
     * @return ClientInterface
     */
    private function psrClient(array $outcomes): ClientInterface
    {
        return new class ($outcomes) implements ClientInterface {
            /**
             * @param list<Response|Throwable> $outcomes
             */
            public function __construct(private array $outcomes)
            {
            }

            /**
             * @param RequestInterface $request
             * @return ResponseInterface
             * @throws Throwable whatever the call was told to fail with
             */
            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $outcome = array_shift($this->outcomes);

                if ($outcome instanceof Throwable) {
                    throw $outcome;
                }

                return $outcome instanceof ResponseInterface ? $outcome : new Response(204);
            }
        };
    }

    /**
     * A message whose text is not valid UTF-8, so the body it belongs to cannot be composed at
     * all. It is the one way a batch fails before anything of it reaches the wire, which is what
     * makes it worth having: such a failure is filed in a pass of its own.
     * @return Message
     * @throws MessageException
     * @throws RecipientException
     */
    private function brokenMessage(): Message
    {
        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421900999999');

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent("Tato sprava \xB1\x31 nie je utf8");
        $message->setRecipientCollection($recipients);

        return $message;
    }

    /**
     * An answer whose body is gone: the stream was detached before anything read it, so asking
     * for the body throws something that is no send exception of this library.
     * @return Response
     */
    private function unreadable(): Response
    {
        $stream = Utils::streamFor(self::RESPONSE_ACCEPTED);
        $stream->detach();

        return new Response(200, ['Content-Type' => 'application/json'], $stream);
    }

    /**
     * A transport that speaks Guzzle well enough for the batches to be pooled and gives up the
     * moment it is asked for one. What it throws comes out of the pool as it is waited on rather
     * than through the callback a single rejected call is reported by.
     * @return ClientInterface
     */
    private function brokenGuzzleClient(): ClientInterface
    {
        return new class (self::REASON_POOL) implements ClientInterface, GuzzleClientInterface {
            /**
             * @param string $reason
             */
            public function __construct(private string $reason)
            {
            }

            /**
             * @param RequestInterface $request
             * @param array<string, mixed> $options
             * @return ResponseInterface
             */
            #[\Override]
            public function send(RequestInterface $request, array $options = []): ResponseInterface
            {
                throw new RuntimeException($this->reason);
            }

            /**
             * @param RequestInterface $request
             * @param array<string, mixed> $options
             * @return PromiseInterface
             */
            #[\Override]
            public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
            {
                throw new RuntimeException($this->reason);
            }

            /**
             * @param string $method
             * @param mixed $uri
             * @param array<string, mixed> $options
             * @return ResponseInterface
             */
            #[\Override]
            public function request(string $method, $uri, array $options = []): ResponseInterface
            {
                throw new RuntimeException($this->reason);
            }

            /**
             * @param string $method
             * @param mixed $uri
             * @param array<string, mixed> $options
             * @return PromiseInterface
             */
            #[\Override]
            public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
            {
                throw new RuntimeException($this->reason);
            }

            /**
             * @param string|null $option
             * @return mixed
             */
            #[\Override]
            public function getConfig(?string $option = null): mixed
            {
                return null;
            }

            /**
             * @param RequestInterface $request
             * @return ResponseInterface
             */
            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException($this->reason);
            }
        };
    }

    /**
     * A call that never reached the gateway at all.
     * @return ConnectException
     */
    private function refusal(): ConnectException
    {
        return new ConnectException(self::REASON, new Request('POST', '/api/v3/send/o2m'));
    }

    /**
     * @param list<Response|Throwable> $outcomes
     * @param int|null $concurrency
     * @param ClientInterface|null $client
     * @param LoggerInterface|null $logger
     * @return EuroSmsService
     * @throws ConfigException
     */
    private function service(
        array $outcomes,
        ?int $concurrency = null,
        ?ClientInterface $client = null,
        ?LoggerInterface $logger = null
    ): EuroSmsService {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('a1b2c3');

        if (null !== $concurrency) {
            $config->setRequestConcurrency($concurrency);
        }

        if (null === $client) {
            $stack = HandlerStack::create($this->handler($outcomes));
            $stack->push(Middleware::history($this->history));
            $client = new Client(['handler' => $stack]);
        }

        return new EuroSmsService($config, $client, logger: $logger);
    }
}
