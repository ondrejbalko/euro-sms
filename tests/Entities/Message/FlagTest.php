<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Message;

use EuroSms\Entities\Message\Flag;
use EuroSms\Enums\FlagEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Flag values and the worked examples come from chapters 14.1 and 14.2 of SMS API v3.1.15.
 */
#[CoversClass(Flag::class)]
#[CoversClass(FlagEnum::class)]
final class FlagTest extends TestCase
{
    public function testNoFlagGivesTheDefaultValue(): void
    {
        $flag = new Flag;

        self::assertSame(FlagEnum::DEFAULT->value, $flag->getValue());
    }

    public function testReceiptAloneGivesOne(): void
    {
        $flag = new Flag;
        $flag->addReceipt();

        self::assertSame(1, $flag->getValue());
    }

    /**
     * Chapter 14.2, first example: a long message without diacritics.
     */
    public function testLongMessageWithReceiptGivesThree(): void
    {
        $flag = new Flag;
        $flag->addReceipt();
        $flag->addLong();

        self::assertSame(3, $flag->getValue());
    }

    /**
     * Chapter 14.2, second example: a long message with diacritics. The two flags are separate
     * bits, so the value is six and never twelve.
     */
    public function testLongMessageWithDiacriticsGivesSix(): void
    {
        $flag = new Flag;
        $flag->addLong();
        $flag->addUnicodeShort();

        self::assertSame(6, $flag->getValue());
    }

    public function testLongMessageWithDiacriticsAndReceiptGivesSeven(): void
    {
        $flag = new Flag;
        $flag->addReceipt();
        $flag->addLong();
        $flag->addUnicodeShort();

        self::assertSame(7, $flag->getValue());
    }

    /**
     * There is no flag of its own for a long message with diacritics, the shorthand only turns
     * on the long bit and the diacritics bit.
     */
    public function testTheLongUnicodeShorthandTurnsOnBothBits(): void
    {
        $flag = new Flag;
        $flag->addUnicodeLong();

        self::assertSame(6, $flag->getValue());
    }

    public function testTheLongUnicodeShorthandNextToTheDiacriticsFlagStaysAtSix(): void
    {
        $flag = new Flag;
        $flag->addUnicodeShort();
        $flag->addUnicodeLong();

        self::assertSame(6, $flag->getValue());
    }

    /**
     * Chapter 14.2, third example: a campaign message, long, without diacritics.
     */
    public function testCampaignLongMessageWithReceiptGivesThirtyFive(): void
    {
        $flag = new Flag;
        $flag->addReceipt();
        $flag->addLong();
        $flag->addPriorityLow();

        self::assertSame(35, $flag->getValue());
    }

    /**
     * Chapter 14.2, fourth example: a long message without diacritics, Viber first, SMS as a fallback.
     */
    public function testLongViberMessageWithReceiptGivesFiveHundredFifteen(): void
    {
        $flag = new Flag;
        $flag->addReceipt();
        $flag->addLong();
        $flag->addViber();

        self::assertSame(515, $flag->getValue());
    }

    /**
     * Chapter 14.1: "Viber only" is only meaningful together with the Viber flag itself.
     */
    public function testViberOnlyAlsoTurnsOnViber(): void
    {
        $flag = new Flag;
        $flag->addViberOnly();

        self::assertSame(640, $flag->getValue());
    }

    /**
     * Chapter 10.5: a promo message is only meaningful together with the Viber flag itself.
     */
    public function testViberPromoAlsoTurnsOnViber(): void
    {
        $flag = new Flag;
        $flag->addViberPromo();

        self::assertSame(768, $flag->getValue());
    }

    public function testTheSameFlagAddedTwiceIsCountedOnce(): void
    {
        $flag = new Flag;
        $flag->addReceipt();
        $flag->addReceipt();

        self::assertSame(1, $flag->getValue());
    }

    public function testSetFlagsKeepsWhatItWasGiven(): void
    {
        $flag = new Flag;
        $flag->setFlags([FlagEnum::RECEIPT, FlagEnum::LOW_PRIORITY]);

        self::assertSame(33, $flag->getValue());
    }

    /**
     * A value that is not a flag has no case of its own, so it cannot even be passed as one.
     */
    public function testAValueThatIsNotAFlagHasNoCase(): void
    {
        self::assertNull(FlagEnum::tryFrom(1024));
    }

    /**
     * Six is the value of two flags together, not a flag of its own, so it has to be passed
     * as the two flags it is made of.
     */
    public function testTheLongUnicodeValueIsNotAFlagOfItsOwn(): void
    {
        self::assertNull(FlagEnum::tryFrom(6));
    }

    /**
     * A value that is not a flag is dropped rather than kept until composing the value blows up.
     */
    public function testSetFlagsDropsWhatIsNotAFlag(): void
    {
        $flag = new Flag;
        $flag->setFlags([FlagEnum::RECEIPT, 1024, 'nonsense', null]);

        self::assertSame([FlagEnum::RECEIPT], $flag->getFlags());
        self::assertSame(1, $flag->getValue());
    }

    /**
     * The numbers of chapter 14.1 may be handed over as they are read there.
     */
    public function testSetFlagsAcceptsTheDocumentedNumbers(): void
    {
        $flag = new Flag;
        $flag->setFlags([1, 32]);

        self::assertSame([FlagEnum::RECEIPT, FlagEnum::LOW_PRIORITY], $flag->getFlags());
        self::assertSame(33, $flag->getValue());
    }

    /**
     * Six is two flags at once and no case of its own, so it is taken apart into the two bits
     * chapter 14.1 composes it out of rather than dropped for having no case to be looked up by.
     */
    public function testSetFlagsTakesApartAValueThatIsTwoFlagsAtOnce(): void
    {
        $flag = new Flag;
        $flag->setFlags([6]);

        self::assertSame([FlagEnum::LONG, FlagEnum::UNICODE_SHORT], $flag->getFlags());
        self::assertSame(6, $flag->getValue());
    }

    /**
     * The Viber bits are composed the same way, chapter 14.1: a message that may only go out over
     * Viber carries the Viber flag and the one that forbids the fallback.
     */
    public function testSetFlagsTakesApartAComposedViberValue(): void
    {
        $flag = new Flag;
        $flag->setFlags([640]);

        self::assertSame([FlagEnum::VIBER_ONLY, FlagEnum::VIBER], $flag->getFlags());
        self::assertSame(640, $flag->getValue());
    }

    /**
     * A bit the gateway knows no flag for is dropped out of the value it arrived in; the flags
     * that came with it are kept.
     */
    public function testSetFlagsKeepsTheFlagsOfAValueThatAlsoCarriesABitThatIsNone(): void
    {
        $flag = new Flag;
        $flag->setFlags([1025]);

        self::assertSame([FlagEnum::RECEIPT], $flag->getFlags());
        self::assertSame(1, $flag->getValue());
    }

    /**
     * Zero is the plain default and no bit at all, so it turns nothing on.
     */
    public function testSetFlagsReadsZeroAsTheDefault(): void
    {
        $flag = new Flag;
        $flag->setFlags([0]);

        self::assertSame([FlagEnum::DEFAULT], $flag->getFlags());
        self::assertSame(FlagEnum::DEFAULT->value, $flag->getValue());
    }

    public function testSetFlagsReplacesWhatWasAddedBefore(): void
    {
        $flag = new Flag;
        $flag->addPriorityHigh();
        $flag->setFlags([FlagEnum::RECEIPT]);

        self::assertSame(1, $flag->getValue());
    }

    /**
     * @param int $expected
     * @param FlagEnum $flag
     */
    #[DataProvider('documentedFlagValues')]
    public function testEachDocumentedFlagKeepsItsValue(int $expected, FlagEnum $flag): void
    {
        self::assertSame($expected, $flag->value);
    }

    /**
     * Chapter 14.1, the complete list of flags.
     * @return array<string, array{int, FlagEnum}>
     */
    public static function documentedFlagValues(): array
    {
        return [
            'receipt' => [1, FlagEnum::RECEIPT],
            'long message' => [2, FlagEnum::LONG],
            'diacritics' => [4, FlagEnum::UNICODE_SHORT],
            'higher priority' => [8, FlagEnum::HIGH_PRIORITY],
            'lower priority' => [32, FlagEnum::LOW_PRIORITY],
            'viber only' => [128, FlagEnum::VIBER_ONLY],
            'viber promo' => [256, FlagEnum::VIBER_PROMO],
            'viber' => [512, FlagEnum::VIBER],
        ];
    }

    /**
     * Every flag has exactly one bit of its own, so no two of them can overlap.
     */
    public function testKnownFlagsDoNotShareABit(): void
    {
        $value = FlagEnum::DEFAULT->value;

        foreach (Flag::all() as $known) {
            self::assertSame(0, $value & $known->value);

            $value |= $known->value;
        }
    }

    /**
     * all() is the whole of chapter 14.1 and says nothing about any one message, which is what
     * reading it off an instance made it look like it did.
     */
    public function testAllListsEveryKnownFlagWhateverTheMessageCarries(): void
    {
        $flag = new Flag;
        $flag->addReceipt();

        self::assertSame(FlagEnum::cases(), Flag::all());
        self::assertSame([FlagEnum::RECEIPT], $flag->getFlags());
    }

    /**
     * The mistake this is here for. Composing the value used to write the default back into the
     * set, so a message nobody had set a flag on stopped reading as one the moment anything asked
     * it what it goes out under — and a Flag cloned into a request differed depending on whether
     * it had been read first.
     */
    public function testReadingTheFlagsLeavesTheSetAsItWas(): void
    {
        $flag = new Flag;

        self::assertSame([FlagEnum::DEFAULT], $flag->getFlags());
        self::assertSame(FlagEnum::DEFAULT->value, $flag->getValue());
        self::assertSame([FlagEnum::DEFAULT], $flag->getFlags());

        $flag->addReceipt();

        self::assertSame([FlagEnum::RECEIPT], $flag->getFlags());
        self::assertSame(1, $flag->getValue());
    }

    /**
     * A flag added twice is one flag, and asking twice does not turn it into two.
     */
    public function testAFlagAddedTwiceIsListedOnce(): void
    {
        $flag = new Flag;
        $flag->addViber();
        $flag->addViber();

        self::assertSame([FlagEnum::VIBER], $flag->getFlags());
        self::assertSame([FlagEnum::VIBER], $flag->getFlags());
        self::assertSame(512, $flag->getValue());
    }
}
