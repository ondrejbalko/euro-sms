<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Result;

use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestOneToMany;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseOneToMany;
use EuroSms\Gateway\Result\ResultOneToMany;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The answers are the ones printed in chapters 9.3.5 to 9.3.7 of SMS API v3.1.15, where a group
 * send is reported through an "accepted" list and a "wrong_numbers" list next to it. The two are
 * not exclusive: chapter 9.3.6 enqueues the request and still names the numbers it refused.
 */
#[CoversClass(ResultOneToMany::class)]
final class ResultOneToManyTest extends TestCase
{
    private const string RESPONSE_ACCEPTED = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 2231232,
        "accepted": [
            { "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] },
            { "r": 421902121212, "i": [ "405B0C82-CF9A-4D95-80AB-41A75A6A5EE7" ] }
        ],
        "wrong_numbers": []
    }';

    private const string RESPONSE_PARTIAL = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 223123,
        "accepted": [
            { "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] }
        ],
        "wrong_numbers": [ { "r": 42190362 }, { "r": 429123423 } ]
    }';

    private const string RESPONSE_REFUSED = '{
        "err_code": "FAILED",
        "err_list": [ { "err_code": "NO_BALANCE", "err_desc": "Account has low balance to send message" } ],
        "accepted": [],
        "wrong_numbers": [ { "r": 42190362 } ]
    }';

    /**
     * Chapter 9.3.7 — every number got through, so there is nothing to report anywhere else.
     */
    public function testEveryAcceptedNumberCarriesTheIdentifiersOfItsSegments(): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED);

        self::assertSame([
            ['number' => 421903622237, 'uuid' => ['3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07'], 'viber' => []],
            ['number' => 421902121212, 'uuid' => ['405B0C82-CF9A-4D95-80AB-41A75A6A5EE7'], 'viber' => []],
        ], $this->entries($result->getSent()));
        self::assertSame([], $result->getDenied());
        self::assertSame([], $result->getFailed());
    }

    /**
     * Chapter 9.3.6 — the request went through and some of its numbers did not. The refused ones
     * are reported as denied: read only out of a refused answer they would be reported nowhere at
     * all, and a caller sending a hundred numbers of which thirty are wrong would see thirty of
     * them quietly disappear.
     */
    public function testTheNumbersRefusedByAnEnqueuedRequestAreStillReported(): void
    {
        $result = $this->parsedResult(self::RESPONSE_PARTIAL);

        self::assertSame([421903622237], array_column($this->entries($result->getSent()), 'number'));
        self::assertSame([42190362, 429123423], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([], $result->getFailed());
    }

    /**
     * Chapter 9.3.5 — the whole request was refused, so there is no verdict per number to read
     * and every number of it shares the error the answer named. The numbers used to be left out
     * of the failed list altogether, so a caller reconciling numbers to outcomes lost every
     * recipient of a refused request, while a single send and a transaction both reported them.
     */
    public function testARefusedRequestReportsItsErrorAgainstEveryNumberOfIt(): void
    {
        $result = $this->parsedResult(self::RESPONSE_REFUSED);

        self::assertSame([], $result->getSent());
        self::assertSame([42190362], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([
            ['number' => 421903622237, 'error' => ['NO_BALANCE' => 'Account has low balance to send message']],
            ['number' => 421902121212, 'error' => ['NO_BALANCE' => 'Account has low balance to send message']],
        ], $this->entries($result->getFailed()));
    }

    /**
     * A number the answer itself named as wrong is denied, and denied is the whole of what it is:
     * reporting it as failed as well would have the same number counted twice over.
     */
    public function testANumberTheAnswerRefusedIsNotAlsoReportedAsFailed(): void
    {
        $result = $this->parsedResult('{
            "err_code": "FAILED",
            "err_list": [ { "err_code": "NO_BALANCE", "err_desc": "Account has low balance to send message" } ],
            "accepted": [],
            "wrong_numbers": [ { "r": 421902121212 } ]
        }');

        self::assertSame([421902121212], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([421903622237], array_column($this->entries($result->getFailed()), 'number'));
    }

    /**
     * The mistake the one above is here for, in the shape it really turns up in. A number comes
     * off the wire as whatever JSON carried it, and nothing makes the gateway quote it the same
     * way twice: chapter 9.3.6 prints it unquoted, chapter 9.3.7 quoted. Told apart from the int
     * the request holds, a refused number was reported as denied and once more as failed, so a
     * caller reconciling numbers to outcomes counted the same number twice over.
     */
    public function testANumberTheAnswerRefusedAsAStringIsNotAlsoReportedAsFailed(): void
    {
        $result = $this->parsedResult('{
            "err_code": "FAILED",
            "err_list": [ { "err_code": "NO_BALANCE", "err_desc": "Account has low balance to send message" } ],
            "accepted": [],
            "wrong_numbers": [ { "r": "421902121212" } ]
        }');

        self::assertSame(['421902121212'], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([421903622237], array_column($this->entries($result->getFailed()), 'number'));
    }

    /**
     * Chapter 9.3.6 again, this time with the refusal stated as the code of the whole answer. The
     * request was enqueued for one of its numbers and refused for the other, so the accepted one
     * is sent and only the refused one is denied — reading the code alone said the request as a
     * whole was unsent and reported a number the gateway had enqueued as failed.
     */
    public function testARefusalCodeDoesNotUndoTheNumbersTheSameAnswerAccepted(): void
    {
        $result = $this->parsedResult('{
            "err_code": "WRONG_NUMBER",
            "group_id": 223123,
            "accepted": [
                { "r": 421903622237, "i": [ "3d7f0b97-3b26-4ace-a9c9-ee39bfc4cf07" ] }
            ],
            "wrong_numbers": [ { "r": 421902121212 } ]
        }');

        self::assertSame([421903622237], array_column($this->entries($result->getSent()), 'number'));
        self::assertSame([421902121212], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([], $result->getFailed());
    }

    /**
     * No answer came back at all, so nothing is known about any of the numbers and every one of
     * them is reported as denied.
     */
    public function testARequestThatWasNeverAnsweredDeniesEveryNumberOfIt(): void
    {
        $result = $this->parsedResult(null);

        self::assertSame([421903622237, 421902121212], array_column($this->entries($result->getDenied()), 'number'));
        self::assertSame([], $result->getSent());
        self::assertSame([], $result->getFailed());
    }

    /**
     * What was worth saying about the send without stopping it is read off the result, and both
     * ways of asking have to agree. hasWarnings() was missing from the result altogether while
     * the documentation offered it next to getWarnings(), so a caller asking the cheap question
     * first died on an undefined method instead of reading the answer.
     */
    public function testTheResultReportsTheWarningsOfTheRequestsItWasSplitInto(): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED, str_repeat('a', 613));

        self::assertCount(1, $result->getWarnings());
        self::assertTrue($result->hasWarnings());
    }

    /**
     * A send that was fine to make says nothing, so an empty list and a plain no are the answers.
     */
    public function testASendOfTextsThatFitSaysNothing(): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED);

        self::assertSame([], $result->getWarnings());
        self::assertFalse($result->hasWarnings());
    }

    /**
     * The lists are keyed by the id of the request, which is generated anew every time.
     * @param array<string, array<int, array<string, mixed>>> $list
     * @return array<int, array<string, mixed>>
     */
    private function entries(array $list): array
    {
        self::assertCount(1, $list);

        return reset($list);
    }

    /**
     * @param string|null $body the answer of the gateway, null when none came back at all
     * @param string $content the text the message was sent with
     * @return ResultOneToMany
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    private function parsedResult(?string $body, string $content = 'Testovacia sprava'): ResultOneToMany
    {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');

        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237');
        $recipients[] = new Recipient('+421902121212');

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent($content);
        $message->setRecipientCollection($recipients);

        $request = new RequestOneToMany($config);
        $request->setSenderName('RZi');
        $request->setRecipientCollection($recipients);
        $request->setContent($content, new Flag);

        $requestCollection = new RequestCollection;
        $requestCollection->offsetSet($request->getId(), $request);

        $responseCollection = new ResponseCollection;

        if (null !== $body) {
            $responseCollection->offsetSet(
                $request->getId(),
                new ResponseOneToMany($request->getId(), MockClientFactory::json($body))
            );
        }

        return new ResultOneToMany($message, $requestCollection, $responseCollection);
    }
}
