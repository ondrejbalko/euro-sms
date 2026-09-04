<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Response;

use EuroSms\Enums\SendStatusEnum;
use EuroSms\Gateway\Response\ResponseOne;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The response bodies are the ones printed in chapter 9.3.2 of SMS API v3.1.15.
 */
#[CoversClass(ResponseOne::class)]
final class ResponseOneTest extends TestCase
{
    private const string REQUEST_ID = 'c9d7b0d4-3b2e-4b8a-9f0e-2f4a1c6d8e30';

    public function testAnAcceptedMessageIsReportedAsSent(): void
    {
        $response = $this->response('{
            "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
            "err_code": "ENQUEUED",
            "err_desc": "Message accepted and enqueued to send"
        }');

        self::assertTrue($response->isSent());
        self::assertSame(SendStatusEnum::ENQUEUED, $response->getSendStatus());
        self::assertSame([], $response->getErrors());
        self::assertSame(['D0E17E57-F455-430E-844D-EAD6F88A18E4'], $response->getBody()['uuid']);
    }

    /**
     * Chapter 13.1: the code the whole request was answered with is read into the case that
     * stands for it, and a code the chapter does not list is the refusal the gateway did not
     * name. The code as it came stays readable in the body next to it, so nothing is lost.
     */
    public function testTheCodeOfTheAnswerIsReadIntoTheCaseThatStandsForIt(): void
    {
        $refused = $this->response('{"err_code": "NO_BALANCE", "err_list": []}');
        $unlisted = $this->response('{"err_code": "FAILED", "err_list": []}');

        self::assertSame(SendStatusEnum::NO_BALANCE, $refused->getSendStatus());
        self::assertSame('The account has no credit left', $refused->getSendStatus()?->getDescription());
        self::assertSame(SendStatusEnum::ERR_OTHER, $unlisted->getSendStatus());
        self::assertSame('FAILED', $unlisted->getBody()['err_code']);
    }

    /**
     * Chapter 9.3.2: a single send states its refusal in the answer itself and lists nothing
     * beside it — "err_code" with "err_desc" next to it and no "err_list" at all. Reading only
     * the list left the caller with a message reported as failed and no reason for it, while the
     * reason sat in the body unread.
     */
    public function testARefusalThatListsNothingIsStillReportedWithItsReason(): void
    {
        $response = $this->response('{
            "err_code": "NO_BALANCE",
            "err_desc": "Not enough credit to send the message"
        }');

        self::assertFalse($response->isSent());
        self::assertSame(['NO_BALANCE' => 'Not enough credit to send the message'], $response->getErrors());
    }

    /**
     * A refusal that names no description of its own is still worth a reason, so the one chapter
     * 13.1 gives the code stands in for it.
     */
    public function testARefusalWithoutADescriptionIsReportedWithTheOneOfItsCode(): void
    {
        self::assertSame(
            ['NO_BALANCE' => 'The account has no credit left'],
            $this->response('{"err_code": "NO_BALANCE"}')->getErrors()
        );
    }

    /**
     * An accepted message is no refusal, so the code it was accepted under is not an error and
     * is not reported as one.
     */
    public function testAnAcceptedMessageReportsNoErrorForTheCodeItWasAcceptedUnder(): void
    {
        self::assertSame([], $this->response('{
            "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
            "err_code": "ENQUEUED",
            "err_desc": "Message accepted and enqueued to send"
        }')->getErrors());
    }

    /**
     * An answer that states no code at all has no verdict of chapter 13.1 to report either.
     */
    public function testAnAnswerWithoutACodeStatesNoVerdict(): void
    {
        self::assertNull($this->response('')->getSendStatus());
    }

    /**
     * A single message never belongs to a group, so the gateway returns no group_id for it.
     */
    public function testASingleMessageHasNoGroup(): void
    {
        $response = $this->response('{"uuid": [], "err_code": "ENQUEUED"}');

        self::assertNull($response->getGroupId());
    }

    public function testEveryReportedErrorIsCollected(): void
    {
        $response = $this->response('{
            "err_code": "FAILED",
            "err_list": [
                { "err_code": "NO_BALANCE", "err_desc": "Account has low balance to send message." },
                { "err_code": "WRONG_SIGNATURE", "err_desc": "Signature does not match" }
            ]
        }');

        self::assertFalse($response->isSent());
        self::assertSame([
            'NO_BALANCE' => 'Account has low balance to send message.',
            'WRONG_SIGNATURE' => 'Signature does not match',
        ], $response->getErrors());
    }

    public function testARejectedRequestCarriesNoMessageIdentifiers(): void
    {
        $response = $this->response('{"err_code": "FAILED", "err_list": []}');

        self::assertArrayNotHasKey('uuid', $response->getBody());
    }

    public function testABodyWithoutAnErrorCodeIsTreatedAsNoAnswer(): void
    {
        $response = $this->response('');

        self::assertFalse($response->isSent());
        self::assertSame(['NO_RESPONSE' => 'Something went wrong, try again'], $response->getErrors());
    }

    public function testTheResponseKeepsTheIdOfTheRequestItAnswers(): void
    {
        self::assertSame(self::REQUEST_ID, $this->response('{"err_code": "ENQUEUED"}')->getRequestId());
    }

    /**
     * @param string $body
     * @return ResponseOne
     */
    private function response(string $body): ResponseOne
    {
        return new ResponseOne(self::REQUEST_ID, MockClientFactory::json($body));
    }
}
