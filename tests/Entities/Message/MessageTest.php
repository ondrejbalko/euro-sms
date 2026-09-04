<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Message;

use EuroSms\Entities\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Which alphabet a message goes out in, chapters 12.1 and 14.1 of SMS API v3.1.15. The caller is
 * asked for nothing: a text the gateway can write in GSM 03.38 is sent in it, and one it cannot
 * goes out with diacritics. Saying so by hand stays possible, and stays the caller's own answer.
 */
#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    private const string PLAIN_TEXT = 'Testovacia sprava';
    private const string DIACRITICS_TEXT = 'Ďakujeme za objednávku, tovar posielame ešte dnes.';

    /**
     * Nobody sets anything and the plain text is sent as the plain text it is, keeping the
     * hundred and sixty characters of chapter 12.1.
     */
    public function testAPlainTextIsNotTakenForAUnicodeOne(): void
    {
        $message = new Message;
        $message->setContent(self::PLAIN_TEXT);

        self::assertFalse($message->isUnicode());
    }

    /**
     * The mistake this is here for: a text with diacritics sent as a plain one reaches the
     * recipient with a question mark in the place of every accented character, chapter 14.1.
     */
    public function testATextWithDiacriticsIsRecognisedWithoutBeingTold(): void
    {
        $message = new Message;
        $message->setContent(self::DIACRITICS_TEXT);

        self::assertTrue($message->isUnicode());
    }

    /**
     * The euro sign is a character of the extension table of chapter 12.2, so a text carrying one
     * is still a plain text — it merely costs two positions instead of one.
     */
    public function testAnExtendedCharacterDoesNotMakeTheMessageAUnicodeOne(): void
    {
        $message = new Message;
        $message->setContent('Cena je 10 € [zlava 5 %]');

        self::assertFalse($message->isUnicode());
    }

    /**
     * A message nobody has given a text to has nothing to decide by, and answers the way an empty
     * text would rather than blowing up on a property that was never written to.
     */
    public function testAMessageWithoutATextIsNoUnicodeMessage(): void
    {
        self::assertFalse((new Message)->isUnicode());
    }

    /**
     * Forcing diacritics on a plain text is allowed and costs the seventy character limit of
     * chapter 12.1 for nothing — but it is what the caller asked for.
     */
    public function testDiacriticsCanBeForcedOnAPlainText(): void
    {
        $message = new Message;
        $message->setContent(self::PLAIN_TEXT);
        $message->setUnicode(true);

        self::assertTrue($message->isUnicode());
    }

    /**
     * The other way round is the one that loses characters: a text with diacritics forced into
     * GSM 03.38 goes out with a question mark in the place of every character that alphabet has
     * no room for, chapter 14.1. The detection is bypassed all the same — the caller said so.
     */
    public function testTheGsmAlphabetCanBeForcedOnATextWithDiacritics(): void
    {
        $message = new Message;
        $message->setContent(self::DIACRITICS_TEXT);
        $message->setUnicode(false);

        self::assertFalse($message->isUnicode());
    }

    /**
     * The third answer, and the one a message starts with: hand over nothing and the text decides
     * again, whatever it was forced to beforehand.
     */
    public function testTheForcedAlphabetIsHandedBackToTheText(): void
    {
        $message = new Message;
        $message->setContent(self::DIACRITICS_TEXT);
        $message->setUnicode(false);
        $message->setUnicode();

        self::assertTrue($message->isUnicode());
    }

    /**
     * Nothing about the alphabet is decided when the text is set, so a text handed over after the
     * fact is read the same way as one handed over before.
     */
    public function testTheTextIsReadWheneverTheAnswerIsAskedFor(): void
    {
        $message = new Message;

        self::assertFalse($message->isUnicode());

        $message->setContent(self::DIACRITICS_TEXT);

        self::assertTrue($message->isUnicode());

        $message->setContent(self::PLAIN_TEXT);

        self::assertFalse($message->isUnicode());
    }
}
