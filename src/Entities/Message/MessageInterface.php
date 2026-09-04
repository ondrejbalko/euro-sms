<?php

declare(strict_types=1);

namespace EuroSms\Entities\Message;

interface MessageInterface
{
    /**
     * Default length of senders name
     */
    const int MAX_SENDER_NAME_LENGTH = 11;

    const int ERROR_SENDER_NOT_DEFINED = 0;
    const int ERROR_SENDER_COLLECTION_IS_EMPTY = 1;
    const int ERROR_INSTANT_MESSAGE_TEXT_IS_EMPTY = 2;
    const int ERROR_INSTANT_MESSAGE_TEXT_TOO_LONG = 3;
    const int ERROR_INSTANT_MESSAGE_SENDER_IS_EMPTY = 4;
    const int ERROR_INSTANT_MESSAGE_TTL_OUT_OF_RANGE = 5;
    const int ERROR_DURATION_INVALID = 6;

    /**
     * The name the message was to go out under says nothing at all — it is the empty string, or
     * nothing but whitespace. A message that names no sender falls back to the one the account
     * is registered under, and the empty string is not a way of asking for that.
     * @var int
     */
    const int ERROR_SENDER_NAME_IS_EMPTY = 7;

    /**
     * The text sender is longer than the eleven characters chapter 9.2.1 allows it. It used to
     * be cut to eleven and sent as something the caller never wrote.
     * @var int
     */
    const int ERROR_SENDER_NAME_TOO_LONG = 8;

    /**
     * The text sender carries a character chapter 9.2.1 does not list. The chapter allows
     * letters, digits, a hyphen, a space and a dot, and warns that anything else may take the
     * delivery of the whole message down with it.
     * @var int
     */
    const int ERROR_SENDER_NAME_INVALID = 9;

    /**
     * The sender is written entirely in digits and so is read as the phone number of chapter
     * 9.2.1 — but it is not one in full international form.
     * @var int
     */
    const int ERROR_SENDER_NAME_NOT_A_NUMBER = 10;

    /**
     * The message was given no text, or one made of nothing but whitespace. The gateway answers
     * such a send with EMPTY_MESSAGE, by which time nothing says which message it was.
     * @var int
     */
    const int ERROR_CONTENT_IS_EMPTY = 11;

    /**
     * A group transaction was asked for with no message to carry. It used to be built, posted
     * and answered with a tally of nothing.
     * @var int
     */
    const int ERROR_MESSAGE_COLLECTION_IS_EMPTY = 12;
}
