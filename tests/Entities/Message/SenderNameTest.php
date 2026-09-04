<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Message;

use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Helpers\SenderName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The name a message goes out under, chapter 9.2.1 of SMS API v3.1.15. It is one of two things
 * and never anything in between: a text of at most eleven characters out of the set the chapter
 * allows, or a phone number in full international form. Whichever it is, it is answered for here
 * rather than at the gateway, and never quietly shortened into something the caller never wrote.
 */
#[CoversClass(Message::class)]
#[CoversClass(SenderName::class)]
final class SenderNameTest extends TestCase
{
    /**
     * The whole of what chapter 9.2.1 allows a text sender: letters, digits, a hyphen, a space
     * and a dot, eleven characters of them at the most.
     */
    public function testATextSenderOutOfTheAllowedSetIsKeptAsItWasWritten(): void
    {
        $message = new Message;
        $message->setSenderName('My-Shop v.2');

        self::assertSame('My-Shop v.2', $message->getSenderName());
    }

    /**
     * Eleven is the limit and not one below it.
     */
    public function testASenderOfExactlyElevenCharactersPasses(): void
    {
        $message = new Message;
        $message->setSenderName('ABCDEFGHIJK');

        self::assertSame('ABCDEFGHIJK', $message->getSenderName());
    }

    /**
     * The mistake this is here for. A longer name used to be cut to eleven characters and sent
     * as something the caller never wrote — a shop called "Zahradnictvo" reached the recipient
     * as "Zahradnict". Whether the name is too long is knowable here, so it is said here.
     */
    public function testALongerSenderIsRefusedRatherThanCut(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_TOO_LONG);

        $message->setSenderName('Zahradnictvo');
    }

    /**
     * Diacritics are outside the set of chapter 9.2.1 whatever they cost in bytes, and the
     * chapter warns that a character it does not list can take the delivery of the whole message
     * down with it. Cutting such a name by bytes ended it in the middle of a UTF-8 sequence; not
     * accepting it at all is the answer instead.
     */
    public function testASenderWithDiacriticsIsRefused(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_INVALID);

        $message->setSenderName('Zahradníc');
    }

    /**
     * Everything else the set has no room for, refused for the same reason and under the same
     * code: the gateway answers a sender it cannot write with WRONG_SENDER, and by then nothing
     * says which of the two inputs of the send was the wrong one.
     * @param string $senderName
     */
    #[DataProvider('provideSendersOutsideTheAllowedSet')]
    public function testASenderCarryingAnythingElseIsRefused(string $senderName): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_INVALID);

        $message->setSenderName($senderName);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideSendersOutsideTheAllowedSet(): array
    {
        return [
            'underscore' => ['My_Shop'],
            'exclamation mark' => ['Sale!'],
            'at sign' => ['a@b.sk'],
            'comma' => ['Shop, s.r.o'],
            'plus in a text' => ['A+B'],
            'newline' => ["Shop\nB"],
            /**
             * A newline at the very end is the one the anchor of the pattern used to let past:
             * "$" matches in front of a closing newline unless the pattern says otherwise, so
             * "Shop\n" passed the set and the newline travelled into "sndr" and into the
             * signature composed over it.
             */
            'trailing newline' => ["Shop\n"],
            'trailing carriage return and newline' => ["Shop\r\n"],
            'emoji' => ['Shop 🛒']
        ];
    }

    /**
     * A message that names no sender at all falls back to the one the account is registered
     * under, so the empty string is not a way of asking for that — it is a value nobody meant
     * to write.
     */
    public function testAnEmptySenderIsRefused(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_IS_EMPTY);

        $message->setSenderName('');
    }

    /**
     * A space is a character chapter 9.2.1 allows, so a name made of nothing but spaces passes
     * the set and still says nothing. It is refused as the empty name it is.
     */
    public function testASenderOfNothingButWhitespaceIsRefused(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_IS_EMPTY);

        $message->setSenderName('   ');
    }

    /**
     * The other thing a sender may be, chapter 9.2.1: a phone number in full international form,
     * written without the plus and without a leading zero. Twelve digits is longer than a text
     * sender may ever be, and that is exactly the point — the eleven character limit belongs to
     * the text sender and to nothing else.
     */
    public function testANumberInFullInternationalFormPasses(): void
    {
        $message = new Message;
        $message->setSenderName('421905123456');

        self::assertSame('421905123456', $message->getSenderName());
    }

    /**
     * A number carrying a closing newline is not a number. The anchor of the pattern used to let
     * it past — "$" matches in front of a closing newline unless the pattern says otherwise — and
     * the normalisation then dropped the newline without a word, so a sender nobody wrote was
     * accepted and sent. It is a text now, and answered for as the too long one it is.
     */
    public function testANumberCarryingATrailingNewlineIsNoNumber(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_TOO_LONG);

        $message->setSenderName("421905123456\n");
    }

    /**
     * The same number written the two ways a caller usually has it lying around. Both say the
     * same thing, so both are normalised into the one shape the gateway is given rather than
     * refused over a prefix that carries no information.
     * @param string $senderName
     */
    #[DataProvider('provideNumbersWrittenWithAnInternationalPrefix')]
    public function testAnInternationalPrefixIsNormalisedAway(string $senderName): void
    {
        $message = new Message;
        $message->setSenderName($senderName);

        self::assertSame('421905123456', $message->getSenderName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNumbersWrittenWithAnInternationalPrefix(): array
    {
        return [
            'plus' => ['+421905123456'],
            'double zero' => ['00421905123456']
        ];
    }

    /**
     * A number in the form it is dialled in at home is not the full form the chapter asks for,
     * and there is nothing here that could tell which country it belongs to — the sender of a
     * message has no country of its own the way a recipient has. It is not guessed at, and it is
     * not refused either: within the eleven characters it is a sender the text set of chapter
     * 9.2.1 can write, so it goes out as the digits the caller typed and nothing is invented.
     */
    public function testANumberInLocalFormGoesOutAsTheTextItIsWrittenAs(): void
    {
        $message = new Message;
        $message->setSenderName('0905123456');

        self::assertSame('0905123456', $message->getSenderName());
    }

    /**
     * The mistake this is here for. Chapter 9.2.1 lists digits among the characters a text sender
     * may be written out of, so a short code is a sender as good as "My-Shop" is. Reading every
     * all-digit sender as a phone number refused every one of them — no country hands out a number
     * four digits long — and an integration that had always sent under its short code could not
     * send at all.
     * @param string $senderName
     */
    #[DataProvider('provideShortCodes')]
    public function testAShortCodeIsATextSenderAndGoesOutAsItWasWritten(string $senderName): void
    {
        $message = new Message;
        $message->setSenderName($senderName);

        self::assertSame($senderName, $message->getSenderName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideShortCodes(): array
    {
        return [
            'a single digit' => ['5'],
            'a four digit short code' => ['1188'],
            'the longest one the text set holds' => ['88776655443']
        ];
    }

    /**
     * Digits alone do not make a number, and neither do they make a text of any length. What is
     * written entirely in digits and is no number some country hands out falls back to the text
     * rules; what those rules cannot take either is refused under a code of its own, so that a
     * caller can tell a mistyped number from a sender text the set has no room for.
     * @param string $senderName
     */
    #[DataProvider('provideDigitsThatAreNeitherANumberNorAText')]
    public function testDigitsThatAreNoNumberAndNoTextAreRefused(string $senderName): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_NOT_A_NUMBER);

        $message->setSenderName($senderName);
    }

    /**
     * Every one of them is over the eleven characters a text sender may be written in, which is
     * what leaves the number as the only thing they could have been.
     * @return array<string, array{string}>
     */
    public static function provideDigitsThatAreNeitherANumberNorAText(): array
    {
        return [
            'no country hands this out' => ['999999999999999'],
            'a leading zero left over' => ['00000421905123456'],
            'one digit past the text set' => ['887766554433']
        ];
    }

    /**
     * The plus and the double zero say the caller meant a number and nothing else, so a sender
     * carrying either is answered for as a number even when the digits under it would have passed
     * as a text on their own. The plus is outside the text set to begin with; the double zero is
     * not, which is what makes it worth stating.
     * @param string $senderName
     */
    #[DataProvider('provideInternationalPrefixesOnSomethingThatIsNoNumber')]
    public function testAnInternationalPrefixLeavesNoTextSenderToFallBackOn(string $senderName): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_NOT_A_NUMBER);

        $message->setSenderName($senderName);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInternationalPrefixesOnSomethingThatIsNoNumber(): array
    {
        return [
            'plus' => ['+1188'],
            'double zero' => ['001188']
        ];
    }

    /**
     * A number written with the spaces it is printed with is a text as far as this is concerned,
     * because a space is a character the text set allows — and as a text it is over the eleven
     * characters. The caller is told about the length rather than about the number, which is
     * what makes the two shapes worth keeping apart.
     */
    public function testANumberWrittenWithSpacesIsReadAsATextAndIsTooLong(): void
    {
        $message = new Message;

        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_SENDER_NAME_TOO_LONG);

        $message->setSenderName('421 905 123 456');
    }

    /**
     * Every case above carries a code of its own. WRONG_SENDER coming back from the gateway says
     * nothing beyond the name of the field; these say which of the four things went wrong.
     */
    public function testTheFourRefusalsCarryFourDifferentCodes(): void
    {
        $codes = [
            MessageInterface::ERROR_SENDER_NAME_IS_EMPTY,
            MessageInterface::ERROR_SENDER_NAME_TOO_LONG,
            MessageInterface::ERROR_SENDER_NAME_INVALID,
            MessageInterface::ERROR_SENDER_NAME_NOT_A_NUMBER
        ];

        self::assertSame($codes, array_values(array_unique($codes)));
    }
}
