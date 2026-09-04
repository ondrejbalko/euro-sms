<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Response;

use EuroSms\Gateway\Response\ResponseManyToMany;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The response bodies are the ones printed in chapters 9.3.10 (basic) and 9.3.11 (full)
 * of SMS API v3.1.15.
 */
#[CoversClass(ResponseManyToMany::class)]
final class ResponseManyToManyTest extends TestCase
{
    private const string REQUEST_ID = '2c1c8f2f-0f6a-4d3a-9f11-77c1b0d5a9e2';

    /**
     * Chapter 9.3.10.
     */
    public function testTheBasicAnswerCarriesTheTallyAndTheGroup(): void
    {
        $response = $this->response('{"accepted": 23, "rejected": 2, "group_id": 1232323}');

        self::assertTrue($response->isSent());
        self::assertSame([], $response->getErrors());
        self::assertSame(1232323, $response->getGroupId());
        self::assertSame(23, $response->getBody()['accepted']);
        self::assertSame(2, $response->getBody()['rejected']);
    }

    /**
     * Chapter 9.3.11 — the full answer reports every message on its own, accepted or not.
     */
    public function testTheFullAnswerReportsEveryMessageSeparately(): void
    {
        $response = $this->fullResponse();

        self::assertTrue($response->isSent());
        self::assertSame(3344123, $response->getGroupId());
        self::assertSame([], $response->getErrors());
        self::assertCount(4, $response->getBody()['result']);
    }

    public function testARefusedNumberIsReportedWithItsOwnErrorCode(): void
    {
        $result = $this->fullResponse()->getBody()['result'];

        self::assertSame('WRONG_NUMBER', $result[0]['e']);
        self::assertSame(42190362, $result[0]['r']);
        self::assertArrayNotHasKey('i', $result[0]);
    }

    /**
     * Chapter 9.3.11: a long message is split into segments and each segment gets its own
     * identifier, so "i" is an array even for a single recipient.
     */
    public function testASegmentedMessageCarriesAnIdentifierPerSegment(): void
    {
        $result = $this->fullResponse()->getBody()['result'];

        self::assertSame('ENQUEUED', $result[3]['e']);
        self::assertSame([
            '20CD7672-176C-47B8-8440-7EA3B11B2C57',
            '510b310a-ea3e-11e8-b780-87afcd091a1a',
        ], $result[3]['i']);
    }

    /**
     * A tally in which nothing was accepted is not a send that went through.
     */
    public function testABasicAnswerThatAcceptedNothingIsNotSent(): void
    {
        self::assertFalse($this->response('{"accepted": 0, "rejected": 25}')->isSent());
    }

    /**
     * @return ResponseManyToMany
     */
    private function fullResponse(): ResponseManyToMany
    {
        return $this->response('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 3344123,
            "result": [
                { "e": "WRONG_NUMBER", "r": 42190362 },
                { "e": "WRONG_SIGNATURE", "r": 429123423 },
                { "e": "ENQUEUED", "r": 421903622237, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] },
                {
                    "e": "ENQUEUED",
                    "r": 420773773237,
                    "i": [ "20CD7672-176C-47B8-8440-7EA3B11B2C57", "510b310a-ea3e-11e8-b780-87afcd091a1a" ]
                }
            ]
        }');
    }

    /**
     * @param string $body
     * @return ResponseManyToMany
     */
    private function response(string $body): ResponseManyToMany
    {
        return new ResponseManyToMany(self::REQUEST_ID, MockClientFactory::json($body));
    }
}
