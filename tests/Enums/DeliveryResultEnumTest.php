<?php

declare(strict_types=1);

namespace EuroSms\Tests\Enums;

use EuroSms\Enums\DeliveryResultEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The statuses are the ones printed in chapter 13.2 of SMS API v3.1.15, which calls itself the
 * complete list of them.
 */
#[CoversClass(DeliveryResultEnum::class)]
final class DeliveryResultEnumTest extends TestCase
{
    /**
     * The five statuses chapter 13.2 marks as final.
     * @var list<string> CODES_FINAL
     */
    private const array CODES_FINAL = ['DELIVRD', 'UNDELIV', 'EXPIRED', 'REJECTD', 'DELETED'];

    /**
     * @var list<string> CODES_PENDING
     */
    private const array CODES_PENDING = ['ENROUTE', 'ACCEPTD', 'SEEN'];

    /**
     * The enum is the chapter and nothing besides it, UNKNOWN aside, which is what an unlisted
     * status is read as.
     */
    public function testTheEnumCarriesTheStatusesOfTheChapterAndNoOthers(): void
    {
        $documented = [...self::CODES_FINAL, ...self::CODES_PENDING, 'UNKNOWN'];
        $cases = array_column(DeliveryResultEnum::cases(), 'value');

        sort($documented);
        sort($cases);

        self::assertSame($documented, $cases);
    }

    /**
     * @return array<string, array{DeliveryResultEnum}>
     */
    public static function caseProvider(): array
    {
        $cases = [];

        foreach (DeliveryResultEnum::cases() as $case) {
            $cases[strtolower($case->value)] = [$case];
        }

        return $cases;
    }

    /**
     * @param DeliveryResultEnum $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testEveryStatusIsWrittenOutInWords(DeliveryResultEnum $case): void
    {
        self::assertNotSame('', $case->getDescription());
        self::assertNotSame($case->value, $case->getDescription());
    }

    public function testNoTwoStatusesShareADescription(): void
    {
        $descriptions = array_map(
            static fn (DeliveryResultEnum $case): string => $case->getDescription(),
            DeliveryResultEnum::cases()
        );

        self::assertCount(count($descriptions), array_unique($descriptions));
    }

    /**
     * @return array<string, array{DeliveryResultEnum, bool}>
     */
    public static function finalityProvider(): array
    {
        return [
            'accepted' => [DeliveryResultEnum::ACCEPTED, false],
            'deleted' => [DeliveryResultEnum::DELETED, true],
            'delivered' => [DeliveryResultEnum::DELIVERED, true],
            'en route' => [DeliveryResultEnum::EN_ROUTE, false],
            'expired' => [DeliveryResultEnum::EXPIRED, true],
            'rejected' => [DeliveryResultEnum::REJECTED, true],
            'seen' => [DeliveryResultEnum::SEEN, false],
            'undeliverable' => [DeliveryResultEnum::UNDELIVERABLE, true],
            'unknown' => [DeliveryResultEnum::UNKNOWN, true]
        ];
    }

    /**
     * A polling loop stops on a final status and keeps asking on the rest, so the five the
     * chapter marks are the five that close the message.
     * @param DeliveryResultEnum $case
     * @param bool $final
     * @return void
     */
    #[DataProvider('finalityProvider')]
    public function testOnlyTheStatusesTheChapterMarksCloseTheMessage(DeliveryResultEnum $case, bool $final): void
    {
        self::assertSame($final, $case->isFinal());
    }

    /**
     * SEEN follows a report of its own that already delivered the message, so it says nothing
     * about the delivery having ended.
     */
    public function testSeenDoesNotCloseTheMessage(): void
    {
        self::assertFalse(DeliveryResultEnum::SEEN->isFinal());
        self::assertTrue(DeliveryResultEnum::DELIVERED->isFinal());
    }

    /**
     * @param DeliveryResultEnum $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testADocumentedStatusIsReadAsItself(DeliveryResultEnum $case): void
    {
        self::assertSame($case, DeliveryResultEnum::fromCode($case->value));
        self::assertTrue(DeliveryResultEnum::isKnownCode($case->value));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unknownCodeProvider(): array
    {
        return [
            'a status of no chapter' => ['SOMETHING_ELSE'],
            'the empty string' => [''],
            'the right status in the wrong case' => ['delivrd'],
            'the name rather than the code' => ['DELIVERED'],
            'nothing at all' => [null]
        ];
    }

    /**
     * An unlisted status is the error the operator left unnamed, and stays tellable from an
     * UNKNOWN the operator reported itself.
     * @param string|null $code
     * @return void
     */
    #[DataProvider('unknownCodeProvider')]
    public function testAnUnknownStatusIsReadAsTheUnnamedError(?string $code): void
    {
        self::assertSame(DeliveryResultEnum::UNKNOWN, DeliveryResultEnum::fromCode($code));
        self::assertFalse(DeliveryResultEnum::isKnownCode($code));
    }

    public function testTheOperatorsOwnUnknownIsKnown(): void
    {
        self::assertTrue(DeliveryResultEnum::isKnownCode(DeliveryResultEnum::UNKNOWN->value));
    }
}
