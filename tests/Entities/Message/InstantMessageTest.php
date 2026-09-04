<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Message;

use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Exception\InstantMessageException;
use EuroSms\Gateway\GatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape of the "im" object and the bounds of its fields come from chapter 10 of
 * SMS API v3.1.15, the thousand characters a Viber message holds from chapter 14.1.
 */
#[CoversClass(InstantMessage::class)]
final class InstantMessageTest extends TestCase
{
    private const string SENDER = 'RZi Viber';
    private const string TEXT = 'Ďakujeme za objednávku, tovar posielame ešte dnes.';

    public function testAFreshVariantCarriesNothing(): void
    {
        $instantMessage = new InstantMessage;

        self::assertTrue($instantMessage->isEmpty());
        self::assertSame([], $instantMessage->getData());
        self::assertNull($instantMessage->getSenderName());
        self::assertNull($instantMessage->getTtl());
        self::assertNull($instantMessage->getContent());
    }

    public function testTheObjectCarriesTheSenderTheTimeToLiveAndTheText(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName(self::SENDER);
        $instantMessage->setTtl(600);
        $instantMessage->setContent(self::TEXT);

        self::assertSame([
            'sndr' => self::SENDER,
            'ttl' => 600,
            'msg' => self::TEXT,
        ], $instantMessage->getData());
        self::assertFalse($instantMessage->isEmpty());
    }

    /**
     * What the object does not carry falls back to the "dimsndr" and "dimttl" defaults of the
     * transaction, so a variant that only differs in its text writes out nothing else.
     */
    public function testWhatWasNeverSetIsLeftOutOfTheObject(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setContent(self::TEXT);

        self::assertSame(['msg' => self::TEXT], $instantMessage->getData());
        self::assertFalse($instantMessage->isEmpty());
    }

    public function testAVariantThatOnlyNamesItsSenderIsNotEmpty(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName(self::SENDER);

        self::assertSame(['sndr' => self::SENDER], $instantMessage->getData());
        self::assertFalse($instantMessage->isEmpty());
    }

    /**
     * The Viber sender is registered with the operator, so it is not shortened to the eleven
     * characters chapter 9.3.1 gives an SMS sender.
     */
    public function testTheSenderIsNotShortenedToTheLengthOfAnSmsSender(): void
    {
        $sender = str_repeat('a', MessageInterface::MAX_SENDER_NAME_LENGTH + 5);

        $instantMessage = new InstantMessage;
        $instantMessage->setSenderName($sender);

        self::assertSame($sender, $instantMessage->getSenderName());
    }

    public function testASenderWithoutANameIsRefused(): void
    {
        $instantMessage = new InstantMessage;

        $this->expectException(InstantMessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_INSTANT_MESSAGE_SENDER_IS_EMPTY);

        $instantMessage->setSenderName('');
    }

    public function testAnEmptyTextIsRefused(): void
    {
        $instantMessage = new InstantMessage;

        $this->expectException(InstantMessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_INSTANT_MESSAGE_TEXT_IS_EMPTY);

        $instantMessage->setContent('');
    }

    public function testATextOfAThousandCharactersIsAccepted(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setContent(str_repeat('a', GatewayInterface::IM_MAX_LENGTH));

        self::assertSame(GatewayInterface::IM_MAX_LENGTH, strlen((string)$instantMessage->getContent()));
    }

    public function testATextLongerThanAThousandCharactersIsRefusedBeforeItIsSent(): void
    {
        $instantMessage = new InstantMessage;

        $this->expectException(InstantMessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_INSTANT_MESSAGE_TEXT_TOO_LONG);

        $instantMessage->setContent(str_repeat('a', GatewayInterface::IM_MAX_LENGTH + 1));
    }

    /**
     * Chapter 14.1 counts the thousand characters with diacritics and all, so what is measured
     * are characters and not the bytes they take up.
     */
    public function testAThousandCharactersWithDiacriticsStillFit(): void
    {
        $content = str_repeat('á', GatewayInterface::IM_MAX_LENGTH);

        $instantMessage = new InstantMessage;
        $instantMessage->setContent($content);

        self::assertSame($content, $instantMessage->getContent());
        self::assertGreaterThan(GatewayInterface::IM_MAX_LENGTH, strlen($content));
    }

    public function testOneCharacterWithDiacriticsTooManyIsRefused(): void
    {
        $instantMessage = new InstantMessage;

        $this->expectException(InstantMessageException::class);

        $instantMessage->setContent(str_repeat('á', GatewayInterface::IM_MAX_LENGTH + 1));
    }

    /**
     * @param int $ttl
     */
    #[DataProvider('acceptedTimesToLive')]
    public function testATimeToLiveInsideTheAllowedRangeIsKept(int $ttl): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setTtl($ttl);

        self::assertSame($ttl, $instantMessage->getTtl());
    }

    /**
     * Fifteen seconds and a whole day are the bounds themselves, so both of them still fit.
     * @return array<string, array{int}>
     */
    public static function acceptedTimesToLive(): array
    {
        return [
            'the shortest one allowed' => [GatewayInterface::IM_TTL_MIN],
            'ten minutes' => [600],
            'the longest one allowed' => [GatewayInterface::IM_TTL_MAX],
        ];
    }

    /**
     * @param int $ttl
     */
    #[DataProvider('refusedTimesToLive')]
    public function testATimeToLiveOutsideTheAllowedRangeIsRefusedBeforeItIsSent(int $ttl): void
    {
        $instantMessage = new InstantMessage;

        $this->expectException(InstantMessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_INSTANT_MESSAGE_TTL_OUT_OF_RANGE);

        $instantMessage->setTtl($ttl);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function refusedTimesToLive(): array
    {
        return [
            'no time at all' => [0],
            'a negative one' => [-1],
            'one second short of the range' => [GatewayInterface::IM_TTL_MIN - 1],
            'one second past the range' => [GatewayInterface::IM_TTL_MAX + 1],
        ];
    }

    /**
     * A refused value leaves the object as it was, so nothing half-set reaches the request.
     */
    public function testARefusedValueChangesNothing(): void
    {
        $instantMessage = new InstantMessage;
        $instantMessage->setTtl(600);

        try {
            $instantMessage->setTtl(GatewayInterface::IM_TTL_MAX + 1);
        } catch (InstantMessageException) {
            // the value below is what matters, not the exception itself
        }

        self::assertSame(600, $instantMessage->getTtl());
    }
}
