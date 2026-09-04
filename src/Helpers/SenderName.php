<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Gateway\GatewayInterface;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * The name a message goes out under, chapter 9.2.1 of SMS API v3.1.15. It is one of two things and
 * never anything in between: a text of at most eleven characters written out of the set the chapter
 * allows, or a phone number in full international form written without the plus. Both are answered
 * for here rather than at the gateway, which replies to a name it cannot write with WRONG_SENDER —
 * in English, after the send, and with nothing in the answer saying which of the inputs was at
 * fault.
 *
 * Nothing is shortened. A name over the eleven characters used to be cut and the message went out
 * under something the caller never wrote; cut by bytes rather than characters it ended in the
 * middle of a UTF-8 sequence and the body of the request was then no longer encodable at all.
 *
 * One message and a whole transaction name their sender in the same field under two names, "sndr"
 * and "dsndr" of chapters 9.3.1 and 9.3.8, and the chapter gives them one set of rules. They are
 * checked by one piece of code for that reason: a transaction default that was cut while the
 * message that falls back to it was refused would be the same mistake wearing the other name.
 */
final class SenderName
{
    /**
     * The sender as it goes on the wire, or a MessageException naming what is wrong with it.
     *
     * Which of the two kinds a name is, is read off the name itself. Written with anything but
     * digits, a space included, it is a text; a number typed with the spaces it is printed with is
     * therefore a text, and is answered for as one. Written entirely in digits it is a number when
     * it really is one some country hands out, and the eleven characters are not its limit there —
     * a number is as long as the country makes it. Digits that are no such number fall back to the
     * text rules, because chapter 9.2.1 lists digits among the characters a text may be written
     * out of: a short code such as "8877" is a text sender and goes out as one.
     * @param string $senderName
     * @return string
     * @throws MessageException
     */
    #[\NoDiscard('the checked sender name is the only thing this call produces')]
    public static function validate(string $senderName): string
    {
        if ('' === trim($senderName)) {
            throw new MessageException('Sender name is empty.', MessageInterface::ERROR_SENDER_NAME_IS_EMPTY);
        }

        $senderNumber = self::readNumber($senderName);

        if (null !== $senderNumber) {
            return $senderNumber;
        }

        $length = mb_strlen($senderName, 'utf-8');

        if ($length > MessageInterface::MAX_SENDER_NAME_LENGTH) {
            throw new MessageException(
                sprintf(
                    'Sender name is %d characters long, %d at most are allowed.',
                    $length,
                    MessageInterface::MAX_SENDER_NAME_LENGTH
                ),
                MessageInterface::ERROR_SENDER_NAME_TOO_LONG
            );
        }

        if (1 !== preg_match(GatewayInterface::SENDER_NAME_PATTERN, $senderName)) {
            throw new MessageException(
                'Sender name may carry letters, digits, a hyphen, a space and a dot only.',
                MessageInterface::ERROR_SENDER_NAME_INVALID
            );
        }

        return $senderName;
    }

    /**
     * The sender read as the phone number chapter 9.2.1 allows in the place of a text, none at all
     * when it is not written in digits and so is a text. The plus of E.164 and the double zero
     * dialled in front of it say the same thing as one another and nothing the gateway needs, so
     * they are taken off rather than refused. What is left has to be a number some country really
     * hands out: a sender carries no country of its own the way a recipient does, so a number in
     * local form is never completed here and never guessed at.
     *
     * Being written in digits is not by itself enough to make a sender a number. The eleven
     * characters of chapter 9.2.1 are letters, digits, a hyphen, a space and a dot, so a short
     * code such as "8877" is a text the chapter allows as much as "My-Shop" is — and reading every
     * all-digit sender as a number refused it outright, because no country hands out a number that
     * short. What no country hands out is therefore handed back to the text rules rather than
     * refused, and only a sender the text rules cannot take either is answered for as a number
     * that is none: one over the eleven characters, or one carrying the plus or the double zero
     * that leaves no doubt a number is what was meant.
     * @param string $senderName
     * @return string|null
     * @throws MessageException
     */
    private static function readNumber(string $senderName): ?string
    {
        if (1 !== preg_match(GatewayInterface::SENDER_NUMBER_PATTERN, $senderName, $matches)) {
            return null;
        }

        $phoneNumberUtil = PhoneNumberUtil::getInstance();
        $cause = null;

        try {
            $parsed = $phoneNumberUtil->parse('+' . $matches['digits']);

            if ($phoneNumberUtil->isValidNumber($parsed)) {
                return ltrim($phoneNumberUtil->format($parsed, PhoneNumberFormat::E164), '+');
            }
        } catch (NumberParseException $e) {
            $cause = $e;
        }

        /**
         * The prefix is what settles it. A sender written with the plus or the double zero in
         * front of it was meant as a number and as nothing else — the two say the same thing as
         * one another, so they are answered for the same way — and there is no text sender left
         * to fall back on. Bare digits were never that explicit, and those are the ones handed
         * back to the text rules.
         */
        if ($senderName === $matches['digits'] && self::isTextSender($senderName)) {
            return null;
        }

        throw new MessageException(
            sprintf('Sender number "%s" is not a number in full international form.', $senderName),
            MessageInterface::ERROR_SENDER_NAME_NOT_A_NUMBER,
            $cause
        );
    }

    /**
     * Whether the sender is one the text rules of chapter 9.2.1 can take: within the eleven
     * characters and written out of the set the chapter lists. It answers the question the two
     * checks in validate() ask, without throwing at what it turns down — here what it turns down
     * is not a mistake but the answer that this sender is no text and has to be a number.
     * @param string $senderName
     * @return bool
     */
    private static function isTextSender(string $senderName): bool
    {
        return mb_strlen($senderName, 'utf-8') <= MessageInterface::MAX_SENDER_NAME_LENGTH
            && 1 === preg_match(GatewayInterface::SENDER_NAME_PATTERN, $senderName);
    }
}
