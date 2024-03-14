<?php

namespace EuroSms;

class Config
{
    /**
     * @var bool
     */
    private bool $debugMode = false;

    /**
     * @var string
     */
    private string $id;

    /**
     * @var string
     */
    private string $key;

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
     * @var bool
     */
    private bool $testMode = false;

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getKey(): ?string
    {
        return $this->key ?? null;
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
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * @param bool $debugMode
     * @return void
     */
    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
    }

    /**
     * @param string $id
     * @return void
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * @param string $key
     * @return void
     */
    public function setKey(string $key): void
    {
        $this->key = $key;
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
     * @param bool $testMode
     * @return void
     */
    public function setTestMode(bool $testMode): void
    {
        $this->testMode = $testMode;
    }
}
