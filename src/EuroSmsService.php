<?php

declare(strict_types=1);

namespace EuroSms;

use Closure;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Enums\EndpointEnum;
use EuroSms\Enums\RequestMethodEnum;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Exception\SendException;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseInterface as GatewayResponseInterface;
use EuroSms\Gateway\Response\ResponseManyToMany;
use EuroSms\Gateway\Response\ResponseOne;
use EuroSms\Gateway\Response\ResponseOneToMany;
use EuroSms\Gateway\Result\ResultManyToMany;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Gateway\Result\ResultOneToMany;
use EuroSms\Gateway\Status\StatusRequest;
use EuroSms\Gateway\Status\StatusResponse;
use EuroSms\Helpers\GuzzleClientFactory;
use EuroSms\Helpers\Percolator;
use EuroSms\Helpers\Redactor;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface as HttpRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Throwable;

class EuroSmsService
{
    /**
     * The first status code that says the call did not go through.
     * @var int
     */
    private const int HTTP_ERROR_STATUS = 400;

    /**
     * What a record of a call is written under. The three of them are one message each, so that
     * a log can be read by the message and everything that tells one call from another stays in
     * the context where a PSR-3 logger expects it.
     * @var string
     */
    private const string LOG_REQUEST = 'EuroSMS request.';

    /** @var string */
    private const string LOG_RESPONSE = 'EuroSMS response.';

    /** @var string */
    private const string LOG_FAILED = 'EuroSMS call failed.';

    /**
     * What a call the gateway refused is reported as.
     * @var string
     */
    private const string ERROR_HTTP_STATUS = 'Gateway answered with http status %d.';

    /**
     * What a batch that came back as nothing at all is reported as. A rejected call normally
     * carries the reason the transport failed for; this is what is said when it carries none.
     * @var string
     */
    private const string ERROR_BATCH_FAILED = 'Batch was not sent.';

    /** @var ClientInterface $client */
    private ClientInterface $client;

    /**
     * Whatever the caller handed over, and nothing when it handed over nothing: a library that
     * was given no logger writes nowhere rather than into a logger of its own.
     * @var LoggerInterface|null $logger
     */
    private ?LoggerInterface $logger;

    /** @var Percolator $percolator */
    private Percolator $percolator;

    /** @var Redactor $redactor */
    private Redactor $redactor;

    /** @var RequestCollection $requestCollection */
    private RequestCollection $requestCollection;

    /** @var RequestFactoryInterface $requestFactory */
    private RequestFactoryInterface $requestFactory;

    /** @var ResponseCollection $responseCollection */
    private ResponseCollection $responseCollection;

    /** @var StatusRequest $statusRequest */
    private StatusRequest $statusRequest;

    /** @var StreamFactoryInterface $streamFactory */
    private StreamFactoryInterface $streamFactory;

    /**
     * The transport is whatever the caller hands over: any PSR-18 client with the PSR-17
     * factories the requests are built by. A caller that hands over none gets the Guzzle client
     * the library ships with, configured out of the configuration.
     *
     * The logger is the same kind of thing: any PSR-3 logger is written to, and a caller that
     * hands over none is written nothing at all. What goes out and what comes back is recorded
     * at the debug level and a call that did not go through at the error level, so which of the
     * two ends up anywhere is the logger's own threshold to decide and not a switch of ours.
     * @param Config $config
     * @param ClientInterface|null $client
     * @param RequestFactoryInterface|null $requestFactory
     * @param StreamFactoryInterface|null $streamFactory
     * @param LoggerInterface|null $logger
     * @throws ConfigException
     */
    public function __construct(
        private readonly Config $config,
        ?ClientInterface $client = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null
    ) {
        if (null === $this->config->getId()) {
            throw new ConfigException('Id not specified.');
        }

        if (null === $this->config->getKey()) {
            throw new ConfigException('Key not specified.');
        }

        $this->client = $client ?? GuzzleClientFactory::createClient($this->config);
        $this->requestFactory = $requestFactory ?? GuzzleClientFactory::createFactory();
        $this->streamFactory = $streamFactory ?? GuzzleClientFactory::createFactory();
        $this->logger = $logger;

        $this->redactor = new Redactor((string)$this->config->getKey());
        $this->percolator = new Percolator($this->config);
        $this->statusRequest = new StatusRequest($this->config);
        $this->start();
    }

    /**
     * A request as PSR-7 states it: the address of the gateway written out in full, because a
     * client handed over from outside knows of no base address of ours, and the content type the
     * configuration asks the answer in.
     * @param RequestMethodEnum $method
     * @param string $path
     * @return HttpRequestInterface
     * @throws InvalidArgumentException when the factory refuses the address or a header
     */
    private function createHttpRequest(RequestMethodEnum $method, string $path): HttpRequestInterface
    {
        return $this->requestFactory
            ->createRequest($method->value, EuroSmsInterface::API_HOST . $path)
            ->withHeader('Accept', $this->config->getRequestContentType())
            ->withHeader('Content-Type', $this->config->getRequestContentType());
    }

    /**
     * One batch written out as PSR-7 states it, and recorded on the way out. Building it is kept
     * apart from sending it because the batches of one send go out together: what every one of
     * them is is known before any of them is on the wire.
     * @param string $path
     * @param RequestInterface $request
     * @return HttpRequestInterface
     * @throws SendException when the body cannot be composed or the factory refuses the request
     */
    private function createBatchRequest(string $path, RequestInterface $request): HttpRequestInterface
    {
        try {
            /**
             * The body is kept as the array it was composed as, not read back out of the JSON it
             * became. The two are the same thing, and decoding a batch of a thousand recipients a
             * second time only to mask it for a log copied the whole of it once more on the way
             * out of every send.
             */
            $data = $request->jsonSerialize();
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            $httpRequest = $this->createHttpRequest(RequestMethodEnum::POST, $path)
                ->withBody($this->streamFactory->createStream($json));
        } catch (Throwable $e) {
            $this->logFailure($path, $e->getMessage(), $request->getId());

            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }

        $this->logger?->debug(self::LOG_REQUEST, [
            'method' => RequestMethodEnum::POST->value,
            'endpoint' => $this->redactor->path($path),
            'request' => $request->getId(),
            'body' => $this->redactor->data($data)
        ]);

        return $httpRequest;
    }

    /**
     * What a call that never came back is reported as. A rejected call carries whatever the
     * transport threw, and that is what the caller is told; a rejection carrying something else
     * is still a batch that did not go out and is reported as one rather than swallowed.
     * @param string $path
     * @param mixed $reason
     * @param string|null $requestId the batch this happened to, none when the call was no batch
     * @return SendException
     */
    private function createFailure(string $path, mixed $reason, ?string $requestId = null): SendException
    {
        $throwable = $reason instanceof Throwable ? $reason : null;
        $message = null === $throwable ? self::ERROR_BATCH_FAILED : $throwable->getMessage();

        $this->logFailure($path, $message, $requestId);

        return new SendException($message, null === $throwable ? 0 : $throwable->getCode(), $throwable);
    }

    /**
     * The whole of one send: every request the message was split into is posted, and what came
     * back is filed under the request it answers.
     *
     * The batches travel together, at most as many of them at once as the configuration allows,
     * because a send to ten thousand numbers used to be ten calls one after another and blocked
     * the caller for the sum of their round trips. One batch that fails no longer takes the rest
     * with it either: its reason is filed next to the answers and the result reports its numbers
     * as denied, so a partly sent batch of numbers is something the caller can act on instead of
     * something it has to guess at.
     *
     * A send in which nothing at all got through is still the exception it always was. There is
     * no partial outcome to report there, and a caller that catches a failed send keeps catching
     * one.
     * @param EndpointEnum $endpoint
     * @param Closure(string, ResponseInterface): GatewayResponseInterface $responseFactory
     * @return void
     * @throws SendException when not one batch of the send was answered
     */
    private function dispatch(EndpointEnum $endpoint, Closure $responseFactory): void
    {
        $path = $this->getEndpoint($endpoint);
        $failures = [];
        $httpRequests = [];

        foreach ($this->requestCollection->all() as $request) {
            try {
                $httpRequests[$request->getId()] = $this->createBatchRequest($path, $request);
            } catch (SendException $e) {
                $failures[$request->getId()] = $e;
            }
        }

        /**
         * The answers are filed in the order the batches were built and not in the order they
         * happened to come back, so that a send answered by the gateway in one order and by the
         * same gateway in another the next time still reads the same way round.
         */
        $outcomes = $this->sendBatches($path, $httpRequests);

        foreach (array_keys($httpRequests) as $requestId) {
            $outcome = $outcomes[$requestId] ?? $this->createFailure($path, null, $requestId);

            if ($outcome instanceof SendException) {
                $failures[$requestId] = $outcome;

                continue;
            }

            try {
                $response = $this->readResponse($path, $outcome, $requestId);
                $this->responseCollection->offsetSet($requestId, $responseFactory($requestId, $response));
            } catch (SendException $e) {
                $failures[$requestId] = $e;
            } catch (Throwable $e) {
                /**
                 * Reading an answer is more than reading its status. The body is taken off a
                 * PSR-7 stream and decoded, and a stream that was detached or cannot be read
                 * throws something that is no send exception of ours — which walked out of the
                 * whole loop, left the batches after this one unfiled and the failures unwritten,
                 * and handed the caller a RuntimeException in the place of a partial result.
                 */
                $failures[$requestId] = $this->createFailure($path, $e, $requestId);
            }
        }

        $failures = $this->orderFailures($failures);

        foreach ($failures as $requestId => $failure) {
            $this->responseCollection->addFailure($requestId, $failure);
        }

        $first = reset($failures);

        if (false !== $first && 0 === $this->responseCollection->count()) {
            throw $first;
        }
    }

    /**
     * The failures of one send read back in the order the batches were built, whichever way round
     * they happened. A send that failed entirely is reported to the caller by the first of them,
     * and they used to be filed in two passes — everything that could not be composed first,
     * everything that did not come back afterwards — so a send of three batches of which two timed
     * out and one carried a text that would not encode was thrown as the encoding error. The
     * caller's log then named a malformed text for a send that died on the network, and the
     * reasons that were true of it are only reachable through a result a throwing send never
     * hands out.
     * @param array<string, SendException> $failures
     * @return array<string, SendException>
     */
    private function orderFailures(array $failures): array
    {
        $ordered = [];

        foreach ($this->requestCollection->all() as $request) {
            $requestId = $request->getId();

            if (isset($failures[$requestId])) {
                $ordered[$requestId] = $failures[$requestId];
            }
        }

        /**
         * Everything the collection did not name keeps whatever order it had. Ordering a list is
         * no reason to lose an entry of it.
         */
        return $ordered + $failures;
    }

    /**
     * A status operation carries everything it needs in its path, so it is read with GET and
     * posts no body at all.
     * @param string $uri
     * @return ResponseInterface
     * @throws SendException
     */
    private function doStatusRequest(string $uri): ResponseInterface
    {
        try {
            $httpRequest = $this->createHttpRequest(RequestMethodEnum::GET, $uri);
        } catch (Throwable $e) {
            $this->logFailure($uri, $e->getMessage());

            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }

        $this->logger?->debug(self::LOG_REQUEST, [
            'method' => RequestMethodEnum::GET->value,
            'endpoint' => $this->redactor->path($uri),
            'request' => null,
            'body' => null
        ]);

        return $this->send($uri, $httpRequest);
    }

    /**
     * @param EndpointEnum $endpoint
     * @return string
     */
    private function getEndpoint(EndpointEnum $endpoint): string
    {
        return $this->config->isTestMode() ? $endpoint->toTest()->value : $endpoint->value;
    }

    /**
     * The delivery status of whatever changed since this was last asked, chapter 9.4.3, no
     * matter which send the message came from. A message that has reached a final status is
     * reported once and not again, so what comes back is read now rather than asked for twice.
     *
     * That is also why the transaction number can be named. The gateway tells one call from
     * another by it, and a call whose answer never arrived — a timeout, a 502, a process that died
     * holding it — took its reports with it: asked again under a number of its own, the call reads
     * whatever changed since, and what it had already reported once is not among it. Asked again
     * under the same number, it is. A caller that names none is given a generated one, which is
     * what every call did before and what a caller with nothing to recover still wants.
     * @param string|null $transaction the transaction number, a generated one when none is given
     * @return StatusResponse
     * @throws RandomException
     * @throws RequestException when the transaction number is not of the documented length
     * @throws SendException
     */
    #[\NoDiscard('the delivery reports are the only thing this call produces')]
    public function getStatusAny(?string $transaction = null): StatusResponse
    {
        return new StatusResponse($this->doStatusRequest($this->statusRequest->any($transaction)));
    }

    /**
     * The delivery status of the messages of one group transaction, chapter 9.4.4. The group is
     * the one the o2m or m2m result was filed under.
     * @param int $groupId
     * @return StatusResponse
     * @throws SendException
     */
    #[\NoDiscard('the delivery reports are the only thing this call produces')]
    public function getStatusGroup(int $groupId): StatusResponse
    {
        return new StatusResponse($this->doStatusRequest($this->statusRequest->group($groupId)));
    }

    /**
     * The delivery status of one message, chapter 9.4.1, one report per segment it was split
     * into. An identifier the gateway knows nothing about is answered with no reports.
     * @param string $uuid one of the identifiers the send answered with
     * @return StatusResponse
     * @throws RequestException
     * @throws SendException
     */
    #[\NoDiscard('the delivery reports are the only thing this call produces')]
    public function getStatusOne(string $uuid): StatusResponse
    {
        return new StatusResponse($this->doStatusRequest($this->statusRequest->one($uuid)));
    }

    /**
     * A PSR-18 client answers a call the gateway refused rather than throwing at it, chapter 3 of
     * the standard, so the status has to be read here. Anything the gateway did not accept is the
     * send exception the caller has always been able to catch, rather than an answer that decodes
     * into nothing and reads as "the gateway knows of no such message".
     *
     * The body of such an answer is what the gateway states the refusal in, chapter 11, so it
     * travels on the exception instead of being thrown away with the response it arrived in.
     * @param string $path
     * @param ResponseInterface $response
     * @param string|null $requestId the batch this answers, none when the call was no batch
     * @return ResponseInterface
     * @throws SendException
     */
    private function readResponse(
        string $path,
        ResponseInterface $response,
        ?string $requestId = null
    ): ResponseInterface {
        $status = $response->getStatusCode();

        if (self::HTTP_ERROR_STATUS <= $status) {
            $body = self::readBody($response);
            $message = sprintf(self::ERROR_HTTP_STATUS, $status);

            $this->logger?->error(self::LOG_FAILED, [
                'endpoint' => $this->redactor->path($path),
                'request' => $requestId,
                'status' => $status,
                'body' => $body,
                'reason' => $message
            ]);

            throw new SendException($message, $status, null, $status, $body);
        }

        if (null !== $this->logger) {
            $this->logger->debug(self::LOG_RESPONSE, [
                'endpoint' => $this->redactor->path($path),
                'request' => $requestId,
                'status' => $status,
                'body' => self::readBody($response)
            ]);
        }

        return $response;
    }

    /**
     * The answer as it came off the wire, put back where it was found: the result is built out of
     * that same stream afterwards, so reading it for a log may not consume it. A body that cannot
     * be rewound is not read at all — taking it away from the caller to write it into a log would
     * cost more than the record is worth.
     * @param ResponseInterface $response
     * @return string|null
     */
    private static function readBody(ResponseInterface $response): ?string
    {
        $stream = $response->getBody();

        if (!$stream->isSeekable()) {
            return null;
        }

        $body = $stream->__toString();
        $stream->rewind();

        return $body;
    }

    /**
     * A call that did not go through is written at the error level whether or not anything reads
     * the debug one, because it is the record somebody goes looking for afterwards. Such a call
     * has no answer to report, so it carries neither a status nor a body.
     *
     * It names the batch it happened to. The batches of one send travel together and are answered
     * in whatever order the gateway answers them, so the endpoint alone — the same string for
     * every batch of the send — no longer says which of them this record is about, and the numbers
     * that never went out could not be found in the log at all.
     * @param string $path
     * @param string $reason
     * @param string|null $requestId the batch this happened to, none when the call was no batch
     * @return void
     */
    private function logFailure(string $path, string $reason, ?string $requestId = null): void
    {
        $this->logger?->error(self::LOG_FAILED, [
            'endpoint' => $this->redactor->path($path),
            'request' => $requestId,
            'status' => null,
            'body' => null,
            'reason' => $reason
        ]);
    }

    /**
     * What a status call is: the one call to the client, the failure it may turn out to be, and
     * the answer read out of it. A send goes through the batches instead, because there is more
     * than one of them and they do not wait for each other.
     * @param string $path
     * @param HttpRequestInterface $httpRequest
     * @return ResponseInterface
     * @throws SendException
     */
    private function send(string $path, HttpRequestInterface $httpRequest): ResponseInterface
    {
        try {
            $response = $this->client->sendRequest($httpRequest);
        } catch (Throwable $e) {
            $this->logFailure($path, $e->getMessage());

            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }

        return $this->readResponse($path, $response);
    }

    /**
     * Every batch of one send put on the wire, with what came back for each of them: the answer
     * when the call went through, the failure when it did not. Nothing is thrown here — one batch
     * that never left may not take the rest of the send with it.
     *
     * How many of them travel at once is the configuration's to say. A client the library built
     * itself speaks Guzzle and the batches go out together; a PSR-18 client handed over from
     * outside states one call at a time and nothing else, so there they go out one after another
     * and are reported exactly the same way.
     * @param string $path
     * @param array<string, HttpRequestInterface> $httpRequests
     * @return array<string, ResponseInterface|SendException>
     */
    private function sendBatches(string $path, array $httpRequests): array
    {
        $client = $this->client;

        if ([] === $httpRequests) {
            return [];
        }

        if (!$client instanceof GuzzleClientInterface) {
            return $this->sendBatchesSerially($path, $httpRequests);
        }

        /** @var array<string, ResponseInterface|SendException> $outcomes */
        $outcomes = [];

        $pool = new Pool($client, $httpRequests, [
            'concurrency' => $this->config->getRequestConcurrency(),
            /**
             * The two options a PSR-18 call carries of its own, chapter 3 of the standard, stated
             * here because this call is not made through PSR-18: a refused call is an answer to be
             * read rather than a rejection, and a redirect is not followed behind the caller's
             * back. Without them the very same send would report a status of 400 one way when it
             * went out on its own and another way when it went out as one batch of several.
             */
            'options' => [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false
            ],
            'fulfilled' => static function (ResponseInterface $response, string $requestId) use (&$outcomes): void {
                $outcomes[$requestId] = $response;
            },
            'rejected' => function (mixed $reason, string $requestId) use (&$outcomes, $path): void {
                $outcomes[$requestId] = $this->createFailure($path, $reason, $requestId);
            }
        ]);

        /**
         * The pool answers every batch through the two callbacks above, so what it throws at the
         * end is not one batch failing but the pool itself giving up — a middleware that blew up,
         * a client that is no client, an Error out of the caller's own decoration. That travelled
         * straight out of the send as something no catch of this library names, against the one
         * exception dispatch() promises, with nothing written to the log and every batch that had
         * already come back thrown away with it. Whatever it is, it is the batches that did not
         * get an answer, and each of them is reported as one.
         */
        try {
            $pool->promise()->wait();
        } catch (Throwable $e) {
            foreach (array_keys($httpRequests) as $requestId) {
                $outcomes[$requestId] ??= $this->createFailure($path, $e, $requestId);
            }
        }

        return $outcomes;
    }

    /**
     * The batches one after another, for a transport that can do nothing else. A batch that fails
     * is reported the way a concurrent one is and the next batch is still sent.
     * @param string $path
     * @param array<string, HttpRequestInterface> $httpRequests
     * @return array<string, ResponseInterface|SendException>
     */
    private function sendBatchesSerially(string $path, array $httpRequests): array
    {
        $outcomes = [];

        foreach ($httpRequests as $requestId => $httpRequest) {
            try {
                $outcomes[$requestId] = $this->client->sendRequest($httpRequest);
            } catch (Throwable $e) {
                $outcomes[$requestId] = $this->createFailure($path, $e, $requestId);
            }
        }

        return $outcomes;
    }

    /**
     * @param MessageCollection $messageCollection
     * @return ResultManyToMany
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     * @throws SendException
     */
    #[\NoDiscard('the result says which messages were sent, which failed and which were denied')]
    public function sendManyToMany(MessageCollection $messageCollection): ResultManyToMany
    {
        $this->start();

        foreach ($this->percolator->createRequestManyToMany($messageCollection) as $request) {
            $this->requestCollection->offsetSet($request->getId(), $request);
        }

        $this->requestManyToMany();

        return new ResultManyToMany($messageCollection, $this->requestCollection, $this->responseCollection);
    }

    /**
     * @param Message $message
     * @return ResultOne
     * @throws Exception\RecipientException
     * @throws Exception\RequestException
     * @throws SendException
     */
    #[\NoDiscard('the result says which messages were sent, which failed and which were denied')]
    public function sendOne(Message $message): ResultOne
    {
        $this->start();

        $request = $this->percolator->createRequestOne($message);

        $this->requestCollection->offsetSet($request->getId(), $request);
        $this->requestOne();

        return new ResultOne($message, $this->requestCollection, $this->responseCollection);
    }

    /**
     * Send one message to many recipients. Messages are sent in bulks, 1 iteration sends to max 1000 recipients
     * @param Message $message
     * @return ResultOneToMany
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     * @throws SendException
     */
    #[\NoDiscard('the result says which messages were sent, which failed and which were denied')]
    public function sendOneToMany(Message $message): ResultOneToMany
    {
        $this->start();

        $recipientCollection = $message->getRecipientCollection();
        $unique = $this->percolator->getUniqueRecipients($recipientCollection);
        $message->setRecipientCollection($unique);

        foreach ($this->percolator->getRecipientBatches($unique) as $batch) {
            $request = $this->percolator->createRequestOneToMany($message, $batch);
            $this->requestCollection->offsetSet($request->getId(), $request);
        }

        $this->requestOneToMany();

        return new ResultOneToMany($message, $this->requestCollection, $this->responseCollection);
    }

    /**
     * A send starts from empty collections, so that what went out before is neither posted a
     * second time nor read back into the result of a later call.
     * @return void
     */
    private function start(): void
    {
        $this->requestCollection = new RequestCollection;
        $this->responseCollection = new ResponseCollection;
    }

    /**
     * @return void
     * @throws SendException
     */
    private function requestManyToMany(): void
    {
        $this->dispatch(
            EndpointEnum::SEND_MANY_TO_MANY,
            static fn (string $requestId, ResponseInterface $response): ResponseManyToMany
                => new ResponseManyToMany($requestId, $response)
        );
    }

    /**
     * @return void
     * @throws SendException
     */
    private function requestOne(): void
    {
        $this->dispatch(
            EndpointEnum::SEND_ONE,
            static fn (string $requestId, ResponseInterface $response): ResponseOne
                => new ResponseOne($requestId, $response)
        );
    }

    /**
     * @return void
     * @throws SendException
     */
    private function requestOneToMany(): void
    {
        $this->dispatch(
            EndpointEnum::SEND_ONE_TO_MANY,
            static fn (string $requestId, ResponseInterface $response): ResponseOneToMany
                => new ResponseOneToMany($requestId, $response)
        );
    }
}
