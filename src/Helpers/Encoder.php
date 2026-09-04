<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

/**
 * The alphabet a plain SMS is written in, chapters 12.1 and 12.2 of SMS API v3.1.15 and the table
 * of appendix B. Handed a character GSM 03.38 knows no place for, the gateway replaces it with a
 * question mark, chapter 14.1 — so whether a text can be written in that alphabet at all is what
 * decides between the hundred and sixty characters a plain message holds and the seventy a message
 * with diacritics is left with.
 *
 * Nothing here counts bytes. A character of the alphabet is one septet on the wire however many
 * bytes it takes in UTF-8, and the ten characters of the extension table are two septets each
 * however few.
 */
final class Encoder
{
    /**
     * The encoding the texts handed to this library are written in.
     */
    private const string CHARSET = 'utf-8';

    /**
     * The basic set of chapter 12.1, in the order appendix B lists it — one septet each. The
     * escape at 0x1B is left out on purpose: it announces a character of the extension table and
     * is never a character a text is written with.
     */
    private const string BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * The extension table of chapter 12.2. Every one of these travels as the escape followed by
     * the character itself, so it takes two septets where every other character takes one.
     */
    private const string EXTENDED = "\f^{}\[~]|€";

    /**
     * Whether the whole text can be written in GSM 03.38. One character the alphabet does not
     * carry is enough for the answer to be no: the gateway would replace that one character, and
     * a message that arrives mangled is worth no less than one sent with diacritics.
     * @param string $text
     * @return bool
     */
    #[\NoDiscard('the verdict is the only thing this call produces')]
    public static function isGsm(string $text): bool
    {
        foreach (mb_str_split($text, 1, self::CHARSET) as $character) {
            if (!str_contains(self::BASIC, $character) && !str_contains(self::EXTENDED, $character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many septets the text takes once it is written in GSM 03.38, which is what the length
     * limits of chapter 12.1 are counted in. A character of the extension table counts twice, the
     * escape in front of it being a position of the message like any other.
     *
     * The answer only means anything for a text isGsm() answers true for. A text that has to go
     * out with diacritics is not written in this alphabet at all and is counted by its characters.
     * @param string $text
     * @return int
     */
    #[\NoDiscard('the length is the only thing this call produces')]
    public static function length(string $text): int
    {
        $length = 0;

        foreach (mb_str_split($text, 1, self::CHARSET) as $character) {
            $length += str_contains(self::EXTENDED, $character) ? 2 : 1;
        }

        return $length;
    }

    /**
     * How many positions the text takes once it goes out with diacritics, which is what the limits
     * of chapter 12.1 are counted in there. Those positions are sixteen bits wide, so a character
     * the basic plane has no room for — an emoji, and everything else above U+FFFF — takes two of
     * them and not one. Counting the characters instead let a text of forty emoji pass for a
     * single message while the gateway split it in two and charged for both.
     * @param string $text
     * @return int
     */
    #[\NoDiscard('the length is the only thing this call produces')]
    public static function unicodeLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', self::CHARSET)), 2);
    }
}
