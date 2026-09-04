<?php

declare(strict_types=1);

namespace EuroSms\Tests\CallBack;

use DateTimeImmutable;
use EuroSms\CallBack\Acknowledgement;
use EuroSms\CallBack\DeliveryReport;
use EuroSms\CallBack\ReceivedMessage;
use EuroSms\Enums\DeliveryResultEnum;
use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\CallBackException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two answers are the ones printed in chapters 6.1.6 and 6.2.4 of SMS API v3.1.15, the
 * identifier below being the one the documentation writes its own examples with. An answer of
 * any other shape leaves the CallBack unconfirmed, and an unconfirmed CallBack is pushed again
 * for 24 hours and then thrown away, chapter 6.1.1, so the exact wording is the whole of it.
 */
#[CoversClass(Acknowledgement::class)]
final class AcknowledgementTest extends TestCase
{
    private const string UUID = '59294546-9F34-4A01-858D-9B27FEBC99E2';

    /**
     * Chapter 6.1.6, quoted as it is printed there.
     */
    public function testThePlainAnswerIsWrittenAsTheDocumentationPrintsIt(): void
    {
        self::assertSame(
            'ok|59294546-9F34-4A01-858D-9B27FEBC99E2',
            Acknowledgement::ofUuid(self::UUID)->toPlain()
        );
    }

    /**
     * The gateway reads the whole body, so a line break or a space of its own would already be
     * another answer than the documented one.
     */
    public function testThePlainAnswerCarriesNothingAroundIt(): void
    {
        $answer = Acknowledgement::ofUuid(self::UUID)->toPlain();

        self::assertSame($answer, trim($answer));
        self::assertDoesNotMatchRegularExpression('/\s/', $answer);
    }

    /**
     * Chapter 6.1.6: the JSON answer names the message it confirms and the outcome, and ok is
     * the one outcome the gateway reads as confirmed.
     */
    public function testTheJsonAnswerNamesTheMessageAndTheOutcome(): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode(Acknowledgement::ofUuid(self::UUID)->toJson(), true);

        self::assertSame(
            [
                FieldEnum::CALLBACK_UUID->value => self::UUID,
                FieldEnum::CALLBACK_STATUS->value => Acknowledgement::STATUS_OK
            ],
            $decoded
        );
    }

    public function testTheJsonAnswerIsWhatTheAcknowledgementSerialisesInto(): void
    {
        $acknowledgement = Acknowledgement::ofUuid(self::UUID);

        self::assertSame($acknowledgement->toJson(), json_encode($acknowledgement));
    }

    /**
     * A notification is confirmed by naming the very message it reported on, so the answer is
     * built from the notification rather than from an identifier the handler carried around.
     */
    public function testADeliveryReportIsConfirmedByTheMessageItReportedOn(): void
    {
        $report = new DeliveryReport(self::UUID, DeliveryResultEnum::DELIVERED);

        self::assertSame('ok|' . self::UUID, Acknowledgement::of($report)->toPlain());
    }

    public function testAReceivedMessageIsConfirmedByItsOwnIdentifier(): void
    {
        $message = new ReceivedMessage(
            self::UUID,
            '421902000111',
            new DateTimeImmutable('2019-01-15 12:21:04'),
            '421903170552',
            'Ahoj, prídem o piatej.'
        );

        self::assertSame(self::UUID, Acknowledgement::of($message)->getUuid());
    }

    public function testTheAnswerIsWrittenPlainWhereverItIsUsedAsAString(): void
    {
        self::assertSame('ok|' . self::UUID, (string)Acknowledgement::ofUuid(self::UUID));
    }

    public function testAnAnswerNamingNoMessageIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(Acknowledgement::ERROR_UUID_NOT_DEFINED);

        (void)Acknowledgement::ofUuid('');
    }
}
