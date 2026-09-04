<?php

declare(strict_types=1);

namespace EuroSms\Tests\Entities\Recipient;

use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\RecipientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A recipient is a phone number read into the international format of E.164 and, when the calling
 * system named one, the key it knows that number by. Chapter 9.2 of SMS API v3.1.15 lets a request
 * name a recipient as an object carrying that key instead of as a bare number, and chapter 9.4.2
 * hands the key back on the delivery report — which is the only thing a receipt can be matched to
 * a record of one's own by.
 */
#[CoversClass(Recipient::class)]
final class RecipientTest extends TestCase
{
    private const string IDENTIFIER = '21C334C7-CD7E-45D7-AA88-FC9C58A7FF47';
    private const string NUMBER = '+421903622237';

    public function testANationalNumberIsReadIntoTheInternationalFormat(): void
    {
        $recipient = new Recipient('0903 622 237');

        self::assertSame(self::NUMBER, $recipient->getNumber());
        self::assertSame(421903622237, $recipient->getNumberClean());
    }

    /**
     * The number as the caller wrote it stays readable next to the one the gateway is given.
     */
    public function testTheNumberIsKeptAsItWasWrittenAsWell(): void
    {
        self::assertSame('0903 622 237', (new Recipient('0903 622 237'))->getNumberOrig());
    }

    /**
     * E.164 is digits and nothing else, and a good half of the world writes its international
     * form with hyphens rather than with spaces — the United States and Russia among them. A
     * number read into that form kept its hyphens, and the number the gateway is addressed with
     * and the signature of chapter 9.2.2 is composed over both stop at the first of them.
     * @return void
     */
    public function testAnInternationalNumberWrittenWithHyphensIsStillReadIntoDigits(): void
    {
        self::assertSame('+12125550100', (new Recipient('+1 212 555 0100'))->getNumber());
        self::assertSame(12125550100, (new Recipient('+1 212 555 0100'))->getNumberClean());
        self::assertSame('+74951234567', (new Recipient('+7 495 123 45 67'))->getNumber());
        self::assertSame(74951234567, (new Recipient('+7 495 123 45 67'))->getNumberClean());
    }

    public function testANationalNumberOfAnotherCountryIsReadInThatCountry(): void
    {
        self::assertSame(420766121212, (new Recipient('766 121 212', 'CZ'))->getNumberClean());
    }

    public function testANumberThatIsNoNumberIsRefused(): void
    {
        $this->expectException(RecipientException::class);
        $this->expectExceptionCode(RecipientInterface::ERROR_WRONG_NUMBER);

        new Recipient('nothing to dial');
    }

    public function testARecipientCarriesNoKeyUntilItIsGivenOne(): void
    {
        $recipient = new Recipient(self::NUMBER);

        self::assertNull($recipient->getIdentifier());
        self::assertFalse($recipient->hasIdentifier());
    }

    public function testTheKeyCanBeNamedAlongWithTheNumber(): void
    {
        $recipient = new Recipient(self::NUMBER, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER);

        self::assertSame(self::IDENTIFIER, $recipient->getIdentifier());
        self::assertTrue($recipient->hasIdentifier());
    }

    public function testTheKeyCanBeGivenToARecipientThatAlreadyExists(): void
    {
        $recipient = new Recipient(self::NUMBER);
        $recipient->setIdentifier(self::IDENTIFIER);

        self::assertSame(self::IDENTIFIER, $recipient->getIdentifier());
        self::assertTrue($recipient->hasIdentifier());
    }

    public function testTheKeyCanBeTakenAwayAgain(): void
    {
        $recipient = new Recipient(self::NUMBER, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER);
        $recipient->setIdentifier(null);

        self::assertNull($recipient->getIdentifier());
        self::assertFalse($recipient->hasIdentifier());
    }

    /**
     * A record whose identifier was never filled in would otherwise be sent as an object naming
     * an empty key, so an empty one is read as no key at all.
     */
    public function testAnEmptyKeyIsNoKeyAtAll(): void
    {
        $recipient = new Recipient(self::NUMBER, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, '');

        self::assertNull($recipient->getIdentifier());
        self::assertFalse($recipient->hasIdentifier());
    }

    /**
     * The key is whatever the calling system files its records under, so it is neither trimmed
     * nor rewritten: it has to come back off a delivery report as the very same string.
     */
    public function testTheKeyIsKeptExactlyAsItWasGiven(): void
    {
        $recipient = new Recipient(self::NUMBER);
        $recipient->setIdentifier(' order/2026-0042 ');

        self::assertSame(' order/2026-0042 ', $recipient->getIdentifier());
    }

    /**
     * Two records may well be written to the same number — an order confirmation and the
     * verification code behind it — and telling them apart is what the key is for.
     */
    public function testTwoRecipientsMayShareANumberAndStillBeToldApart(): void
    {
        $confirmation = new Recipient(self::NUMBER, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, self::IDENTIFIER);
        $verification = new Recipient(self::NUMBER, RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, 'F0A2E9B1-6D44-4C7A-9E31-2B8C5D0A7E63');

        self::assertSame($confirmation->getNumberClean(), $verification->getNumberClean());
        self::assertNotSame($confirmation->getIdentifier(), $verification->getIdentifier());
    }
}
