<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Status;

use EuroSms\Enums\DeliveryResultEnum;
use EuroSms\Gateway\Status\DeliveryReport;
use EuroSms\Gateway\Status\StatusResponse;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bodies are the ones printed in chapters 9.4.2 and 9.4.5 of SMS API v3.1.15: status/one
 * answers with a bare list of reports, status/group and status/any wrap the same reports into an
 * envelope of dlrs, count and err_code.
 */
#[CoversClass(StatusResponse::class)]
#[CoversClass(DeliveryReport::class)]
final class StatusResponseTest extends TestCase
{
    private const string RESPONSE_ONE = '[
        {
            "rcpt": 421903170552,
            "carrier": "231.6",
            "price": 0.0265,
            "dlr_time": "2017-11-23 08:05:25",
            "sgmnt": 2,
            "dlr": "DELIVRD",
            "i": "be863034-df6b-5e3f-b681-649bf7017b9e",
            "snd": "2017-11-23 08:05:21",
            "err_code": "OK",
            "f": null
        }
    ]';

    private const string RESPONSE_GROUP = '{
        "dlrs": [
            {
                "rcpt": 421902589458,
                "carrier": "231.2",
                "dlr_time": "2017-11-28 12:22:02",
                "f": null,
                "price": 0.032,
                "snd": "2017-11-28 12:22:03",
                "i": "e58e883f-4518-5ed7-b169-9e346f78503d",
                "sgmnt": 1,
                "dlr": "EXPIRED"
            },
            {
                "rcpt": 421915837327,
                "carrier": "231.6",
                "dlr_time": "2017-11-28 12:20:13",
                "f": null,
                "price": 0.037,
                "snd": "2017-11-28 12:20:13",
                "i": "3293a313-9490-575d-a8d1-0f8604409ae5",
                "sgmnt": 1,
                "dlr": "EXPIRED"
            }
        ],
        "count": 2,
        "err_code": "OK"
    }';

    /**
     * Chapter 9.4.2 hands back under "f" the key the request gave the recipient, chapter 9.2, so
     * two messages written to the same number in a row are told apart on their receipts.
     */
    private const string RESPONSE_IDENTIFIED = '[
        {
            "rcpt": 421903622237,
            "i": "be863034-df6b-5e3f-b681-649bf7017b9e",
            "sgmnt": 1,
            "dlr": "DELIVRD",
            "f": "21C334C7-CD7E-45D7-AA88-FC9C58A7FF47"
        },
        {
            "rcpt": 421903622237,
            "i": "510b310a-ea3e-11e8-b780-87afcd091a1a",
            "sgmnt": 1,
            "dlr": "DELIVRD",
            "f": "F0A2E9B1-6D44-4C7A-9E31-2B8C5D0A7E63"
        }
    ]';

    /**
     * A long message is split into segments and every one of them is reported on its own,
     * chapter 9.4.1, so the segment the report speaks about is part of the answer.
     */
    private const string RESPONSE_SEGMENTED = '[
        { "i": "be863034-df6b-5e3f-b681-649bf7017b9e", "sgmnt": 1, "dlr": "DELIVRD" },
        { "i": "510b310a-ea3e-11e8-b780-87afcd091a1a", "sgmnt": 2, "dlr": "DELIVRD" },
        { "i": "20cd7672-176c-47b8-8440-7ea3b11b2c57", "sgmnt": 3, "dlr": "ENROUTE" }
    ]';

    public function testTheBareListOfOneMessageIsReadAsItsReports(): void
    {
        $response = $this->response(self::RESPONSE_ONE);

        self::assertCount(1, $response);
        self::assertFalse($response->isEmpty());
        self::assertNull($response->getCount());
    }

    public function testEveryDocumentedFieldOfAReportIsReadBack(): void
    {
        $report = $this->response(self::RESPONSE_ONE)->getReports()[0];

        self::assertSame(421903170552, $report->getRecipient());
        self::assertSame('231.6', $report->getCarrier());
        self::assertSame(0.0265, $report->getPrice());
        self::assertSame('2017-11-23 08:05:25', $report->getDeliveryTime()?->format('Y-m-d H:i:s'));
        self::assertSame(2, $report->getSegment());
        self::assertSame('DELIVRD', $report->getStatus());
        self::assertSame('be863034-df6b-5e3f-b681-649bf7017b9e', $report->getUuid());
        self::assertSame('2017-11-23 08:05:21', $report->getSendTime()?->format('Y-m-d H:i:s'));
        self::assertSame('OK', $report->getErrorCode());
        self::assertNull($report->getIdentifier());
        self::assertTrue($report->isDelivered());
    }

    /**
     * The key travels with the message and comes back on its receipt, which is the only thing
     * that tells two reports of one number apart: the same number written to twice in a row —
     * an order confirmation and the verification code behind it — is reported twice.
     */
    public function testTheKeyTheRequestGaveTheRecipientComesBackOnTheReport(): void
    {
        $reports = $this->response(self::RESPONSE_IDENTIFIED)->getReports();

        self::assertSame(
            [421903622237, 421903622237],
            array_map(static fn (DeliveryReport $report): ?int => $report->getRecipient(), $reports)
        );
        self::assertSame(
            ['21C334C7-CD7E-45D7-AA88-FC9C58A7FF47', 'F0A2E9B1-6D44-4C7A-9E31-2B8C5D0A7E63'],
            array_map(static fn (DeliveryReport $report): ?string => $report->getIdentifier(), $reports)
        );
    }

    /**
     * A recipient given no key sends none, so a key that came back empty is read as none as well
     * — otherwise a report and the recipient it belongs to would never match on their two keys.
     */
    public function testAKeyThatCameBackEmptyIsReadAsNone(): void
    {
        $report = $this->response('[{"i": "be863034-df6b-5e3f-b681-649bf7017b9e", "dlr": "DELIVRD", "f": ""}]')->getReports()[0];

        self::assertNull($report->getIdentifier());
    }

    /**
     * getFlag() named the field rather than what it carries and is kept only for what already
     * reads it; it answers with the key itself.
     */
    public function testTheFormerNameOfTheKeyStillAnswersWithIt(): void
    {
        $report = $this->response(self::RESPONSE_IDENTIFIED)->getReports()[0];

        self::assertSame($report->getIdentifier(), $report->getFlag());
    }

    /**
     * Chapter 9.4.5: the group and the bulk operation answer with an envelope, and the reports
     * inside it are the same ones a single status carries.
     */
    public function testTheEnvelopeOfAGroupIsReadIntoTheSameReports(): void
    {
        $response = $this->response(self::RESPONSE_GROUP);

        self::assertCount(2, $response);
        self::assertSame(2, $response->getCount());
        self::assertSame('OK', $response->getErrorCode());
        self::assertSame(
            [421902589458, 421915837327],
            array_map(static fn (DeliveryReport $report): ?int => $report->getRecipient(), $response->getReports())
        );
    }

    public function testAReportOfAGroupIsNotDeliveredUntilItSaysSo(): void
    {
        $report = $this->response(self::RESPONSE_GROUP)->getReports()[0];

        self::assertSame('EXPIRED', $report->getStatus());
        self::assertFalse($report->isDelivered());
        self::assertNull($report->getErrorCode());
    }

    public function testEverySegmentOfAMessageIsReportedOnItsOwn(): void
    {
        $reports = $this->response(self::RESPONSE_SEGMENTED)->getReports();

        self::assertCount(3, $reports);
        self::assertSame(
            [1, 2, 3],
            array_map(static fn (DeliveryReport $report): ?int => $report->getSegment(), $reports)
        );
    }

    public function testTheReportsAreIteratedOverDirectly(): void
    {
        $statuses = [];

        foreach ($this->response(self::RESPONSE_SEGMENTED) as $report) {
            $statuses[] = $report->getStatus();
        }

        self::assertSame(['DELIVRD', 'DELIVRD', 'ENROUTE'], $statuses);
    }

    /**
     * An identifier the gateway knows nothing about is not a failure, chapter 9.4.1: it is
     * answered with nothing to report.
     */
    public function testAnUnknownIdentifierIsAnsweredWithNoReportsAndNoError(): void
    {
        $response = $this->response('[]');

        self::assertTrue($response->isEmpty());
        self::assertCount(0, $response);
        self::assertSame([], $response->getReports());
    }

    /**
     * A group whose statuses have not changed since the last call is answered the same way,
     * chapters 9.4.3 and 9.4.4, only through the envelope.
     */
    public function testAGroupWithNothingChangedIsAnsweredWithAnEmptyEnvelope(): void
    {
        $response = $this->response('{"dlrs": [], "count": 0, "err_code": "OK"}');

        self::assertTrue($response->isEmpty());
        self::assertSame(0, $response->getCount());
        self::assertSame('OK', $response->getErrorCode());
    }

    public function testABodyThatIsNoAnswerAtAllIsReadAsNoReports(): void
    {
        $response = $this->response('');

        self::assertTrue($response->isEmpty());
        self::assertSame([], $response->getBody());
        self::assertNull($response->getErrorCode());
    }

    /**
     * Nothing about a body that came off the wire is taken on trust, so a field shaped
     * otherwise than documented is read as if it had not been stated.
     */
    public function testAFieldShapedOtherwiseThanDocumentedIsReadAsUnstated(): void
    {
        $body = '[{"rcpt": {}, "price": "nic", "dlr_time": "vcera", "dlr": ["DELIVRD"], "f": []}]';
        $report = $this->response($body)->getReports()[0];

        self::assertNull($report->getIdentifier());
        self::assertNull($report->getRecipient());
        self::assertNull($report->getPrice());
        self::assertNull($report->getDeliveryTime());
        self::assertNull($report->getStatus());
        self::assertNull($report->getDeliveryResult());
        self::assertFalse($report->isDelivered());
        self::assertFalse($report->isFinal());
    }

    /**
     * A time the format does not describe exactly is no time at all. Left to itself the parser
     * takes a thirteenth month and rolls it into the next year, so the report would carry a
     * moment the gateway never stated.
     */
    public function testATimeTheFormatDoesNotDescribeIsReadAsUnstated(): void
    {
        $body = '[{"rcpt": 421903170552, "dlr_time": "2026-13-45 99:99:99", "snd": "2017-11-23", "dlr": "DELIVRD"}]';
        $report = $this->response($body)->getReports()[0];

        self::assertNull($report->getDeliveryTime());
        self::assertNull($report->getSendTime());
    }

    /**
     * Chapter 13.2: the status is read into the case that stands for it, so a polling loop has
     * something to stop on without comparing strings of its own.
     */
    public function testTheStatusOfAReportIsReadIntoTheCaseThatStandsForIt(): void
    {
        $reports = $this->response(self::RESPONSE_SEGMENTED)->getReports();

        self::assertSame(DeliveryResultEnum::DELIVERED, $reports[0]->getDeliveryResult());
        self::assertTrue($reports[0]->isFinal());
        self::assertSame(DeliveryResultEnum::EN_ROUTE, $reports[2]->getDeliveryResult());
        self::assertFalse($reports[2]->isFinal());
    }

    /**
     * A status of no chapter is the error the operator left unnamed, and the string it came as
     * stays readable next to the case it was read into.
     */
    public function testAStatusOfNoChapterIsReadAsTheUnnamedErrorWithoutLosingIt(): void
    {
        $report = $this->response('[{"dlr": "SOMETHING_ELSE"}]')->getReports()[0];

        self::assertSame(DeliveryResultEnum::UNKNOWN, $report->getDeliveryResult());
        self::assertSame('SOMETHING_ELSE', $report->getStatus());
        self::assertTrue($report->isFinal());
        self::assertFalse($report->isDelivered());
    }

    public function testAnEntryThatIsNoObjectIsLeftOutOfTheReports(): void
    {
        self::assertCount(1, $this->response('["DELIVRD", {"dlr": "DELIVRD"}, 7]'));
    }

    public function testTheAnswerOfTheGatewayIsKeptAsItCame(): void
    {
        $response = $this->response(self::RESPONSE_GROUP);

        self::assertSame(200, $response->getClientResponse()->getStatusCode());
        self::assertArrayHasKey('dlrs', $response->getBody());
    }

    /**
     * @param string $body
     * @return StatusResponse
     */
    private function response(string $body): StatusResponse
    {
        return new StatusResponse(MockClientFactory::json($body));
    }
}
