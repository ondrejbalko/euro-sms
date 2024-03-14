<?php

namespace EuroSms\Gateway\Request;

use DateTime;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;

class RequestOne extends RequestAbstract implements RequestInterface
{
    /** @var int $amount */
    protected int $amount = 0;

    /** @var string $content */
    protected string $content;

    /** @var Flag $flag */
    protected Flag $flag;

    /** @var bool $isUnicode */
    protected bool $isUnicode = false;

    /** @var Recipient */
    protected Recipient $recipient;

    /** @var DateTime $scheduleDateTime */
    protected DateTime $scheduleDateTime;

    /** @var string $sign */
    protected string $sign;

    /** @var int $ttl */
    protected int $ttl;

    /**
     * @return int
     * @throws RequestException
     */
    public function getAmount(): int
    {
        if (!isset($this->data[GatewayInterface::FIELD_TEXT])) {
            throw new RequestException('Message not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        return $this->amount;
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, string|int|null>
     * @throws MessageException
     * @throws RecipientException
     */
    public function getData(): array
    {
        if (!isset($this->recipient)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        if (!isset($this->senderName)) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        $this->sign = $this->calcSignature(
            $this->senderName,
            (string)$this->recipient->getNumberClean(),
            $this->content
        );

        return array_filter([
            GatewayInterface::FIELD_CLIENT_ID => $this->clientId,
            GatewayInterface::FIELD_SIGN => $this->sign,
            GatewayInterface::FIELD_RECIPIENT => $this->recipient->getNumberClean(),
            GatewayInterface::FIELD_FLAGS => $this->flag->getValue(),
            GatewayInterface::FIELD_TTL => $this->ttl ?? null,
            GatewayInterface::FIELD_SENDER_NAME => $this->senderName,
            GatewayInterface::FIELD_SCHEDULE => isset($this->scheduleDateTime) ? $this->scheduleDateTime->format('Y-m-d H:i') : null,
            GatewayInterface::FIELD_TEXT => $this->content
        ]);
    }

    /**
     * @return Flag
     */
    public function getFlag(): Flag
    {
        return $this->flag;
    }

    /**
     * @return Recipient
     */
    public function getRecipient(): Recipient
    {
        return $this->recipient;
    }

    /**
     * @return DateTime
     */
    public function getScheduleDateTime(): DateTime
    {
        return $this->scheduleDateTime;
    }

    /**
     * @return string
     */
    public function getSenderName(): string
    {
        return $this->senderName;
    }

    /**
     * @return string
     */
    public function getSign(): string
    {
        return $this->sign;
    }

    /**
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * @return bool
     */
    public function isUnicode(): bool
    {
        return $this->isUnicode;
    }

    /**
     * @param Recipient $recipient
     * @return void
     */
    public function setRecipient(Recipient $recipient): void
    {
        $this->recipient = $recipient;
    }

    /**
     * @param string|null $content
     * @param Flag $flag
     * @param bool $isUnicode
     * @return void
     * @throws RequestException
     */
    public function setContent(?string $content, Flag $flag, bool $isUnicode = false): void
    {
        if (null === $content || '' === $content) {
            throw new RequestException('Message text not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        $this->content = $content;
        $this->flag = clone $flag;
        $this->isUnicode = $isUnicode;

        $this->setContentAmount();
    }

    /**
     * calculates the amount of SMS needed to send your message
     * @return void
     */
    protected function setContentAmount(): void
    {
        $messageLength = GatewayInterface::MAX_MESSAGE_LENGTH;
        $nextMessageLength = GatewayInterface::MAX_NEXT_MESSAGE_LENGTH;

        $textLength = strlen($this->content);

        if ($this->isUnicode()) {
            $messageLength = GatewayInterface::MAX_UNICODE_MESSAGE_LENGTH;
            $nextMessageLength = GatewayInterface::MAX_UNICODE_NEXT_MESSAGE_LENGTH;

            $textLength = mb_strlen($this->content, 'utf-8');
        }

        $this->amount = (int)ceil(max(0, $textLength - $messageLength) / $nextMessageLength) + 1;

        if ($this->amount > 1) {
            $this->getFlag()->addLong();
        }

        if ($this->isUnicode()) {
            $this->getFlag()->addUnicodeShort();

            if ($this->amount > 1) {
                $this->getFlag()->addUnicodeLong();
            }
        }
    }

    /**
     * @param DateTime $scheduleDateTime
     * @return void
     */
    public function setScheduleDateTime(DateTime $scheduleDateTime): void
    {
        $this->scheduleDateTime = $scheduleDateTime;
    }

    /**
     * @param int $ttl
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }
}
