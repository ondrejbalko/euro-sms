<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The verdicts a send request is answered with: chapter 13.1 of SMS API v3.1.15 for the codes
 * every message shares, chapter 10.6 for the ones an instant message adds on top of them.
 *
 * The gateway states one of them in "err_code" for the whole request and, on a group
 * transaction, once more in "e" for every single number, chapter 9.3.11. ENQUEUED is the only
 * one that means the message went out; every other names the reason it did not.
 */
enum SendStatusEnum: string
{
    /**
     * Accepted and enqueued to send, the one verdict that is not a refusal
     */
    case ENQUEUED = 'ENQUEUED';

    /**
     * The text of the message was there but empty
     */
    case EMPTY_MESSAGE = 'EMPTY_MESSAGE';

    /**
     * A refusal the gateway did not name, and the case an unlisted code is read as
     */
    case ERR_OTHER = 'ERR_OTHER';

    /**
     * The Viber message carried no text, chapter 10.6
     */
    case IM_MSG_EMPTY = 'IM_MSG_EMPTY';

    /**
     * The Viber message was longer than chapter 10.4 allows, chapter 10.6
     */
    case IM_MSG_TOO_LONG = 'IM_MSG_TOO_LONG';

    /**
     * The account may not send over Viber at all, chapter 10.6
     */
    case IM_NOT_ALLOWED = 'IM_NOT_ALLOWED';

    /**
     * The time to live asked for lies outside the range of chapter 10.3, chapter 10.6
     */
    case IM_TTL_OUT_OF_RANGE = 'IM_TTL_OUT_OF_RANGE';

    /**
     * The sender name of the Viber message is not registered with the gateway, chapter 10.6
     */
    case IM_UNREGISTERED_SENDER = 'IM_UNREGISTERED_SENDER';

    /**
     * The message is longer than the gateway takes
     */
    case MSG_TOO_LONG = 'MSG_TOO_LONG';

    /**
     * The account has no credit left to pay the message with
     */
    case NO_BALANCE = 'NO_BALANCE';

    /**
     * The client identifier was not stated
     */
    case NO_IID = 'NO_IID';

    /**
     * The list of messages was not stated
     */
    case NO_MSG = 'NO_MSG';

    /**
     * The recipient was not stated
     */
    case NO_RCPT = 'NO_RCPT';

    /**
     * The signature of the request was not stated
     */
    case NO_SGN = 'NO_SGN';

    /**
     * The sender name was not stated
     */
    case NO_SNDR = 'NO_SNDR';

    /**
     * The text of the message was not stated
     */
    case NO_TXT = 'NO_TXT';

    /**
     * The request carried more messages than the gateway takes at once
     */
    case TOO_MANY_MESSAGES = 'TOO_MANY_MESSAGES';

    /**
     * The client identifier is not one the gateway knows
     */
    case WRONG_IID = 'WRONG_IID';

    /**
     * The recipient is no number the gateway can send to
     */
    case WRONG_NUMBER = 'WRONG_NUMBER';

    /**
     * The sender name is not one the account may send under
     */
    case WRONG_SENDER = 'WRONG_SENDER';

    /**
     * The signature does not match the request it was computed over
     */
    case WRONG_SIGNATURE = 'WRONG_SIGNATURE';

    /**
     * The verdict a code stands for, whether or not the answer described it. A code neither
     * chapter lists is the refusal the gateway did not name, which is what ERR_OTHER is for;
     * the code itself stays where it came from, in the body of the answer, so the original
     * wording is never lost by reading it.
     * @param string|null $code
     * @return self
     */
    public static function fromCode(?string $code): self
    {
        return null === $code ? self::ERR_OTHER : self::tryFrom($code) ?? self::ERR_OTHER;
    }

    /**
     * Whether the code is one the two chapters name. Anything else is read as ERR_OTHER, and
     * this is what tells such a code apart from an ERR_OTHER the gateway itself answered with.
     * @param string|null $code
     * @return bool
     */
    public static function isKnownCode(?string $code): bool
    {
        return null !== $code && null !== self::tryFrom($code);
    }

    /**
     * The verdict written out, so that what is shown does not depend on the err_desc the gateway
     * happened to send along, which is not stated for every code and not worded the same twice.
     * @return string
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::ENQUEUED => 'Accepted and enqueued to send',
            self::EMPTY_MESSAGE => 'The message carries no text',
            self::ERR_OTHER => 'A refusal the gateway did not name',
            self::IM_MSG_EMPTY => 'The Viber message carries no text',
            self::IM_MSG_TOO_LONG => 'The Viber message is too long',
            self::IM_NOT_ALLOWED => 'The account may not send over Viber',
            self::IM_TTL_OUT_OF_RANGE => 'The time to live of the Viber message is out of range',
            self::IM_UNREGISTERED_SENDER => 'The sender of the Viber message is not registered',
            self::MSG_TOO_LONG => 'The message is too long',
            self::NO_BALANCE => 'The account has no credit left',
            self::NO_IID => 'The client identifier is missing',
            self::NO_MSG => 'The list of messages is missing',
            self::NO_RCPT => 'The recipient is missing',
            self::NO_SGN => 'The signature of the request is missing',
            self::NO_SNDR => 'The sender name is missing',
            self::NO_TXT => 'The text of the message is missing',
            self::TOO_MANY_MESSAGES => 'The request carries more messages than the gateway takes at once',
            self::WRONG_IID => 'The client identifier is not one the gateway knows',
            self::WRONG_NUMBER => 'The recipient is not a number the gateway can send to',
            self::WRONG_SENDER => 'The sender name is not one the account may send under',
            self::WRONG_SIGNATURE => 'The signature does not match the request'
        };
    }

    /**
     * Whether the verdict means the message was taken over. ENQUEUED is the only one that does,
     * chapter 13.1, so every other is a reason it was refused.
     * @return bool
     */
    public function isEnqueued(): bool
    {
        return self::ENQUEUED === $this;
    }
}
