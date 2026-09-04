<?php

declare(strict_types=1);

namespace EuroSms\Tests\Helpers;

use EuroSms\Helpers\Encoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The alphabet comes from chapters 12.1 and 12.2 of SMS API v3.1.15 and from the table of
 * appendix B. Both sets are written out here rather than read off the class under test, so that
 * a character quietly falling out of the alphabet is a failing test and not a matching mistake.
 */
#[CoversClass(Encoder::class)]
final class EncoderTest extends TestCase
{
    /**
     * The basic set of chapter 12.1, in the order appendix B lists it. The escape at 0x1B is the
     * one position of the table that is left out: it announces a character of the extension table
     * and is never a character a text is written with.
     */
    private const string BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * The extension table of chapter 12.2, escape plus character, two septets each.
     */
    private const string EXTENDED = "\f^{}\[~]|€";

    /**
     * A hundred and twenty-eight positions less the escape.
     */
    public function testTheBasicSetIsAsLongAsAppendixBSaysItIs(): void
    {
        self::assertSame(127, mb_strlen(self::BASIC, 'utf-8'));
    }

    public function testTheExtensionTableIsAsLongAsAppendixBSaysItIs(): void
    {
        self::assertSame(10, mb_strlen(self::EXTENDED, 'utf-8'));
    }

    /**
     * Every character of the basic set, one after the other: the alphabet carries it and it takes
     * one septet, however many bytes it costs in UTF-8.
     */
    public function testEveryCharacterOfTheBasicSetIsOneSeptet(): void
    {
        foreach (mb_str_split(self::BASIC, 1, 'utf-8') as $character) {
            self::assertTrue(Encoder::isGsm($character), sprintf('%s is a character of chapter 12.1', $character));
            self::assertSame(1, Encoder::length($character), sprintf('%s is one septet', $character));
        }
    }

    /**
     * Every character of the extension table: the alphabet carries it as well, and it takes two
     * septets, the escape in front of it being a position of the message like any other.
     */
    public function testEveryCharacterOfTheExtensionTableIsTwoSeptets(): void
    {
        foreach (mb_str_split(self::EXTENDED, 1, 'utf-8') as $character) {
            self::assertTrue(Encoder::isGsm($character), sprintf('%s is a character of chapter 12.2', $character));
            self::assertSame(2, Encoder::length($character), sprintf('%s is two septets', $character));
        }
    }

    /**
     * The whole basic set at once is a hundred and twenty-seven septets, so no character of it is
     * counted twice by being read as an extended one.
     */
    public function testTheWholeBasicSetCountsAsOneSeptetPerCharacter(): void
    {
        self::assertSame(127, Encoder::length(self::BASIC));
    }

    /**
     * The whole extension table at once is twenty septets, so no character of it is counted once
     * by being read as a basic one.
     */
    public function testTheWholeExtensionTableCountsAsTwoSeptetsPerCharacter(): void
    {
        self::assertSame(20, Encoder::length(self::EXTENDED));
    }

    /**
     * Most of what Slovak is written with the alphabet has no place for, which is the whole reason
     * a message goes out with diacritics at all.
     */
    public function testTheCharactersSlovakIsWrittenWithAreMostlyNotInTheAlphabet(): void
    {
        foreach (mb_str_split('áčďíĺľňóôŕšťúýžÁČĎÍĽŇÓŠŤÚÝŽ', 1, 'utf-8') as $character) {
            self::assertFalse(Encoder::isGsm($character), sprintf('%s is no character of appendix B', $character));
        }
    }

    /**
     * Three of them the alphabet does carry, appendix B listing them at 0x05, 0x1F and 0x7B — so
     * a text is never taken for a unicode one merely because it is Slovak.
     */
    public function testTheThreeSlovakCharactersTheAlphabetDoesCarryAreGsm(): void
    {
        self::assertTrue(Encoder::isGsm('é'));
        self::assertTrue(Encoder::isGsm('É'));
        self::assertTrue(Encoder::isGsm('ä'));
        self::assertSame(3, Encoder::length('éÉä'));
    }

    /**
     * A character that looks close enough to one of the alphabet is still not one of them: the
     * gateway would replace it with a question mark, chapter 14.1.
     */
    public function testACharacterOutsideTheAlphabetIsRefused(): void
    {
        self::assertFalse(Encoder::isGsm('ł'));
        self::assertFalse(Encoder::isGsm('ě'));
        self::assertFalse(Encoder::isGsm('«'));
        self::assertFalse(Encoder::isGsm('`'));
        self::assertFalse(Encoder::isGsm('😀'));
    }

    /**
     * One strange character is enough for the whole text to be no GSM text, wherever it stands.
     */
    public function testOneCharacterOutsideTheAlphabetSettlesTheWholeText(): void
    {
        self::assertFalse(Encoder::isGsm('Ďakujeme za objednavku'));
        self::assertFalse(Encoder::isGsm('Dakujeme za objednávku'));
        self::assertFalse(Encoder::isGsm('Dakujeme za objednavku!'.'😀'));
    }

    public function testAPlainTextIsAGsmTextOfItsOwnLength(): void
    {
        self::assertTrue(Encoder::isGsm('Testovacia sprava'));
        self::assertSame(17, Encoder::length('Testovacia sprava'));
    }

    /**
     * The line break of the basic set is one septet, the form feed of the extension table is two.
     */
    public function testTheWhitespaceOfBothTablesIsCountedWhereItBelongs(): void
    {
        self::assertSame(2, Encoder::length("\r\n"));
        self::assertSame(2, Encoder::length("\f"));
    }

    /**
     * A text of both tables at once: every extended character costs its escape on top of itself.
     */
    public function testAMixedTextCountsEveryExtendedCharacterTwice(): void
    {
        self::assertTrue(Encoder::isGsm('Cena je 10 € [zlava 5 %]'));
        self::assertSame(27, Encoder::length('Cena je 10 € [zlava 5 %]'));
    }

    public function testATextOfNothingIsAGsmTextOfNoLength(): void
    {
        self::assertTrue(Encoder::isGsm(''));
        self::assertSame(0, Encoder::length(''));
        self::assertSame(0, Encoder::unicodeLength(''));
    }

    /**
     * A message with diacritics is counted in positions of sixteen bits, so everything the basic
     * plane holds is one of them however many bytes it takes in UTF-8.
     */
    public function testACharacterOfTheBasicPlaneIsOneUnicodePosition(): void
    {
        self::assertSame(1, Encoder::unicodeLength('a'));
        self::assertSame(1, Encoder::unicodeLength('á'));
        self::assertSame(1, Encoder::unicodeLength('€'));
        self::assertSame(23, Encoder::unicodeLength('Ďakujeme za objednávku.'));
    }

    /**
     * Everything above U+FFFF is written as two of those positions and charged as two.
     */
    public function testACharacterAboveTheBasicPlaneIsTwoUnicodePositions(): void
    {
        self::assertSame(2, Encoder::unicodeLength('😀'));
        self::assertSame(4, Encoder::unicodeLength('a😀b'));
        self::assertSame(70, Encoder::unicodeLength(str_repeat('😀', 35)));
    }
}
