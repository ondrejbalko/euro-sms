<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Result;

use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestManyToMany;
use EuroSms\Gateway\Request\RequestOneToMany;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseManyToMany;
use EuroSms\Gateway\Result\ResultManyToMany;
use EuroSms\Gateway\Result\ResultMessageAbstract;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Gateway\Result\ResultOneToMany;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The answers are the ones printed in chapter 9.3.11 of SMS API v3.1.15, where the gateway
 * reports every number of the transaction on its own.
 */
#[CoversClass(ResultManyToMany::class)]
final class ResultManyToManyTest extends TestCase
{
    private const string RESPONSE_FULL = '{
        "err_code": "ENQUEUED",
        "err_list": [],
        "group_id": 3344123,
        "result": [
            {
                "e": "ENQUEUED",
                "r": 421903622237,
                "i": [ "20CD7672-176C-47B8-8440-7EA3B11B2C57", "510b310a-ea3e-11e8-b780-87afcd091a1a" ]
            },
            { "e": "WRONG_NUMBER", "r": 421902121212 }
        ]
    }';

    private const string RESPONSE_REFUSED = '{
        "err_code": "WRONG_SIGNATURE",
        "err_list": [ { "err_code": "WRONG_SIGNATURE", "err_desc": "Wrong signature" } ]
    }';

    public function testAnEnqueuedNumberCarriesItsSegmentsAndTheGroupItWasFiledUnder(): void
    {
        $sent = $this->entries($this->parsedResult(self::RESPONSE_FULL)->getSent());

        self::assertSame([
            [
                'number' => 421903622237,
                'uuid' => [
                    '20CD7672-176C-47B8-8440-7EA3B11B2C57',
                    '510b310a-ea3e-11e8-b780-87afcd091a1a',
                ],
                'viber' => [],
                'group_id' => 3344123,
            ],
        ], $sent);
    }

    /**
     * Chapter 9.3.11: the identifier of a segment that went out over Viber carries the "v:"
     * prefix. Every identifier stays in the list it came in, and the Viber ones are named again
     * on their own so that they can be told apart from the SMS ones.
     */
    public function testTheSegmentsThatWentOutOverViberAreTellableFromTheSmsOnes(): void
    {
        $sent = $this->entries($this->parsedResult('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 3344123,
            "result": [
                {
                    "e": "ENQUEUED",
                    "r": 421903622237,
                    "i": [ "v:20CD7672-176C-47B8-8440-7EA3B11B2C57", "510b310a-ea3e-11e8-b780-87afcd091a1a" ]
                }
            ]
        }')->getSent());

        self::assertSame([
            'v:20CD7672-176C-47B8-8440-7EA3B11B2C57',
            '510b310a-ea3e-11e8-b780-87afcd091a1a',
        ], $sent[0]['uuid']);
        self::assertSame(['v:20CD7672-176C-47B8-8440-7EA3B11B2C57'], $sent[0]['viber']);
    }

    public function testAnIdentifierIsReadAsAViberOneOnlyWithThePrefix(): void
    {
        self::assertTrue(ResultManyToMany::isViberIdentifier('v:20CD7672-176C-47B8-8440-7EA3B11B2C57'));
        self::assertFalse(ResultManyToMany::isViberIdentifier('20CD7672-176C-47B8-8440-7EA3B11B2C57'));
    }

    /**
     * A refused number reports its error the same way a refused transaction does, as the code
     * mapped to its description, so that both lists can be read alike. Nothing described the
     * code here, so it stands for its own description.
     */
    public function testARefusedNumberCarriesTheCodeThatRefusedIt(): void
    {
        $result = $this->parsedResult(self::RESPONSE_FULL);

        self::assertSame([
            [
                'number' => 421902121212,
                'error' => ['WRONG_NUMBER' => 'WRONG_NUMBER'],
                'group_id' => 3344123,
            ],
        ], $this->entries($result->getFailed()));
        self::assertSame([], $result->getDenied());
    }

    /**
     * The description the answer listed for the code is the one a refused number is given.
     */
    public function testARefusedNumberIsGivenTheDescriptionTheAnswerListedForItsCode(): void
    {
        $result = $this->parsedResult('{
            "err_code": "ENQUEUED",
            "err_list": [ { "err_code": "WRONG_NUMBER", "err_desc": "Number is not valid" } ],
            "group_id": 3344123,
            "result": [ { "e": "WRONG_NUMBER", "r": 421902121212 } ]
        }');

        self::assertSame(
            ['WRONG_NUMBER' => 'Number is not valid'],
            $this->entries($result->getFailed())[0]['error']
        );
    }

    /**
     * An answer whose verdicts are not shaped the way chapter 9.3.11 shapes them carries no
     * verdict at all, so the transaction is read as refused rather than silently mis-sorted.
     */
    public function testAnAnswerWithoutReadableVerdictsIsTreatedAsARefusedTransaction(): void
    {
        $result = $this->parsedResult('{"err_code": "FAILED", "err_list": [], "result": 5}');

        self::assertSame([421903622237, 421902121212], array_column($this->entries($result->getFailed()), 'number'));
        self::assertSame([], $result->getSent());
    }

    /**
     * A verdict that names no code is not an accepted one, so the number it names fails.
     */
    public function testAVerdictWithoutACodeFailsTheNumberItNames(): void
    {
        $result = $this->parsedResult('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 3344123,
            "result": [ { "r": 421902121212 }, "nonsense" ]
        }');

        self::assertSame([
            [
                'number' => 421902121212,
                'error' => [],
                'group_id' => 3344123,
            ],
        ], $this->entries($result->getFailed()));
    }

    /**
     * A transaction the gateway threw away as a whole reports no number of its own, so every
     * number it carried fails with the error the answer came with.
     */
    public function testARefusedTransactionFailsEveryNumberItCarried(): void
    {
        $result = $this->parsedResult(self::RESPONSE_REFUSED);

        self::assertSame([
            [
                'number' => 421903622237,
                'error' => ['WRONG_SIGNATURE' => 'Wrong signature'],
                'group_id' => null,
            ],
            [
                'number' => 421902121212,
                'error' => ['WRONG_SIGNATURE' => 'Wrong signature'],
                'group_id' => null,
            ],
        ], $this->entries($result->getFailed()));
        self::assertSame([], $result->getSent());
    }

    /**
     * The mistake this is here for. A transaction the gateway enqueued and answered the tally of,
     * chapters 9.3.4.1 and 9.3.10, carries no verdict per number at all. The missing list was read
     * as a refusal, so every recipient of a send that got through was reported as failed — and
     * with an empty error beside it, because an accepted answer names none. What the gateway did
     * not speak of is left unspoken rather than reported as its opposite.
     */
    public function testAnAcceptedTransactionWithNoListPerNumberFailsNothing(): void
    {
        $result = $this->parsedResult('{
            "err_code": "ENQUEUED",
            "err_list": [],
            "group_id": 3344123,
            "accepted": 2
        }');

        self::assertSame([], $result->getFailed());
        self::assertSame([], $result->getSent());
        self::assertSame([], $result->getDenied());
        self::assertSame([3344123], $result->getGroupIds());
    }

    /**
     * The basic answer of chapter 9.3.4.1 says the same thing without a code at all.
     */
    public function testAnAcceptedTransactionAnsweredWithNothingButATallyFailsNothing(): void
    {
        $result = $this->parsedResult('{"accepted": 2, "rejected": 0, "group_id": 3344123}');

        self::assertSame([], $result->getFailed());
        self::assertSame([3344123], $result->getGroupIds());
    }

    public function testARequestThatNeverGotAnAnswerLeavesEveryNumberDenied(): void
    {
        $result = $this->parsedResult(null);

        self::assertSame([
            ['number' => 421903622237, 'reason' => null],
            ['number' => 421902121212, 'reason' => null],
        ], $this->entries($result->getDenied()));
        self::assertSame([], $result->getSent());
        self::assertSame([], $result->getFailed());
    }

    public function testTheCollectionThatWasSentIsHandedBack(): void
    {
        $result = $this->parsedResult(self::RESPONSE_FULL);

        self::assertCount(2, $result->getMessageCollection());
        self::assertSame(
            ['Prva sprava', 'Druha sprava'],
            array_values(array_map(static fn (Message $message): ?string => $message->getContent(), $result->getMessageCollection()->all()))
        );
    }

    public function testTheGroupOfTheTransactionIsReadableFromTheAnswer(): void
    {
        $responses = $this->parsedResult(self::RESPONSE_FULL)->getResponseCollection()->all();

        self::assertSame(3344123, reset($responses)->getGroupId());
    }

    /**
     * The group the transaction was filed under is what the delivery status of the whole send
     * is asked by, chapter 9.4.4, so the result hands it over as a value of its own and not
     * only as a field repeated on every entry.
     */
    public function testTheGroupOfTheSendIsHandedOverForTheDeliveryStatusToBeAskedBy(): void
    {
        self::assertSame([3344123], $this->parsedResult(self::RESPONSE_FULL)->getGroupIds());
    }

    /**
     * One group is one group however many entries repeat it.
     */
    public function testAGroupIsHandedOverOnlyOnce(): void
    {
        self::assertCount(1, $this->parsedResult(self::RESPONSE_FULL)->getGroupIds());
    }

    public function testARefusedTransactionBelongsToNoGroup(): void
    {
        self::assertSame([], $this->parsedResult(self::RESPONSE_REFUSED)->getGroupIds());
    }

    public function testATransactionThatNeverGotAnAnswerBelongsToNoGroup(): void
    {
        self::assertSame([], $this->parsedResult(null)->getGroupIds());
    }

    /**
     * A result over a whole collection of messages is no kind of result over a single one: what
     * it holds is the collection, so no method of it can reach for a message it was never given.
     * Only the two results that really do stand for one message descend from the class that
     * carries one.
     * @return void
     */
    public function testAResultOverACollectionCarriesNoSingleMessage(): void
    {
        self::assertFalse(method_exists(ResultManyToMany::class, 'getMessage'));
        self::assertFalse(is_subclass_of(ResultManyToMany::class, ResultMessageAbstract::class));
        self::assertTrue(is_subclass_of(ResultOne::class, ResultMessageAbstract::class));
        self::assertTrue(is_subclass_of(ResultOneToMany::class, ResultMessageAbstract::class));
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
     * @return ResultManyToMany
     * @throws RecipientException
     * @throws RequestException
     */
    private function parsedResult(?string $body): ResultManyToMany
    {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');

        $messageCollection = new MessageCollection;
        $request = new RequestManyToMany($config);

        foreach (['Prva sprava' => 421903622237, 'Druha sprava' => 421902121212] as $content => $number) {
            $recipients = new RecipientCollection;
            $recipients[] = new Recipient('+' . $number);

            $message = new Message;
            $message->setSenderName('RZi');
            $message->setContent($content);
            $message->setRecipientCollection($recipients);
            $messageCollection->offsetSet($message->getId(), $message);

            $one = new RequestOneToMany($config);
            $one->setSenderName('RZi');
            $one->setRecipientCollection($recipients);
            $one->setContent($content, new Flag);
            $request->addRequest($one);
        }

        $requestCollection = new RequestCollection;
        $requestCollection->offsetSet($request->getId(), $request);

        $responseCollection = new ResponseCollection;

        if (null !== $body) {
            $responseCollection->offsetSet(
                $request->getId(),
                new ResponseManyToMany($request->getId(), MockClientFactory::json($body))
            );
        }

        return new ResultManyToMany($messageCollection, $requestCollection, $responseCollection);
    }
}
