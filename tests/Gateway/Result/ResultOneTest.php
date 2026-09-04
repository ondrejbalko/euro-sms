<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Result;

use EuroSms\Config;
use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Exception\SendException;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseOne;
use EuroSms\Gateway\Result\ResultOne;
use EuroSms\Tests\Helpers\MockClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The answers are the ones printed in chapters 9.3.2 and 13.1 of SMS API v3.1.15, where a single
 * send is answered by a code and the identifiers of the segments the message fell into.
 *
 * What the three lists report a number as is what this is mostly about. The same key carried a
 * string here and an int in every other result, so a caller matching the entries against its own
 * records with a strict comparison matched for one kind of send and silently missed for the rest.
 */
#[CoversClass(ResultOne::class)]
final class ResultOneTest extends TestCase
{
    private const string RESPONSE_ACCEPTED = '{
        "uuid": [ "D0E17E57-F455-430E-844D-EAD6F88A18E4" ],
        "err_code": "ENQUEUED",
        "err_desc": "Message accepted and enqueued to send"
    }';

    private const string RESPONSE_REFUSED = '{
        "err_code": "WRONG_NUMBER",
        "err_desc": "Wrong recipient number"
    }';

    public function testAnAcceptedMessageCarriesTheIdentifiersOfItsSegments(): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED);

        self::assertSame([
            [
                'number' => 421903622237,
                'uuid' => ['D0E17E57-F455-430E-844D-EAD6F88A18E4'],
                'viber' => [],
            ],
        ], $this->entries($result->getSent()));
        self::assertSame([], $result->getFailed());
        self::assertSame([], $result->getDenied());
    }

    public function testARefusedMessageCarriesTheReasonTheAnswerNamed(): void
    {
        $result = $this->parsedResult(self::RESPONSE_REFUSED);

        self::assertSame([
            [
                'number' => 421903622237,
                'error' => ['WRONG_NUMBER' => 'Wrong recipient number'],
            ],
        ], $this->entries($result->getFailed()));
        self::assertSame([], $result->getSent());
    }

    public function testAMessageThatWasNeverAnsweredIsDenied(): void
    {
        $result = $this->parsedResult(null);

        self::assertSame([
            ['number' => 421903622237, 'reason' => null],
        ], $this->entries($result->getDenied()));
        self::assertSame([], $result->getSent());
        self::assertSame([], $result->getFailed());
    }

    /**
     * A batch that never reached the gateway carries the reason it did not, so a denied number is
     * something the caller can act on rather than guess at.
     */
    public function testADeniedMessageCarriesWhyTheCallDidNotGoThrough(): void
    {
        $result = $this->parsedResult(null, 'Connection timed out');

        self::assertSame([
            ['number' => 421903622237, 'reason' => 'Connection timed out'],
        ], $this->entries($result->getDenied()));
    }

    /**
     * The mistake this is here for. The number used to be handed back as the caller wrote it, so
     * the same recipient came back as "0903 622 237" from a single send and as 421903622237 from a
     * one-to-many or many-to-many send of the very same number.
     * @param string $number the number as the caller wrote it
     */
    #[DataProvider('provideTheSameNumberWrittenSeveralWays')]
    public function testTheNumberIsReportedAsItsDigitsHoweverItWasWritten(string $number): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED, number: $number);

        self::assertSame([421903622237], array_column($this->entries($result->getSent()), 'number'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTheSameNumberWrittenSeveralWays(): array
    {
        return [
            'international' => ['+421903622237'],
            'local, with the spaces it is printed with' => ['0903 622 237'],
            'local' => ['0903622237']
        ];
    }

    /**
     * All three lists say it the same way. A number that was reported as digits when it got
     * through and as something else when it did not would be no better than the mismatch above.
     */
    public function testEveryListReportsTheNumberTheSameWay(): void
    {
        $numbers = [
            ...array_column($this->entries($this->parsedResult(self::RESPONSE_ACCEPTED)->getSent()), 'number'),
            ...array_column($this->entries($this->parsedResult(self::RESPONSE_REFUSED)->getFailed()), 'number'),
            ...array_column($this->entries($this->parsedResult(null)->getDenied()), 'number')
        ];

        self::assertSame([421903622237, 421903622237, 421903622237], $numbers);
    }

    /**
     * The number as the caller wrote it is not lost by any of this: it stays readable where it was
     * written down, on the recipient itself.
     */
    public function testTheNumberAsItWasWrittenStaysReadableOnTheRecipient(): void
    {
        $result = $this->parsedResult(self::RESPONSE_ACCEPTED, number: '0903 622 237');

        self::assertSame('0903 622 237', $result->getMessage()->getRecipient()->getNumberOrig());
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
     * @param string|null $reason why the call did not go through, when none came back at all
     * @param string $number the number as the caller wrote it
     * @return ResultOne
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    private function parsedResult(
        ?string $body,
        ?string $reason = null,
        string $number = '+421903622237'
    ): ResultOne {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');

        $recipient = new Recipient($number);

        $message = new Message;
        $message->setSenderName('RZi');
        $message->setContent('Testovacia sprava');
        $message->setRecipient($recipient);

        $request = new RequestOne($config);
        $request->setSenderName('RZi');
        $request->setRecipient($recipient);
        $request->setContent('Testovacia sprava', new Flag);

        $requestCollection = new RequestCollection;
        $requestCollection->offsetSet($request->getId(), $request);

        $responseCollection = new ResponseCollection;

        if (null !== $body) {
            $responseCollection->offsetSet(
                $request->getId(),
                new ResponseOne($request->getId(), MockClientFactory::json($body))
            );
        }

        if (null !== $reason) {
            $responseCollection->addFailure($request->getId(), new SendException($reason));
        }

        return new ResultOne($message, $requestCollection, $responseCollection);
    }
}
