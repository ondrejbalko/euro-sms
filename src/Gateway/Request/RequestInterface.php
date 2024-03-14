<?php

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\Recipient\Recipient;
use JsonSerializable;

interface RequestInterface extends JsonSerializable
{
    const int ERROR_MESSAGE_NOT_DEFINED = 0;

    /**
     * @return array<string, string|int|null>
     */
    public function __toArray(): array;

    /**
     * @return array<string, string|int|null>
     */
    public function getData(): array;

    /**
     * @return string
     */
    public function getId(): string;

    /**
     * @return string
     */
    public function getMessageId(): string;

    /**
     * @return Recipient
     */
    public function getRecipient(): Recipient;

    /**
     * @return int[]
     */
    public function getRecipients(): array;

    /**
     * @return array<string, string|int|null>
     */
    public function jsonSerialize(): array;

    /**
     * @param string $messageId
     * @return void
     */
    public function setMessageId(string $messageId): void;

    /**
     * @param string $senderName
     * @return void
     */
    public function setSenderName(string $senderName): void;
}
