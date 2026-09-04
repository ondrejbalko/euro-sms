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
use EuroSms\Exception\SendException;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Helpers\Redactor;
use EuroSms\Tests\Helpers\MockClientFactory;
use EuroSms\Tests\Helpers\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface as HttpRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * What the library tells a logger it was handed: chapter 9 of SMS API v3.1.15 for the calls it
 * makes and chapter 4.2 for the two values a record may never carry. Every test drives the
 * service through a mocked HTTP client, so no test opens a network connection.
 */
#[CoversClass(EuroSmsService::class)]
#[CoversClass(Redactor::class)]
final class EuroSmsServiceLoggingTest extends TestCase
{
    /**
     * The integration the requests of these tests are sent under, and the key they are signed
     * with. The key is what must not be found anywhere in the log.
     */
    private const string CLIENT_ID = '2-A2gHjk';

    private const string CLIENT_KEY = 'Gh-s7-J6';

    private const string RESPONSE_ONE_ACCEPTED = '{
        "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
        "err_code": "ENQUEUED",
        "err_desc": "Message accepted and enqueued to send"
    }';

    private const string RESPONSE_MANY_ACCEPTED = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 3344123,
        "result": [
            { "e": "ENQUEUED", "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] }
        ]
    }';

    private const string RESPONSE_STATUS_GROUP = '{
        "dlrs": [
            { "rcpt": 421903622237, "i": "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07", "sgmnt": 1, "dlr": "DELIVRD" }
        ],
        "count": 1,
        "err_code": "OK"
    }';

    private MockClientFactory $factory;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->factory = new MockClientFactory;
        $this->logger = new RecordingLogger;
    }

    /**
     * A caller that hands over no logger is where the library has always been: it sends, it reads
     * the answer and it writes nothing anywhere.
     * @return void
     */
    public function testWithoutALoggerTheLibraryWritesNothingAndStillSends(): void
    {
        $service = new EuroSmsService($this->config(), $this->factory->create([
            MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED)
        ]));

        $result = $service->sendOne($this->oneMessage());

        self::assertCount(1, $result->getSent());
        self::assertSame(1, $this->factory->requestCount());
        self::assertSame([], $this->logger->all());
    }

    public function testASendIsWrittenWithItsEndpointAndItsBody(): void
    {
        (void)$this->sendOne();

        $record = $this->logger->record(LogLevel::DEBUG);
        $body = $record['context']['body'];

        self::assertSame('POST', $record['context']['method']);
        self::assertSame('/api/v3/send/one', $record['context']['endpoint']);
        self::assertIsArray($body);
        self::assertSame(self::CLIENT_ID, $body['iid']);
        self::assertSame(421903622237, $body['rcpt']);
        self::assertSame('Testovacia sprava', $body['txt']);
    }

    public function testTheAnswerIsWrittenWithItsStatusAndItsBody(): void
    {
        (void)$this->sendOne();

        $record = $this->logger->record(LogLevel::DEBUG, 1);

        self::assertSame('/api/v3/send/one', $record['context']['endpoint']);
        self::assertSame(200, $record['context']['status']);
        self::assertSame(self::RESPONSE_ONE_ACCEPTED, $record['context']['body']);
    }

    /**
     * The body was read once for the log, and the answer still has to be readable for the result
     * built out of it — which is only true when the stream was put back where it was found.
     * @return void
     */
    public function testReadingTheAnswerForTheLogLeavesItReadableForTheResult(): void
    {
        self::assertCount(1, $this->sendOne()->getSent());
    }

    /**
     * Chapter 4.2: the signature is what a request is authenticated by, so the one value that
     * proves who sent it is the one value the log may not carry.
     * @return void
     */
    public function testTheSignatureOfASendNeverReachesTheLog(): void
    {
        (void)$this->sendOne();

        $body = $this->logger->record(LogLevel::DEBUG)['context']['body'];
        $signature = (string)$this->factory->requestBody()['sgn'];

        self::assertIsArray($body);
        self::assertSame(Redactor::MASK, $body['sgn']);
        self::assertSame(hash_hmac('sha1', 'RZi421903622237Testovacia sprava', self::CLIENT_KEY), $signature);
        self::assertStringNotContainsString($signature, $this->logger->dump());
    }

    /**
     * Chapter 9.3.8: a transaction carries a signature for every message it holds, so masking
     * only the root of the body would leave one behind for every message that went out.
     * @return void
     */
    public function testEveryMessageOfATransactionHasItsSignatureMasked(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_MANY_ACCEPTED)]);

        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237');

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent('Testovacia sprava');
        $message->setRecipientCollection($recipients);

        $collection = new MessageCollection;
        $collection[] = $message;

        (void)$service->sendManyToMany($collection);

        $body = $this->logger->record(LogLevel::DEBUG)['context']['body'];
        $sent = $this->factory->requestBody()['msgs'];

        self::assertIsArray($body);
        self::assertIsArray($body['msgs']);
        self::assertSame(Redactor::MASK, $body['msgs'][0]['sgn']);
        self::assertIsArray($sent);
        self::assertStringNotContainsString((string)$sent[0]['sgn'], $this->logger->dump());
    }

    /**
     * Chapter 9.4.4: the status of a group is asked with the signature written into the path
     * itself, so a path is no safer to log than a body is.
     * @return void
     */
    public function testTheSignatureOfAStatusPathNeverReachesTheLog(): void
    {
        $service = $this->service([MockClientFactory::json(self::RESPONSE_STATUS_GROUP)]);

        (void)$service->getStatusGroup(3344123);

        $signature = hash_hmac('sha1', '3344123', self::CLIENT_KEY);

        self::assertSame(
            '/api/v3/status/group/2-A2gHjk/3344123/' . Redactor::MASK,
            $this->logger->record(LogLevel::DEBUG)['context']['endpoint']
        );
        self::assertStringContainsString($signature, $this->factory->requestUri());
        self::assertStringNotContainsString($signature, $this->logger->dump());
    }

    /**
     * The status of one message is asked without a signature, chapter 9.4.1, so nothing of that
     * path is masked and the identifier stays readable.
     * @return void
     */
    public function testTheStatusOfOneMessageIsWrittenWithItsWholePath(): void
    {
        $service = $this->service([MockClientFactory::json('[]')]);

        (void)$service->getStatusOne('D0E17E57-F455-430E-844D-EAD6F88A18E4');

        self::assertSame(
            '/api/v3/status/one/D0E17E57-F455-430E-844D-EAD6F88A18E4',
            $this->logger->record(LogLevel::DEBUG)['context']['endpoint']
        );
    }

    public function testTheIntegrationKeyNeverReachesTheLog(): void
    {
        (void)$this->sendOne();

        self::assertStringNotContainsString(self::CLIENT_KEY, $this->logger->dump());
    }

    /**
     * A call the gateway refused is the one worth finding in a log that is not being read at the
     * debug level, so it is written at the level that is.
     * @return void
     */
    public function testACallTheGatewayRefusedIsWrittenAtTheErrorLevel(): void
    {
        $service = $this->service([MockClientFactory::json('{"err_code":"INTERNAL_ERROR"}', 500)]);

        try {
            (void)$service->sendOne($this->oneMessage());
            self::fail('The refused call was expected to raise a SendException.');
        } catch (SendException) {
        }

        $record = $this->logger->record(LogLevel::ERROR);

        self::assertSame('/api/v3/send/one', $record['context']['endpoint']);
        self::assertSame(500, $record['context']['status']);
        self::assertSame('{"err_code":"INTERNAL_ERROR"}', $record['context']['body']);
    }

    /**
     * The status code was always readable off the exception; the body the gateway stated its
     * refusal in was thrown away, and it is the half that says what was actually wrong.
     * @return void
     */
    public function testTheSendExceptionCarriesTheStatusAndTheBodyOfTheRefusal(): void
    {
        $service = $this->service([MockClientFactory::json('{"err_code":"WRONG_SIGNATURE"}', 403)]);

        $this->expectException(SendException::class);

        try {
            (void)$service->sendOne($this->oneMessage());
        } catch (SendException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame('{"err_code":"WRONG_SIGNATURE"}', $e->getResponseBody());

            throw $e;
        }
    }

    /**
     * A call that never reached the gateway has no status and no body to carry, and it is still
     * the failure the caller has to be told about.
     * @return void
     */
    public function testACallThatNeverReachedTheGatewayIsWrittenAtTheErrorLevel(): void
    {
        $service = new EuroSmsService($this->config(), $this->unreachableClient(), logger: $this->logger);

        try {
            (void)$service->sendOne($this->oneMessage());
            self::fail('The failed call was expected to raise a SendException.');
        } catch (SendException $e) {
            self::assertNull($e->getHttpStatus());
            self::assertNull($e->getResponseBody());
        }

        $record = $this->logger->record(LogLevel::ERROR);

        self::assertSame('/api/v3/send/one', $record['context']['endpoint']);
        self::assertNull($record['context']['status']);
        self::assertNull($record['context']['body']);
        self::assertSame('Connection timed out.', $record['context']['reason']);
    }

    /**
     * @return Config
     */
    private function config(): Config
    {
        $config = new Config;
        $config->setId(self::CLIENT_ID);
        $config->setKey(self::CLIENT_KEY);

        return $config;
    }

    /**
     * A message addressed to the one number the documentation works its example with.
     * @return Message
     */
    private function oneMessage(): Message
    {
        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent('Testovacia sprava');
        $message->setRecipient(new Recipient('+421903622237'));

        return $message;
    }

    /**
     * @param array<int, ResponseInterface> $responses
     * @return EuroSmsService
     * @throws ConfigException
     */
    private function service(array $responses): EuroSmsService
    {
        return new EuroSmsService($this->config(), $this->factory->create($responses), logger: $this->logger);
    }

    /**
     * @return ResultOne
     */
    private function sendOne(): ResultOne
    {
        return $this->service([MockClientFactory::json(self::RESPONSE_ONE_ACCEPTED)])->sendOne($this->oneMessage());
    }

    /**
     * A PSR-18 client that never gets through, the way the standard states it: a call that did
     * not reach the gateway is a network exception and not an answer.
     * @return ClientInterface
     */
    private function unreachableClient(): ClientInterface
    {
        return new class implements ClientInterface {
            #[\Override]
            public function sendRequest(HttpRequestInterface $request): ResponseInterface
            {
                throw new class ('Connection timed out.') extends RuntimeException implements NetworkExceptionInterface {
                    #[\Override]
                    public function getRequest(): HttpRequestInterface
                    {
                        throw new RuntimeException('The request is not what this test asks about.');
                    }
                };
            }
        };
    }
}
