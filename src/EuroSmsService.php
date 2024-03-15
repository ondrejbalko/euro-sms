<?php

namespace EuroSms;

use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Exception\SendException;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseOne;
use EuroSms\Gateway\Response\ResponseOneToMany;
use EuroSms\Gateway\Result\ResultManyToMany;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Gateway\Result\ResultOneToMany;
use EuroSms\Helpers\Percolator;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class EuroSmsService
{
    /** @var Client $client */
    private Client $client;

    /** @var Percolator $percolator */
    private Percolator $percolator;

    /** @var RequestCollection $requestCollection */
    private RequestCollection $requestCollection;

    /** @var ResponseCollection $responseCollection */
    private ResponseCollection $responseCollection;

    /**
     * @param Config $config
     * @throws ConfigException
     */
    public function __construct(private readonly Config $config)
    {
        if (null === $this->config->getKey()) {
            throw new ConfigException('Key not specified.');
        }

        $this->client = new Client([
            'base_uri' => EuroSmsInterface::API_HOST,
            RequestOptions::TIMEOUT  => $this->config->getRequestTimeout(),
            RequestOptions::HEADERS => [
                'Accept' => $this->config->getRequestContentType(),
                'Content-Type' => $this->config->getRequestContentType()
            ]
        ]);

        $this->percolator = new Percolator($this->config);
        $this->requestCollection = new RequestCollection;
        $this->responseCollection = new ResponseCollection;
    }

    /**
     * @param string $endpoint
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws SendException
     */
    private function doRequest(string $endpoint, RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->post($this->getEndpoint($endpoint), [
                RequestOptions::JSON => $request,
                RequestOptions::VERIFY => EuroSmsInterface::REQUEST_VERIFY_HOST
            ]);
        } catch (Throwable $e) {
            throw new SendException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param string $endpoint
     * @return string
     */
    private function getEndpoint(string $endpoint): string
    {
        if (!$this->config->isTestMode()) {
            return $endpoint;
        }

        $mapper = [
            EuroSmsInterface::ENDPOINT_SEND_ONE => EuroSmsInterface::ENDPOINT_TEST_ONE,
            EuroSmsInterface::ENDPOINT_SEND_ONE_TO_MANY => EuroSmsInterface::ENDPOINT_TEST_ONE_TO_MANY,
            EuroSmsInterface::ENDPOINT_SEND_MANY_TO_MANY => EuroSmsInterface::ENDPOINT_TEST_MANY_TO_MANY
        ];

        return $mapper[$endpoint] ?? $endpoint;
    }

    /**
     * @param MessageCollection $messageCollection
     * @return ResultManyToMany
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     * @throws SendException
     */
    public function sendManyToMany(MessageCollection $messageCollection): ResultManyToMany
    {
        foreach ($messageCollection->all() as $message) {
            $recipientCollection = $message->getRecipientCollection();
            $unique = $this->percolator->getUniqueRecipients($recipientCollection);
            $message->setRecipientCollection($unique);

            foreach ($this->percolator->getRecipientBatches($unique) as $batch) {
                $request = $this->percolator->createRequestOneToMany($message, $batch);
                $this->requestCollection->offsetSet($request->getId(), $request);
            }
        }

        $this->requestOneToMany();

        return new ResultManyToMany($messageCollection, $this->requestCollection, $this->responseCollection);
    }

    /**
     * @param Message $message
     * @return ResultOne
     * @throws Exception\RecipientException
     * @throws Exception\RequestException
     * @throws SendException
     */
    public function sendOne(Message $message): ResultOne
    {
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
    public function sendOneToMany(Message $message): ResultOneToMany
    {
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
     * @return void
     * @throws SendException
     */
    private function requestOne(): void
    {
        foreach ($this->requestCollection->all() as $request) {
            $response = $this->doRequest(EuroSmsInterface::ENDPOINT_SEND_ONE, $request);
            $this->responseCollection->offsetSet($request->getId(), new ResponseOne($request->getId(), $response));
        }
    }

    /**
     * @return void
     * @throws SendException
     */
    private function requestOneToMany(): void
    {
        foreach ($this->requestCollection->all() as $request) {
            $response = $this->doRequest(EuroSmsInterface::ENDPOINT_SEND_ONE_TO_MANY, $request);
            $this->responseCollection->offsetSet($request->getId(), new ResponseOneToMany($request->getId(), $response));
        }
    }
}
