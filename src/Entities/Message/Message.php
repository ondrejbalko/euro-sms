<?php

declare(strict_types=1);

namespace EuroSms\Entities\Message;

use DateTime;
use DateTimeZone;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Helpers\Encoder;
use EuroSms\Helpers\SenderName;
use Ramsey\Uuid\Uuid;
use Throwable;

class Message implements MessageInterface
{
    /** @var string $content */
    private string $content;

    /** @var DateTimeZone $dateTimeZone */
    private DateTimeZone $dateTimeZone;

    /**
     * How long the sending of the whole batch is spread over, "dur" of chapter 9.2.1,
     * in hours and minutes.
     * @var string $duration
     */
    private string $duration;

    /**
     * When the sending of the whole batch is to be over, "end" of the example of chapter 10.2.1.
     * @var DateTime $end
     */
    private DateTime $end;

    /** @var Flag $flag */
    private Flag $flag;

    /** @var string $id */
    private string $id;

    /** @var InstantMessage $instantMessage */
    private InstantMessage $instantMessage;

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
     * Which alphabet the message goes out in, and none of the three answers is the plain yes or
     * no it looks like: null leaves it to the text, true and false force it either way.
     * @var bool|null $unicode
     */
    private ?bool $unicode = null;

    public function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
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
     * @return string|null
     */
    public function getDuration(): ?string
    {
        return $this->duration ?? null;
    }

    /**
     * @return DateTime|null
     */
    public function getEnd(): ?DateTime
    {
        return $this->end ?? null;
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
     * The Viber variant of the message, none when it carries none.
     * @return InstantMessage|null
     */
    public function getInstantMessage(): ?InstantMessage
    {
        return $this->instantMessage ?? null;
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
     * Whether the message goes out with diacritics. Told nothing either way, the text answers for
     * itself: one that can be written in GSM 03.38 is sent in it and holds a hundred and sixty
     * characters, one that cannot goes out with diacritics and holds seventy. A message that was
     * never given a text at all is no unicode message.
     * @return bool
     */
    public function isUnicode(): bool
    {
        return $this->unicode ?? !Encoder::isGsm($this->getContent() ?? '');
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
     * The text of the message. A send with nothing to say is answered by the gateway with
     * EMPTY_MESSAGE, and by then the message has travelled the whole way through the library to
     * find out something that was knowable the moment it was written. A text made of nothing but
     * whitespace is the same mistake wearing whatever a template left behind, and it is charged
     * for exactly like a message that said something.
     *
     * What is kept is the text as it was handed over, whitespace and all. Nothing is trimmed off
     * it: the signature of chapter 9.2.2 is composed over the text as it goes on the wire, so a
     * text quietly changed here would be one the gateway finds its own signature no longer
     * matches.
     * @param string $content
     * @return void
     * @throws MessageException
     */
    public function setContent(string $content): void
    {
        if ('' === trim($content)) {
            throw new MessageException('Message text is empty.', MessageInterface::ERROR_CONTENT_IS_EMPTY);
        }

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
     * Sending a batch all at once is what a gateway is asked not to do here: the messages are
     * spread over the length handed over, chapter 9.2.1, written in hours and minutes as hh:mm.
     * A length shaped otherwise is refused right here rather than by the gateway.
     * @param string $duration
     * @return void
     * @throws MessageException
     */
    public function setDuration(string $duration): void
    {
        if (1 !== preg_match(GatewayInterface::DURATION_PATTERN, $duration)) {
            throw new MessageException('Duration must be written as hh:mm.', MessageInterface::ERROR_DURATION_INVALID);
        }

        $this->duration = $duration;
    }

    /**
     * The moment the sending of the batch is to be over, read in the time zone of the message
     * the same way its start is.
     * @param DateTime $end
     * @return void
     */
    public function setEnd(DateTime $end): void
    {
        $end->setTimezone($this->getDateTimeZone());

        $this->end = $end;
    }

    /**
     * The message is sent over Viber only when the flags ask for it as well, chapter 14.1,
     * so this alone neither turns Viber on nor takes the SMS text away.
     * @param InstantMessage $instantMessage
     * @return void
     */
    public function setInstantMessage(InstantMessage $instantMessage): void
    {
        $this->instantMessage = $instantMessage;
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
     * The name the message goes out under, chapter 9.2.1: a text of at most eleven characters out
     * of the set the chapter allows, or a phone number in full international form. Nothing is
     * shortened — a name that is neither is refused here, where the caller still knows which
     * message it belongs to, rather than at the gateway. What the two shapes are and how they are
     * told apart is written out in SenderName, which the default sender of a whole transaction is
     * checked by as well.
     * @param string $senderName
     * @return void
     * @throws MessageException
     */
    public function setSenderName(string $senderName): void
    {
        $this->senderName = SenderName::validate($senderName);
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
     * Which alphabet the message goes out in, three answers and not two. Null, the one a message
     * starts with, leaves it to the text. True sends it with diacritics whatever the text is, and
     * pays the seventy character limit for it. False forces the text into GSM 03.38, and every
     * character that alphabet has no place for then reaches the recipient as a question mark,
     * chapter 14.1 — the message arrives mangled and is charged for all the same, so forcing it
     * is the caller's to answer for.
     * @param bool|null $unicode null to let the text decide, true or false to force it
     * @return void
     */
    public function setUnicode(?bool $unicode = null): void
    {
        $this->unicode = $unicode;
    }
}
