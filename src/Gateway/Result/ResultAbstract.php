<?php

namespace EuroSms\Gateway\Result;

use EuroSms\Entities\Message\Message;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Response\ResponseCollection;

abstract class ResultAbstract
{
    /**
     * @var array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    protected array $denied = [];

    /**
     * @var array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    protected array $failed = [];

    /**
     * @var array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    protected array $sent = [];

    /**
     * @param Message $message
     * @param RequestCollection $requestCollection
     * @param ResponseCollection $responseCollection
     */
    public function __construct(
        protected Message $message,
        protected RequestCollection $requestCollection,
        protected ResponseCollection $responseCollection
    ) {
        $this->parse();
    }

    /**
     * @return array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    public function getDenied(): array
    {
        return $this->denied;
    }

    /**
     * @return array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return Message
     */
    public function getMessage(): Message
    {
        return $this->message;
    }

    /**
     * @return RequestCollection
     */
    public function getRequestCollection(): RequestCollection
    {
        return $this->requestCollection;
    }

    /**
     * @return ResponseCollection
     */
    public function getResponseCollection(): ResponseCollection
    {
        return $this->responseCollection;
    }

    /**
     * @return array<string, array<int, array<string, array<int, string>|int|string|null>>>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * @return void
     */
    abstract protected function parse(): void;
}
