<?php

declare(strict_types=1);

namespace EuroSms\Gateway;

use EuroSms\Enums\SendStatusEnum;

interface GatewayInterface
{
    /**
     * How a moment in time is written into a request, chapters 9.2.1 and 9.3.1: "start", "end"
     * and "sch" all carry the same shape.
     */
    const string DATE_TIME_FORMAT = 'Y-m-d H:i';

    /**
     * How long the sending of a whole batch may be spread over, chapter 9.2.1: hours and minutes.
     * The example of chapter 10.2.1 spreads eleven messages over one hour with "01:00".
     *
     * The D modifier is what makes the end of the pattern the end of the value. Without it "$"
     * also matches in front of a closing newline, so a length carrying one would pass the check
     * and travel into "dur" with the newline still on it.
     */
    const string DURATION_PATTERN = '/^\d{2}:[0-5]\d$/D';

    /**
     * Default length of one Viber message (with Unicode characters), chapter 10.4
     */
    const int IM_MAX_LENGTH = 1000;

    /**
     * Longest time to live a Viber message may ask for, in seconds
     */
    const int IM_TTL_MAX = 86400;

    /**
     * Shortest time to live a Viber message may ask for, in seconds
     */
    const int IM_TTL_MIN = 15;

    /**
     * How much messages can be sent per one request
     */
    const int MAX_ITEMS_PER_REQUEST = 1000;

    /**
     * Default length of one SMS message
     */
    const int MAX_MESSAGE_LENGTH = 160;

    /**
     * How many segments one message is recommended to stay within, chapter 9.2.2. Nothing here
     * refuses a longer one — the gateway takes it and charges every segment of it — but a text
     * that falls into more parts than this is worth thinking about twice before it is sent.
     */
    const int MAX_MESSAGE_SEGMENTS = 4;

    /**
     * Default length of next SMS message
     */
    const int MAX_NEXT_MESSAGE_LENGTH = 153;

    /**
     * Default length of one SMS message (with Unicode characters)
     */
    const int MAX_UNICODE_MESSAGE_LENGTH = 70;

    /**
     * Default length of next SMS message (with Unicode characters)
     */
    const int MAX_UNICODE_NEXT_MESSAGE_LENGTH = 67;

    /**
     * Message accepted and enqueued to send.
     * @deprecated Use SendStatusEnum::ENQUEUED, which carries the rest of chapter 13.1 with it.
     */
    const string RESPONSE_ENQUEUED = SendStatusEnum::ENQUEUED->value;

    /**
     * What a text sender may be written out of, chapter 9.2.1: letters, digits, a hyphen, a
     * space and a dot, and nothing else. The chapter is explicit that a character outside this
     * set may take the delivery of the whole message down with it, so a name carrying one is
     * refused here rather than sent and lost. Diacritics are outside it too, whatever they cost
     * in bytes — the set is the one the gateway writes, not the one UTF-8 can encode.
     *
     * The D modifier is what makes the end of the pattern the end of the name. Without it "$"
     * also matches in front of a closing newline, so "Shop\n" passed the set and the newline
     * travelled into "sndr" and into the signature of chapter 9.2.2 composed over it.
     */
    const string SENDER_NAME_PATTERN = '/^[A-Za-z0-9 .-]+$/D';

    /**
     * What is read as the other kind of sender chapter 9.2.1 allows, a phone number rather than
     * a text. Anything written entirely in digits is one, and the two prefixes a caller usually
     * has the number lying around with — the plus of E.164 and the double zero dialled in front
     * of it — are matched so they can be taken off. What is left has to be a number in full
     * international form; a local one is not, and there is nothing on a sender that could say
     * which country it belongs to.
     *
     * The D modifier is what makes the end of the pattern the end of the sender, the same way it
     * does for the text above: without it a number carrying a closing newline was read as a
     * number and the newline was quietly dropped by the normalisation rather than answered for.
     */
    const string SENDER_NUMBER_PATTERN = '/^(?:\+|00)?(?<digits>\d+)$/D';

    /**
     * What the gateway writes in front of the identifier of a Viber message, chapter 9.3.11.
     * An SMS identifier carries no prefix at all.
     */
    const string VIBER_IDENTIFIER_PREFIX = 'v:';
}
