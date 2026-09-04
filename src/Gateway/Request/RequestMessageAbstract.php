<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use DateTime;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Helpers\Encoder;

/**
 * What a request carrying one message has whoever it is addressed to: the text, how many messages
 * that text falls into, the flags it goes out under, the Viber variant, how long it may wait for
 * its recipient and the signature the gateway checks it by.
 *
 * How the message names who it is addressed to is not decided here. Chapter 9.3.1 of SMS API
 * v3.1.15 writes a single number under "rcpt" and chapter 9.3.3 a whole list under "rcpts", so
 * both the recipients themselves and the body they end up in are left to the classes below.
 */
abstract class RequestMessageAbstract extends RequestAbstract
{
    /** @var int $amount how many messages the text falls into */
    protected int $amount = 0;

    /** @var string $content */
    protected string $content;

    /** @var Flag $flag */
    protected Flag $flag;

    /** @var InstantMessage $instantMessage */
    protected InstantMessage $instantMessage;

    /** @var bool $isUnicode */
    protected bool $isUnicode = false;

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
        if (!isset($this->content)) {
            throw new RequestException('Message not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        return $this->amount;
    }

    /**
     * The text of the message. A request that was never given one says so the way every other
     * path of it does, rather than letting the uninitialized property blow up with an Error that
     * no catch of this library reaches.
     * @return string
     * @throws RequestException
     */
    public function getContent(): string
    {
        if (!isset($this->content)) {
            throw new RequestException('Message text not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        return $this->content;
    }

    /**
     * @return Flag
     */
    public function getFlag(): Flag
    {
        return $this->flag;
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
     * The "im" object as it goes into the request, none at all when there is nothing to write
     * out: chapter 10.2.1 lets the object be left out but never sent empty. A message of a
     * group transaction is written out of this rather than read back out of the body of a
     * request that is not its own.
     * @return array{sndr?: string, ttl?: int, msg?: string}|null
     */
    public function getInstantMessageData(): ?array
    {
        if (!isset($this->instantMessage) || $this->instantMessage->isEmpty()) {
            return null;
        }

        return $this->instantMessage->getData();
    }

    /**
     * How the message names who it is addressed to, chapters 9.3.1 and 9.3.3: one number under
     * "rcpt", a whole list of them under "rcpts". A message of a group transaction is written out
     * of this rather than read back out of the body of a request that is not its own.
     * @return array{rcpt: int|array{r: int, f: string}}|array{rcpts: list<int|array{r: int, f: string}>}
     */
    abstract public function getRecipientData(): array;

    /**
     * How one recipient is written into a request. A recipient that carries no key of its own is
     * the bare number it always was; one that does becomes the object of chapter 9.2, pairing the
     * number under "r" with the key under "f". The gateway hands that key back on the delivery
     * report, chapter 9.4.2, which is the only way a receipt can be told from another one sent to
     * the same number. Both shapes may stand side by side in one list of recipients.
     * @param Recipient $recipient
     * @return int|array{r: int, f: string}
     */
    protected static function getRecipientValue(Recipient $recipient): int|array
    {
        $identifier = $recipient->getIdentifier();

        if (null === $identifier) {
            return $recipient->getNumberClean();
        }

        return [
            FieldEnum::RECIPIENT_NUMBER->value => $recipient->getNumberClean(),
            FieldEnum::RECIPIENT_IDENTIFIER->value => $identifier
        ];
    }

    /**
     * The numbers the message is addressed to, reduced to their digits however many of them there
     * are: the signature of chapter 9.2.2 is composed of numbers and so are the verdicts the
     * answer is read into. A key a recipient carries is left out of this on purpose — whatever
     * needs it asks for the recipients themselves.
     * @return list<int>
     */
    abstract public function getRecipients(): array;

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
     * Which alphabet the text goes out in is worked out from the text itself unless the caller
     * says otherwise: one that can be written in GSM 03.38 is, and one that cannot goes out with
     * diacritics. Handing over true or false decides it instead — false on a text the alphabet
     * cannot carry means every such character reaches the recipient as a question mark, chapter
     * 14.1, which is the caller's to answer for.
     * @param string|null $content
     * @param Flag $flag
     * @param bool|null $isUnicode null to let the text decide, true or false to force it
     * @return void
     * @throws RequestException
     */
    public function setContent(?string $content, Flag $flag, ?bool $isUnicode = null): void
    {
        if (null === $content || '' === $content) {
            throw new RequestException('Message text not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        $this->content = $content;
        $this->flag = clone $flag;
        $this->isUnicode = $isUnicode ?? !Encoder::isGsm($content);

        $this->setContentAmount();
    }

    /**
     * How many messages the text falls into, and so what it will cost. A text that fits into one
     * message is one message; anything longer is sent as a concatenated one, and there every part
     * is shorter, the rest of it going to the header that says which part of which message it is —
     * the first part included. Counting the first part as a full one and only the rest as short
     * ones undercounts a text that spills just past the last part: 307 plain characters are three
     * messages of 153, not two.
     *
     * What the limits are counted in is neither bytes nor characters. A plain message is written
     * in GSM 03.38, chapters 12.1 and 12.2, where a character is one septet however many bytes it
     * takes in UTF-8 and one of the ten extended characters is two; a message with diacritics is
     * written in UCS-2, where a position is sixteen bits and a character above U+FFFF takes two of
     * them. Counting the bytes of a plain text made a message of every accented character it
     * carried and charged a euro sign three times over; counting the characters of a text with
     * diacritics let forty emoji pass for one message the gateway would have split in two.
     *
     * A text handed over more than once is one text, so whatever was said about the previous
     * one is dropped rather than added to.
     * @return void
     */
    protected function setContentAmount(): void
    {
        $this->warnings = [];

        if ($this->isUnicode()) {
            $messageLength = GatewayInterface::MAX_UNICODE_MESSAGE_LENGTH;
            $nextMessageLength = GatewayInterface::MAX_UNICODE_NEXT_MESSAGE_LENGTH;
            $textLength = Encoder::unicodeLength($this->content);
        } else {
            $messageLength = GatewayInterface::MAX_MESSAGE_LENGTH;
            $nextMessageLength = GatewayInterface::MAX_NEXT_MESSAGE_LENGTH;
            $textLength = Encoder::length($this->content);
        }

        $this->amount = $textLength <= $messageLength ? 1 : (int)ceil($textLength / $nextMessageLength);

        /**
         * Chapter 9.2.2 recommends staying within four segments and chapter 14.1 is explicit
         * that the gateway itself puts no limit on them — it takes a longer message and charges
         * every part of it. Refusing the send here would refuse something that is allowed, so
         * the caller is told and the message goes out. Read back through getWarnings(), on the
         * request or on the whole result.
         */
        if ($this->amount > GatewayInterface::MAX_MESSAGE_SEGMENTS) {
            $this->warnings[] = sprintf(
                'The text falls into %d segments, chapter 9.2.2 recommends staying within %d.',
                $this->amount,
                GatewayInterface::MAX_MESSAGE_SEGMENTS
            );
        }

        if ($this->amount > 1) {
            $this->getFlag()->addLong();
        }

        if ($this->isUnicode()) {
            $this->getFlag()->addUnicodeShort();
        }
    }

    /**
     * The variant handed over by the caller is cloned, so building the request leaves it alone.
     * @param InstantMessage $instantMessage
     * @return void
     */
    public function setInstantMessage(InstantMessage $instantMessage): void
    {
        $this->instantMessage = clone $instantMessage;
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
     * The signature of chapter 9.2.2: the sender name, every number the message is addressed to
     * written one after another, and the text, hashed with the integration key. A message
     * addressed to one number and one addressed to a list sign the same string — the list of one
     * is that one number — so both are signed here.
     *
     * It is worked out on its own rather than as a by-product of building the body. A transaction
     * needs nothing of its messages but their signatures, chapter 9.3.8, and it used to get them
     * by building each message's whole request and throwing it away: for a transaction of a
     * thousand entries, a thousand bodies composed, filtered and dropped, with the recipients then
     * written out a second time for the entry that is kept.
     * @return string
     * @throws MessageException when the message was never given a sender to go out under
     * @throws RequestException when the message was never given a text
     */
    public function sign(): string
    {
        if (!isset($this->senderName)) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        $this->sign = $this->calcSignature(
            $this->senderName,
            implode('', $this->getRecipients()),
            $this->getContent()
        );

        return $this->sign;
    }

    /**
     * @param int $ttl
     * @return void
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }
}
