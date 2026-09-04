<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Response;

use EuroSms\Gateway\Response\ResponseOneToMany;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The response bodies are the ones printed in chapters 9.3.4.1 (basic) and 9.3.5 to 9.3.7 (full)
 * of SMS API v3.1.15. Chapter 9.3.7 spells the accepted code "ENQUEUD" — that is a typo in the
 * document; chapters 9.3.6, 9.3.11 and 13.1 all agree on "ENQUEUED", which is what is used here.
 */
#[CoversClass(ResponseOneToMany::class)]
final class ResponseOneToManyTest extends TestCase
{
    private const string REQUEST_ID = '9fbe1e6a-1f7c-4c5f-8a0d-5b6d1f2a3c44';

    /**
     * Chapter 9.3.4.1 — the basic answer is a tally, nothing else.
     */
    public function testTheBasicAnswerCarriesTheTallyAndTheGroup(): void
    {
        $response = $this->response('{"accepted": 23, "rejected": 2, "group_id": 223123}');

        self::assertTrue($response->isSent());
        self::assertSame([], $response->getErrors());
        self::assertSame(223123, $response->getGroupId());
        self::assertSame(23, $response->getBody()['accepted']);
        self::assertSame(2, $response->getBody()['rejected']);
    }

    /**
     * Chapter 9.3.5 — the whole request is refused, so nothing was accepted.
     */
    public function testACompletelyRejectedRequestIsNotSent(): void
    {
        $response = $this->response('{
            "err_code": "FAILED",
            "err_list": [
                { "err_code": "NO_BALANCE", "err_desc": "Account has low balance to send message" },
                { "err_code": "WRONG_SIGNATURE", "err_desc": "Signature does not match" }
            ],
            "wrong_numbers": [ { "r": 42190362 }, { "r": 429123423 } ],
            "accepted": []
        }');

        self::assertFalse($response->isSent());
        self::assertNull($response->getGroupId());
        self::assertSame([
            'NO_BALANCE' => 'Account has low balance to send message',
            'WRONG_SIGNATURE' => 'Signature does not match',
        ], $response->getErrors());
    }

    /**
     * Chapter 9.3.6 — the request went through, some of the numbers did not.
     */
    public function testAPartiallyRejectedRequestIsStillSent(): void
    {
        $response = $this->response('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 223123,
            "wrong_numbers": [ { "r": 42190362 }, { "r": 429123423 } ],
            "accepted": [
                { "r": 421903622237, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] }
            ]
        }');

        self::assertTrue($response->isSent());
        self::assertSame(223123, $response->getGroupId());
        self::assertSame([], $response->getErrors());
        self::assertCount(2, $response->getBody()['wrong_numbers']);
        self::assertCount(1, $response->getBody()['accepted']);
    }

    /**
     * Chapter 9.3.7 — every number was accepted.
     */
    public function testACompletelyAcceptedRequestListsAnIdentifierForEveryNumber(): void
    {
        $response = $this->response('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 2231232,
            "accepted": [
                { "r": "421903622237", "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] },
                { "r": "902121212", "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] }
            ],
            "wrong_numbers": []
        }');

        self::assertTrue($response->isSent());
        self::assertSame(2231232, $response->getGroupId());
        self::assertSame([], $response->getBody()['wrong_numbers']);
        self::assertSame(['3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07'], $response->getBody()['accepted'][0]['i']);
    }

    public function testAGroupIdThatIsNotANumberIsIgnored(): void
    {
        $response = $this->response('{"err_code": "ENQUEUED", "group_id": "not a number"}');

        self::assertNull($response->getGroupId());
    }

    /**
     * The mistake this is here for. Chapter 9.3.6 answers a request the gateway enqueued and still
     * names the numbers it refused, so a code that is a refusal and an "accepted" list that is not
     * empty stand side by side in one answer. The code was read and the tally was not, so the
     * whole request came back as unsent, and the numbers the gateway had enqueued — and will
     * charge for — were reported to the caller as failed.
     */
    public function testAnAnswerThatRefusesAndAcceptsAtOnceIsSent(): void
    {
        $response = $this->response('{
            "err_code": "WRONG_NUMBER",
            "group_id": 223123,
            "accepted": [
                { "r": 421903622237, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] }
            ],
            "wrong_numbers": [ { "r": 421900333444 } ]
        }');

        self::assertTrue($response->isSent());
        self::assertSame(223123, $response->getGroupId());
    }

    /**
     * Partly accepted is not a reason to lose the refusal. The code the answer carries is the only
     * thing naming why the rest of it did not go out, and it is stated whether or not anything
     * else did.
     */
    public function testAnAnswerThatRefusesAndAcceptsAtOnceStillNamesTheRefusal(): void
    {
        $response = $this->response('{
            "err_code": "WRONG_NUMBER",
            "err_desc": "Wrong recipient number",
            "accepted": [ { "r": 421903622237, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] } ],
            "wrong_numbers": [ { "r": 421900333444 } ]
        }');

        self::assertTrue($response->isSent());
        self::assertSame(['WRONG_NUMBER' => 'Wrong recipient number'], $response->getErrors());
    }

    /**
     * An accepted code is the acceptance itself, chapter 13.1, and never an error to report.
     */
    public function testAnEnqueuedCodeIsNoError(): void
    {
        $response = $this->response('{"err_code": "ENQUEUED", "accepted": [ { "r": 421903622237 } ]}');

        self::assertTrue($response->isSent());
        self::assertSame([], $response->getErrors());
    }

    /**
     * A tally of nothing next to a refusal is a refusal. Reading the two together may not turn a
     * send that got nowhere into one that got through.
     */
    public function testARefusalWithAnEmptyTallyIsStillUnsent(): void
    {
        $response = $this->response('{"err_code": "NO_BALANCE", "accepted": 0, "rejected": 23}');

        self::assertFalse($response->isSent());
        self::assertArrayHasKey('NO_BALANCE', $response->getErrors());
    }

    /**
     * @param string $body
     * @return ResponseOneToMany
     */
    private function response(string $body): ResponseOneToMany
    {
        return new ResponseOneToMany(self::REQUEST_ID, MockClientFactory::json($body));
    }
}
