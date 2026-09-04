<?php

declare(strict_types=1);

namespace EuroSms\Entities\Message;

use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\InstantMessageException;
use EuroSms\Gateway\GatewayInterface;

/**
 * The Viber variant of a message, the "im" object of chapter 10 of SMS API v3.1.15. It carries a
 * sender registered with Viber, a text of its own and a time to live of its own. What it does not
 * carry falls back to the "dimsndr" and "dimttl" defaults of the whole transaction, so a message
 * that only differs in its text needs nothing else.
 *
 * Chapter 10.2.1 lets the object be left out altogether but never sent empty, which is what
 * isEmpty() is asked before it is written into a request.
 *
 * Carrying this object is not by itself what sends the message over Viber: that is what the Viber
 * flags of chapter 14.1 are for, so the message has to ask for them as well.
 */
class InstantMessage
{
    /** @var string $content */
    private string $content;

    /** @var string $senderName */
    private string $senderName;

    /**
     * time to live, in seconds; how long the gateway keeps trying to deliver over Viber before
     * it falls back to SMS or throws the message away
     * @var int $ttl
     */
    private int $ttl;

    /**
     * The object as the gateway names its fields, without the ones that were never set,
     * chapter 10.2.1 of SMS API v3.1.15:
     *
     * - sndr: the sender name the Viber message goes out under
     * - ttl: how long the gateway keeps trying Viber before it gives up, in seconds
     * - msg: the text of the Viber message
     *
     * @return array{sndr?: string, ttl?: int, msg?: string}
     */
    #[\NoDiscard('the composed "im" object is all this call produces')]
    public function getData(): array
    {
        $data = [];

        if (isset($this->senderName)) {
            $data[FieldEnum::SENDER_NAME->value] = $this->senderName;
        }

        if (isset($this->ttl)) {
            $data[FieldEnum::TTL->value] = $this->ttl;
        }

        if (isset($this->content)) {
            $data[FieldEnum::INSTANT_MESSAGE_TEXT->value] = $this->content;
        }

        return $data;
    }

    /**
     * @return string|null
     */
    public function getContent(): ?string
    {
        return $this->content ?? null;
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
     * Nothing was set, so the request has nothing to write out.
     * @return bool
     */
    public function isEmpty(): bool
    {
        return [] === $this->getData();
    }

    /**
     * A Viber message holds a thousand characters, diacritics and all, chapter 14.1. A longer
     * text is refused here rather than truncated by the gateway once it has been paid for.
     * @param string $content
     * @return void
     * @throws InstantMessageException
     */
    public function setContent(string $content): void
    {
        if ('' === $content) {
            throw new InstantMessageException(
                'Instant message text not defined.',
                MessageInterface::ERROR_INSTANT_MESSAGE_TEXT_IS_EMPTY
            );
        }

        $length = mb_strlen($content, 'utf-8');

        if ($length > GatewayInterface::IM_MAX_LENGTH) {
            throw new InstantMessageException(
                sprintf(
                    'Instant message text is %d characters long, %d at most are allowed.',
                    $length,
                    GatewayInterface::IM_MAX_LENGTH
                ),
                MessageInterface::ERROR_INSTANT_MESSAGE_TEXT_TOO_LONG
            );
        }

        $this->content = $content;
    }

    /**
     * The Viber sender is registered with the operator and is not the SMS sender, so it is
     * neither shortened to the eleven characters of chapter 9.3.1 nor derived from it.
     * @param string $senderName
     * @return void
     * @throws InstantMessageException
     */
    public function setSenderName(string $senderName): void
    {
        if ('' === $senderName) {
            throw new InstantMessageException(
                'Instant message sender name not defined.',
                MessageInterface::ERROR_INSTANT_MESSAGE_SENDER_IS_EMPTY
            );
        }

        $this->senderName = $senderName;
    }

    /**
     * in seconds, from fifteen seconds to a whole day
     * @param int $ttl
     * @return void
     * @throws InstantMessageException
     */
    public function setTtl(int $ttl): void
    {
        if ($ttl < GatewayInterface::IM_TTL_MIN || $ttl > GatewayInterface::IM_TTL_MAX) {
            throw new InstantMessageException(
                sprintf(
                    'Instant message time to live of %d seconds is outside the allowed range of %d to %d.',
                    $ttl,
                    GatewayInterface::IM_TTL_MIN,
                    GatewayInterface::IM_TTL_MAX
                ),
                MessageInterface::ERROR_INSTANT_MESSAGE_TTL_OUT_OF_RANGE
            );
        }

        $this->ttl = $ttl;
    }
}
