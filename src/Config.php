<?php

declare(strict_types=1);

namespace EuroSms;

use EuroSms\Enums\ResponseFormatEnum;
use EuroSms\Exception\ConfigException;

class Config
{
    /**
     * @var string
     */
    private string $id;

    /**
     * @var string
     */
    private string $key;

    /**
     * How many batches of one send may be on the wire at the same time.
     * @var int
     */
    private int $requestConcurrency = EuroSmsInterface::REQUEST_CONCURRENCY;

    /**
     * @var string
     */
    private string $requestContentType = EuroSmsInterface::REQUEST_CONTENT_TYPE;

    /**
     * @var float
     */
    private float $requestTimeout = EuroSmsInterface::REQUEST_TIMEOUT;

    /**
     * @var bool
     */
    private bool $requestVerifyHost = EuroSmsInterface::REQUEST_VERIFY_HOST;

    /**
     * How much the gateway is asked to answer with, chapter 9.2.1. The gateway itself falls back
     * to the basic answer, so that is what the library states unless the result needs more.
     * @var ResponseFormatEnum
     */
    private ResponseFormatEnum $responseFormat = ResponseFormatEnum::BASIC;

    /**
     * @var bool
     */
    private bool $testMode = false;

    /**
     * The integration the requests are sent under, chapter 4.1. A configuration that was never
     * given one hands out nothing, the way it does with the key, rather than reading a property
     * that was never written to and blowing up with an Error nobody catches.
     * @return string|null
     */
    public function getId(): ?string
    {
        return $this->id ?? null;
    }

    /**
     * @return string|null
     */
    public function getKey(): ?string
    {
        return $this->key ?? null;
    }

    /**
     * @return int
     */
    public function getRequestConcurrency(): int
    {
        return $this->requestConcurrency;
    }

    /**
     * @return string
     */
    public function getRequestContentType(): string
    {
        return $this->requestContentType;
    }

    /**
     * @return float
     */
    public function getRequestTimeout(): float
    {
        return $this->requestTimeout;
    }

    /**
     * @return bool
     */
    public function getRequestVerifyHost(): bool
    {
        return $this->requestVerifyHost;
    }

    /**
     * @return ResponseFormatEnum
     */
    public function getResponseFormat(): ResponseFormatEnum
    {
        return $this->responseFormat;
    }

    /**
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * The integration the requests are sent under, chapter 4.1. A name that says nothing is not
     * one: it is refused here rather than written into "iid" and answered with WRONG_IID after
     * the send, the same way a concurrency of nought is refused rather than taken.
     * @param string $id
     * @return void
     * @throws ConfigException when the integration id says nothing
     */
    public function setId(string $id): void
    {
        if ('' === trim($id)) {
            throw new ConfigException('Id not specified.');
        }

        $this->id = $id;
    }

    /**
     * The key every request is signed with, chapter 4.2. An empty one is refused rather than kept,
     * and it is worth more than the refused sends it saves: the service only ever asked whether a
     * key had been set at all, so an empty one passed, signed every request into a WRONG_SIGNATURE
     * and — because the log masks a value by comparing it against the key — turned every empty
     * field of every logged body into "***" on the way.
     * @param string $key
     * @return void
     * @throws ConfigException when the integration key says nothing
     */
    public function setKey(string $key): void
    {
        if ('' === trim($key)) {
            throw new ConfigException('Key not specified.');
        }

        $this->key = $key;
    }

    /**
     * How many batches of one send may stand at the gateway at the same time. One sends them the
     * way they used to go out, one after another; anything above that is how many connections the
     * library is allowed to open at once, and the gateway is a shared service — a number picked to
     * make one send as fast as possible is picked at the expense of everything else talking to it.
     *
     * Nought and less is refused rather than taken: a send that may have no call on the wire is a
     * send that never happens, and the number would sit in the configuration until something else
     * blamed the gateway for it.
     * @param int $requestConcurrency
     * @return void
     * @throws ConfigException when asked for fewer than one call at a time
     */
    public function setRequestConcurrency(int $requestConcurrency): void
    {
        if (1 > $requestConcurrency) {
            throw new ConfigException(sprintf('At least one call at a time is needed, %d given.', $requestConcurrency));
        }

        $this->requestConcurrency = $requestConcurrency;
    }

    /**
     * @param string $requestContentType
     */
    public function setRequestContentType(string $requestContentType): void
    {
        $this->requestContentType = $requestContentType;
    }

    /**
     * @param float $requestTimeout
     */
    public function setRequestTimeout(float $requestTimeout): void
    {
        $this->requestTimeout = $requestTimeout;
    }

    /**
     * @param bool $requestVerifyHost
     */
    public function setRequestVerifyHost(bool $requestVerifyHost): void
    {
        $this->requestVerifyHost = $requestVerifyHost;
    }

    /**
     * A send whose result is read number by number asks for the full answer no matter what is
     * set here, chapter 9.3.11, so this decides the rest.
     * @param ResponseFormatEnum $responseFormat
     * @return void
     */
    public function setResponseFormat(ResponseFormatEnum $responseFormat): void
    {
        $this->responseFormat = $responseFormat;
    }

    /**
     * @param bool $testMode
     * @return void
     */
    public function setTestMode(bool $testMode): void
    {
        $this->testMode = $testMode;
    }
}
