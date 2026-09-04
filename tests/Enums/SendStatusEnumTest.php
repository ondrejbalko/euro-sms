<?php

declare(strict_types=1);

namespace EuroSms\Tests\Enums;

use EuroSms\Enums\SendStatusEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The codes are the ones printed in chapters 13.1 and 10.6 of SMS API v3.1.15, which together
 * are the complete list of what a send request can be answered with.
 */
#[CoversClass(SendStatusEnum::class)]
final class SendStatusEnumTest extends TestCase
{
    /**
     * Chapter 13.1, the codes every message shares.
     * @var list<string> CODES_COMMON
     */
    private const array CODES_COMMON = [
        'ENQUEUED',
        'NO_IID',
        'NO_MSG',
        'NO_RCPT',
        'NO_TXT',
        'NO_SGN',
        'NO_SNDR',
        'NO_BALANCE',
        'WRONG_SIGNATURE',
        'WRONG_IID',
        'WRONG_NUMBER',
        'WRONG_SENDER',
        'EMPTY_MESSAGE',
        'TOO_MANY_MESSAGES',
        'MSG_TOO_LONG',
        'ERR_OTHER'
    ];

    /**
     * Chapter 10.6, the codes an instant message adds on top of them.
     * @var list<string> CODES_INSTANT_MESSAGE
     */
    private const array CODES_INSTANT_MESSAGE = [
        'IM_NOT_ALLOWED',
        'IM_UNREGISTERED_SENDER',
        'IM_MSG_EMPTY',
        'IM_MSG_TOO_LONG',
        'IM_TTL_OUT_OF_RANGE'
    ];

    /**
     * The enum is the two chapters and nothing besides them, so that a code it does not know is
     * a code the documentation does not list either.
     */
    public function testTheEnumCarriesTheCodesOfBothChaptersAndNoOthers(): void
    {
        $documented = [...self::CODES_COMMON, ...self::CODES_INSTANT_MESSAGE];
        $cases = array_column(SendStatusEnum::cases(), 'value');

        sort($documented);
        sort($cases);

        self::assertSame($documented, $cases);
    }

    /**
     * @return array<string, array{SendStatusEnum}>
     */
    public static function caseProvider(): array
    {
        $cases = [];

        foreach (SendStatusEnum::cases() as $case) {
            $cases[strtolower($case->value)] = [$case];
        }

        return $cases;
    }

    /**
     * @param SendStatusEnum $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testEveryCodeIsWrittenOutInWords(SendStatusEnum $case): void
    {
        self::assertNotSame('', $case->getDescription());
        self::assertNotSame($case->value, $case->getDescription());
    }

    /**
     * A description shared by two codes would tell the two of them apart for nobody.
     */
    public function testNoTwoCodesShareADescription(): void
    {
        $descriptions = array_map(
            static fn (SendStatusEnum $case): string => $case->getDescription(),
            SendStatusEnum::cases()
        );

        self::assertCount(count($descriptions), array_unique($descriptions));
    }

    /**
     * Chapter 13.1: the message went out on ENQUEUED and on nothing else.
     * @param SendStatusEnum $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testOnlyEnqueuedMeansTheMessageWasTakenOver(SendStatusEnum $case): void
    {
        self::assertSame(SendStatusEnum::ENQUEUED === $case, $case->isEnqueued());
    }

    /**
     * @param SendStatusEnum $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testADocumentedCodeIsReadAsItself(SendStatusEnum $case): void
    {
        self::assertSame($case, SendStatusEnum::fromCode($case->value));
        self::assertTrue(SendStatusEnum::isKnownCode($case->value));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unknownCodeProvider(): array
    {
        return [
            'a code of no chapter' => ['SOMETHING_ELSE'],
            'the empty string' => [''],
            'the right code in the wrong case' => ['enqueued'],
            'nothing at all' => [null]
        ];
    }

    /**
     * A code the documentation does not list is the refusal the gateway did not name. It is
     * still told apart from an ERR_OTHER the gateway answered with itself.
     * @param string|null $code
     * @return void
     */
    #[DataProvider('unknownCodeProvider')]
    public function testAnUnknownCodeIsReadAsTheUnnamedRefusal(?string $code): void
    {
        self::assertSame(SendStatusEnum::ERR_OTHER, SendStatusEnum::fromCode($code));
        self::assertFalse(SendStatusEnum::isKnownCode($code));
    }

    public function testTheGatewaysOwnErrOtherIsKnown(): void
    {
        self::assertTrue(SendStatusEnum::isKnownCode(SendStatusEnum::ERR_OTHER->value));
    }
}
