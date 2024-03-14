<?php

namespace EuroSms\Gateway\Response;

use EuroSms\Gateway\GatewayInterface;

abstract class ResponseAbstract
{
    /** @var array<string, mixed> $body */
    protected array $body;

    /** @var array<string, string> $errors */
    protected array $errors = [];

    /** @var bool $sent */
    protected bool $sent = false;

    /**
     * @param string $requestId
     * @param \Psr\Http\Message\ResponseInterface $clientResponse
     */
    public function __construct(protected string $requestId, protected \Psr\Http\Message\ResponseInterface $clientResponse)
    {
        $this->body = (array)json_decode($this->clientResponse->getBody()->__toString(), true);

        if (array_key_exists('err_code', $this->body)) {
            $this->sent = $this->body['err_code'] === GatewayInterface::RESPONSE_ENQUEUED;
        } else {
            $this->errors['NO_RESPONSE'] = 'Something went wrong, try again';
        }

        if (array_key_exists('err_list', $this->body)) {
            array_walk($this->body['err_list'], function (array $error) {
                $this->errors[(string)$error['err_code']] = (string)$error['err_desc'];
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getClientResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->clientResponse;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @return bool
     */
    public function isSent(): bool
    {
        return $this->sent;
    }
}
