<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use EuroSms\Config;
use EuroSms\Enums\ResponseFormatEnum;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Helpers\Signature;
use JsonSerializable;
use Ramsey\Uuid\Uuid;

/**
 * What a request is regardless of what it carries: the integration it is sent under, an identity
 * of its own so the answer can be matched back to it, the sender name and the length the sending
 * may be spread over. Everything about who the request is addressed to and what it says to them
 * is left to the classes below, and so is the body itself.
 */
abstract class RequestAbstract implements JsonSerializable
{
    /** @var string $clientId */
    protected string $clientId;

    /** @var string $duration */
    protected string $duration;

    /** @var string $id */
    protected string $id;

    /** @var string $messageId */
    protected string $messageId;

    /** @var string $senderName */
    protected string $senderName;

    /**
     * What is worth telling the caller about this request without refusing to send it.
     * @var list<string> $warnings
     */
    protected array $warnings = [];

    /**
     * Every request is sent under an integration, chapter 4.1, and there is no request without
     * one — a configuration carrying none is refused here rather than at the gateway.
     * @param Config $config
     * @throws ConfigException
     */
    public function __construct(protected readonly Config $config)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->clientId = $config->getId() ?? throw new ConfigException('Id not specified.');
    }

    /**
     * @return array<string, mixed>
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
        return Signature::calc((string)$this->config->getKey(), ...$data);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function getData(): array;

    /**
     * How long the sending is spread over, none when the caller asked for no spreading.
     * @return string|null
     */
    public function getDuration(): ?string
    {
        return $this->duration ?? null;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The message this request was built for, none when it was built for no single one. A
     * transaction carries a whole collection and is given no identifier of its own, chapter
     * 9.3.8, so it hands out nothing here rather than reading a property that was never written
     * to and blowing up with an Error nobody catches — the same answer getDuration() gives.
     * @return string|null
     */
    public function getMessageId(): ?string
    {
        return $this->messageId ?? null;
    }

    /**
     * The numbers the request is addressed to. One request names one, another a whole list and a
     * third the numbers of every message it carries, so none of that is decided here.
     * @return list<int|string>
     */
    abstract public function getRecipients(): array;

    /**
     * How much of an answer the request asks for, chapter 9.2.1. A request whose result is
     * read number by number needs more than the configuration may state, so it says so itself.
     * @return ResponseFormatEnum
     */
    public function getResponseFormat(): ResponseFormatEnum
    {
        return $this->config->getResponseFormat();
    }

    /**
     * What is worth telling the caller about this request without refusing to send it. A request
     * that is fine to send says nothing, so an empty list is the usual answer.
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Whether the request was given a sender name of its own. A message of a group transaction
     * may have none and fall back to the one of the whole group, chapter 9.3.8.
     * @return bool
     */
    public function hasSenderName(): bool
    {
        return isset($this->senderName);
    }

    /**
     * Whether there is anything to tell the caller about this request.
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__toArray();
    }

    /**
     * The length the sending of the whole request is spread over, hours and minutes as hh:mm,
     * chapter 9.2.1. A length shaped otherwise never reaches the gateway.
     * @param string $duration
     * @return void
     * @throws RequestException
     */
    public function setDuration(string $duration): void
    {
        if (1 !== preg_match(GatewayInterface::DURATION_PATTERN, $duration)) {
            throw new RequestException('Duration must be written as hh:mm.', RequestInterface::ERROR_DURATION_INVALID);
        }

        $this->duration = $duration;
    }

    /**
     * @param string $messageId
     * @return void
     */
    public function setMessageId(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    /**
     * @param string $senderName
     * @return void
     */
    public function setSenderName(string $senderName): void
    {
        $this->senderName = $senderName;
    }
}
