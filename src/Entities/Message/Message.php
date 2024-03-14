<?php

namespace EuroSms\Entities\Message;

use DateTime;
use DateTimeZone;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use Ramsey\Uuid\Uuid;
use Throwable;

class Message implements MessageInterface
{
    /** @var string $content */
    private string $content;

    /** @var DateTimeZone $dateTimeZone */
    private DateTimeZone $dateTimeZone;

    /** @var Flag $flag */
    private Flag $flag;

    /** @var string $id */
    private string $id;

    /** @var Recipient $recipient */
    private Recipient $recipient;

    /** @var RecipientCollection $recipientCollection */
    private RecipientCollection $recipientCollection;

    /**
     * UNIX scheduled sending timestamp
     * @var DateTime $scheduleDateTime
     */
    private DateTime $scheduleDateTime;

    /** @var string $senderName */
    private string $senderName;

    /**
     * time to live - mostly used for auth systems
     * if the recipient is not available till ttl expires, message won't be sent
     * @var int $ttl
     */
    private int $ttl;

    /**
     * Unicode's characters allowed
     * @var bool $unicode
     */
    private bool $unicode = false;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->flag = new Flag;

        try {
            $this->dateTimeZone = new DateTimeZone(date_default_timezone_get());
        } catch (Throwable) {
            $this->dateTimeZone = new DateTimeZone('UTC');
        }
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content ?? null;
    }

    /**
     * @return DateTimeZone
     */
    public function getDateTimeZone(): DateTimeZone
    {
        return $this->dateTimeZone;
    }

    /**
     * @return Flag
     */
    public function getFlag(): Flag
    {
        return $this->flag;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return Recipient
     * @throws RecipientException
     */
    public function getRecipient(): Recipient
    {
        if (!isset($this->recipient)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        return $this->recipient;
    }

    /**
     * @return RecipientCollection
     * @throws RecipientException
     */
    public function getRecipientCollection(): RecipientCollection
    {
        if (!isset($this->recipientCollection)) {
            throw new RecipientException('Recipient collection not defined.', RecipientInterface::ERROR_RECIPIENT_COLLECTION_NOT_DEFINED);
        }

        return $this->recipientCollection;
    }

    /**
     * @return DateTime|null
     */
    public function getScheduleDateTime(): ?DateTime
    {
        return $this->scheduleDateTime ?? null;
    }

    /**
     * @return string|null
     */
    public function getSenderName(): ?string
    {
        return $this->senderName ?? null;
    }

    /**
     * @return int|null
     */
    public function getTtl(): ?int
    {
        return $this->ttl ?? null;
    }

    /**
     * @return bool
     */
    public function isUnicode(): bool
    {
        return $this->unicode;
    }

    /**
     * @param DateTime $scheduleDateTime
     * @param DateTimeZone|null $dateTimeZone
     * @return void
     */
    public function setScheduleDateTime(DateTime $scheduleDateTime, ?DateTimeZone $dateTimeZone = null): void
    {
        if (null !== $dateTimeZone) {
            $this->setDateTimeZone($dateTimeZone);
        }

        $scheduleDateTime->setTimezone($this->getDateTimeZone());

        $this->scheduleDateTime = $scheduleDateTime;
    }

    /**
     * @param string $content
     * @return void
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * @param DateTimeZone $dateTimeZone
     * @return void
     */
    public function setDateTimeZone(DateTimeZone $dateTimeZone): void
    {
        $this->dateTimeZone = $dateTimeZone;
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
     * @param RecipientCollection $recipientCollection
     * @return void
     * @throws MessageException
     */
    public function setRecipientCollection(RecipientCollection $recipientCollection): void
    {
        if ($recipientCollection->count() === 0) {
            throw new MessageException('Recipient collection is empty.', MessageInterface::ERROR_SENDER_COLLECTION_IS_EMPTY);
        }

        $this->recipientCollection = $recipientCollection;
    }

    /**
     * @param string $senderName
     * @return void
     */
    public function setSenderName(string $senderName): void
    {
        $this->senderName = substr($senderName, 0, MessageInterface::MAX_SENDER_NAME_LENGTH);
    }

    /**
     * in seconds
     * @param int $ttl
     * @return void
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }

    /**
     * @param bool $unicode
     * @return void
     */
    public function setUnicode(bool $unicode): void
    {
        $this->unicode = $unicode;
    }
}
