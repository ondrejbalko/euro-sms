<?php

namespace EuroSms\Gateway\Request;

use EuroSms\Config;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

abstract class RequestAbstract implements JsonSerializable
{
    /** @var string $clientId */
    protected string $clientId;

    /** @var string $id */
    protected string $id;

    /** @var string $messageId */
    protected string $messageId;

    /** @var string $senderName */
    protected string $senderName;

    /**
     * @param Config $config
     */
    public function __construct(protected readonly Config $config)
    {
        $this->id = Uuid::uuid4();
        $this->clientId = $config->getId();
    }

    /**
     * @return array<string, string|int|null>
     */
    public function __toArray(): array
    {
        return $this->getData();
    }

    /**
     * @param string|int ...$data
     * @return string
     */
    protected function calcSignature(string|int ...$data): string
    {
        return hash_hmac('sha1', implode('', $data), (string)$this->config->getKey());
    }

    /**
     * @return array<string, string|int|null>
     */
    abstract public function getData(): array;

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * @return int[]
     */
    public function getRecipients(): array
    {
        return [];
    }

    /**
     * @return array<string, string|int|null>
     */
    public function jsonSerialize(): array
    {
        return $this->__toArray();
    }

    /**
     * @param string $messageId
     */
    public function setMessageId(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    /**
     * @param string $senderName
     */
    public function setSenderName(string $senderName): void
    {
        $this->senderName = $senderName;
    }
}
