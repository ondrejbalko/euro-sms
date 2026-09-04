<?php

declare(strict_types=1);

namespace EuroSms\Tests\CallBack;

use EuroSms\CallBack\CallBackParser;
use EuroSms\CallBack\DeliveryReport;
use EuroSms\CallBack\ReceivedMessage;
use EuroSms\Enums\DeliveryResultEnum;
use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\CallBackException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bodies below are the ones chapter 6 of SMS API v3.1.15 describes: the JSON delivery report
 * is the worked example printed in chapter 6.1.5, the plain one states the very same keys as the
 * query parameters of chapter 6.1.2, and the received message carries the parameters listed in
 * chapter 6.2.3.
 */
#[CoversClass(CallBackParser::class)]
#[CoversClass(DeliveryReport::class)]
#[CoversClass(DeliveryResultEnum::class)]
#[CoversClass(ReceivedMessage::class)]
final class CallBackParserTest extends TestCase
{
    private const string UUID = '60c525e2-b28a-4e92-9a0f-58dacfa23a86';

    private const string RECEIVED_UUID = '59294546-9f34-4a01-858d-9b27febc99e2';

    /**
     * The worked example of chapter 6.1.5, quoted as it is printed there.
     */
    private const string REPORT_JSON = '{
        "sent_result": "OK",
        "sent_time": "2019-01-15 12:20:13",
        "delivery_result": "DELIVRD",
        "delivery_time": "2019-01-15 12:20:22",
        "operator": "231.1",
        "price": 0.031,
        "sms_uuid": "60c525e2-b28a-4e92-9a0f-58dacfa23a86",
        "segment": 1
    }';

    /**
     * The same report stated as the query of chapter 6.1.2.
     */
    private const string REPORT_QUERY = 'sent_result=OK&sent_time=2019-01-15+12%3A20%3A13'
        . '&delivery_result=DELIVRD&delivery_time=2019-01-15+12%3A20%3A22&operator=231.1'
        . '&price=0.031&sms_uuid=60c525e2-b28a-4e92-9a0f-58dacfa23a86&segment=1';

    private const string RECEIVED_JSON = '{
        "sms_uuid": "59294546-9f34-4a01-858d-9b27febc99e2",
        "recipient": "421902000111",
        "receive_time": "2019-01-15 12:21:04",
        "sender": "421903170552",
        "sms_text": "Ahoj, prídem o piatej."
    }';

    private const string RECEIVED_QUERY = 'sms_uuid=59294546-9f34-4a01-858d-9b27febc99e2'
        . '&recipient=421902000111&receive_time=2019-01-15+12%3A21%3A04&sender=421903170552'
        . '&sms_text=Ahoj%2C+pr%C3%ADdem+o+piatej.';

    /**
     * @return array<string, array{DeliveryResultEnum, bool}>
     */
    public static function deliveryResults(): array
    {
        return [
            'accepted' => [DeliveryResultEnum::ACCEPTED, false],
            'en route' => [DeliveryResultEnum::EN_ROUTE, false],
            'deleted' => [DeliveryResultEnum::DELETED, true],
            'delivered' => [DeliveryResultEnum::DELIVERED, true],
            'expired' => [DeliveryResultEnum::EXPIRED, true],
            'rejected' => [DeliveryResultEnum::REJECTED, true],
            'seen' => [DeliveryResultEnum::SEEN, false],
            'undeliverable' => [DeliveryResultEnum::UNDELIVERABLE, true],
            'unknown' => [DeliveryResultEnum::UNKNOWN, true]
        ];
    }

    /**
     * Chapter 6.1.5: every field of the worked example is read back the way the documentation
     * states it, so what this library understands by a delivery report is checked against the
     * gateway's own example rather than against itself.
     */
    public function testTheWorkedExampleOfTheDocumentationIsReadFieldByField(): void
    {
        $report = CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_DELIVERY_REPORT->value => self::REPORT_JSON
        ]);

        self::assertSame(self::UUID, $report->getUuid());
        self::assertSame('OK', $report->getSentResult());
        self::assertSame('2019-01-15 12:20:13', $report->getSentTime()?->format('Y-m-d H:i:s'));
        self::assertSame(DeliveryResultEnum::DELIVERED, $report->getDeliveryResult());
        self::assertSame('2019-01-15 12:20:22', $report->getDeliveryTime()?->format('Y-m-d H:i:s'));
        self::assertSame('231.1', $report->getOperator());
        self::assertSame(0.031, $report->getPrice());
        self::assertSame(1, $report->getSegment());
        self::assertTrue($report->isDelivered());
        self::assertTrue($report->isFinal());
    }

    /**
     * Chapters 6.1.2 and 6.1.3: the JSON shape wraps the very same keys the plain shape states
     * as query parameters, so which of the two the CallBack was registered with makes no
     * difference to what the caller ends up holding.
     */
    public function testTheTwoShapesOfADeliveryReportAreReadIntoTheSameReport(): void
    {
        self::assertEquals(
            CallBackParser::parse([FieldEnum::CALLBACK_DELIVERY_REPORT->value => self::REPORT_JSON]),
            CallBackParser::parseQuery(self::REPORT_QUERY)
        );
    }

    /**
     * Chapters 6.2.1 and 6.2.2, the same thing for an incoming message.
     */
    public function testTheTwoShapesOfAReceivedMessageAreReadIntoTheSameMessage(): void
    {
        self::assertEquals(
            CallBackParser::parse([FieldEnum::CALLBACK_RECEIVED->value => self::RECEIVED_JSON]),
            CallBackParser::parseQuery(self::RECEIVED_QUERY)
        );
    }

    /**
     * Chapter 6.2.3, and chapter 6.2 for the whole of a long message arriving as one: an
     * incoming message states no segment because there is never more than one of it.
     */
    public function testEveryFieldOfAReceivedMessageIsReadBack(): void
    {
        $message = CallBackParser::receivedMessage([FieldEnum::CALLBACK_RECEIVED->value => self::RECEIVED_JSON]);

        self::assertSame(self::RECEIVED_UUID, $message->getUuid());
        self::assertSame('421902000111', $message->getRecipient());
        self::assertSame('2019-01-15 12:21:04', $message->getReceiveTime()->format('Y-m-d H:i:s'));
        self::assertSame('421903170552', $message->getSender());
        self::assertSame('Ahoj, prídem o piatej.', $message->getText());
    }

    /**
     * Chapter 13.2: the status is read into the case that stands for it, and the ones the
     * documentation does not mark as final are read as one more report still to come.
     * @param DeliveryResultEnum $result
     * @param bool $final
     */
    #[DataProvider('deliveryResults')]
    public function testTheDeliveryStatusIsReadAsTheDocumentationListsIt(DeliveryResultEnum $result, bool $final): void
    {
        $report = CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => $result->value
        ]);

        self::assertSame($result, $report->getDeliveryResult());
        self::assertSame($final, $report->isFinal());
        self::assertSame(DeliveryResultEnum::DELIVERED === $result, $report->isDelivered());
    }

    /**
     * Chapter 13.2: SEEN is reported for instant messages alone, so a report saying it came from
     * Viber. It is not a delivery, and it is none of the five statuses the chapter marks as
     * final either: the delivery it follows was closed by a report of its own.
     */
    public function testTheViberStatusIsReadAsSeenAndNotAsDelivered(): void
    {
        $report = CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => DeliveryResultEnum::SEEN->value
        ]);

        self::assertTrue($report->isSeen());
        self::assertFalse($report->isFinal());
        self::assertFalse($report->isDelivered());
    }

    /**
     * Chapter 6.1.4 states the operator as a field that is not filled in for every destination,
     * and a report that has not been delivered yet has no delivery time to state either. The
     * plain shape sends such a parameter as an empty one, which is no statement at all.
     */
    public function testTheFieldsTheGatewayLeavesOutAreReadAsUnstated(): void
    {
        $report = CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => DeliveryResultEnum::EN_ROUTE->value,
            FieldEnum::CALLBACK_OPERATOR->value => '',
            FieldEnum::CALLBACK_PRICE->value => '',
            FieldEnum::CALLBACK_SEGMENT->value => '',
            FieldEnum::CALLBACK_DELIVERY_TIME->value => ''
        ]);

        self::assertFalse($report->isFinal());
        self::assertNull($report->getOperator());
        self::assertNull($report->getPrice());
        self::assertNull($report->getSegment());
        self::assertNull($report->getDeliveryTime());
        self::assertNull($report->getSentResult());
        self::assertNull($report->getSentTime());
    }

    /**
     * A caller holding the address the gateway called rather than the parameters parsed out of
     * it reads the one as readily as the other.
     */
    public function testTheWholeAddressTheGatewayCalledIsReadAsWell(): void
    {
        self::assertEquals(
            CallBackParser::parseQuery(self::REPORT_QUERY),
            CallBackParser::parseQuery('https://callback.zakaznicky.system.com/sms?' . self::REPORT_QUERY)
        );
    }

    /**
     * The JSON parameter is read whether it arrived as the document itself or as something the
     * customer system had decoded already.
     */
    public function testAJsonParameterThatWasDecodedAlreadyIsReadJustTheSame(): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = (array)json_decode(self::REPORT_JSON, true);

        self::assertEquals(
            CallBackParser::parse([FieldEnum::CALLBACK_DELIVERY_REPORT->value => self::REPORT_JSON]),
            CallBackParser::parse([FieldEnum::CALLBACK_DELIVERY_REPORT->value => $decoded])
        );
    }

    /**
     * A CallBack that goes unconfirmed is repeated for 24 hours and then thrown away, chapter
     * 6.1.1, so a body that cannot be read is refused rather than answered with half of one.
     */
    public function testAReportWithoutItsIdentifierIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_MISSING);

        (void)CallBackParser::parse([
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => DeliveryResultEnum::DELIVERED->value
        ]);
    }

    public function testAReportWithoutItsDeliveryStatusIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_MISSING);

        (void)CallBackParser::parse([
            FieldEnum::CALLBACK_SENT_RESULT->value => 'OK',
            FieldEnum::CALLBACK_UUID->value => self::UUID
        ]);
    }

    /**
     * Chapter 13.2 calls itself the complete list of delivery statuses, so a status that is not
     * on it is a body this library does not understand rather than one it passes on unread.
     */
    public function testADeliveryStatusTheDocumentationDoesNotListIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_NOT_READABLE);

        (void)CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => 'DORUCENE'
        ]);
    }

    public function testAReceivedMessageWithoutItsTextIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_MISSING);

        (void)CallBackParser::parseQuery(
            'sms_uuid=' . self::RECEIVED_UUID . '&recipient=421902000111'
            . '&receive_time=2019-01-15+12%3A21%3A04&sender=421903170552&sms_text='
        );
    }

    /**
     * Chapters 6.1.4 and 6.2.3 state the times as yyyy-MM-dd HH:mm:ss and nothing else, so a
     * value the format does not describe exactly is refused rather than bent into a date.
     */
    public function testATimeStatedOtherwiseThanDocumentedIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_NOT_READABLE);

        (void)CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => DeliveryResultEnum::DELIVERED->value,
            FieldEnum::CALLBACK_DELIVERY_TIME->value => '15.1.2019 12:20'
        ]);
    }

    public function testAPriceThatIsNoNumberIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_NOT_READABLE);

        (void)CallBackParser::deliveryReport([
            FieldEnum::CALLBACK_UUID->value => self::UUID,
            FieldEnum::CALLBACK_DELIVERY_RESULT->value => DeliveryResultEnum::DELIVERED->value,
            FieldEnum::CALLBACK_PRICE->value => '0,031'
        ]);
    }

    public function testAFieldStatingMoreThanOneValueIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_FIELD_NOT_READABLE);

        (void)CallBackParser::parseQuery('sms_uuid[]=' . self::UUID . '&delivery_result=DELIVRD');
    }

    public function testAJsonParameterThatDoesNotDecodeIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_BODY_NOT_READABLE);

        (void)CallBackParser::parse([FieldEnum::CALLBACK_DELIVERY_REPORT->value => '{"sms_uuid": ']);
    }

    public function testAJsonParameterThatIsNoObjectIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_BODY_NOT_READABLE);

        (void)CallBackParser::parse([FieldEnum::CALLBACK_RECEIVED->value => '"ok"']);
    }

    public function testABodyThatIsNeitherNotificationIsRefused(): void
    {
        $this->expectException(CallBackException::class);
        $this->expectExceptionCode(CallBackParser::ERROR_BODY_NOT_RECOGNISED);

        (void)CallBackParser::parseQuery('sms_uuid=' . self::UUID);
    }
}
