<?php

declare(strict_types=1);

namespace EuroSms\Tests\Helpers;

use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Helpers\Percolator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How many messages one transaction may carry, chapter 9.3.8 of SMS API v3.1.15, and what
 * happens to a collection that carries more. What counts towards the limit is how many messages
 * the transaction sends and not how many entries it lists: one entry addressed to a thousand
 * numbers is already a thousand messages.
 */
#[CoversClass(Percolator::class)]
final class PercolatorTest extends TestCase
{
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string SENDER = 'RZi';
    private const string TEXT = 'Testovacia sprava';

    /**
     * A transaction with nothing in it used to be built, posted and answered with a tally of
     * nothing, and the caller was never told that the collection it handed over was empty. The
     * count is knowable here, so it is said here.
     */
    public function testAnEmptyCollectionIsRefused(): void
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionCode(MessageInterface::ERROR_MESSAGE_COLLECTION_IS_EMPTY);

        (void)$this->percolator()->createRequestManyToMany(new MessageCollection);
    }

    /**
     * Exactly as many messages as one transaction holds still travel as one transaction.
     */
    public function testACollectionOfExactlyTheLimitTravelsAsOneTransaction(): void
    {
        $requests = $this->percolator()->createRequestManyToMany(
            $this->collection(GatewayInterface::MAX_ITEMS_PER_REQUEST)
        );

        self::assertCount(1, $requests);
        self::assertSame(
            GatewayInterface::MAX_ITEMS_PER_REQUEST,
            $this->countRecipients($requests)
        );
    }

    /**
     * One message over the limit and the collection is split rather than refused: the caller
     * asked for a send that is perfectly allowed, it merely does not fit into one transaction.
     * Every message is still sent, once.
     */
    public function testACollectionOverTheLimitIsSplitAndLosesNothing(): void
    {
        $count = GatewayInterface::MAX_ITEMS_PER_REQUEST + 1;

        $requests = $this->percolator()->createRequestManyToMany($this->collection($count));

        self::assertCount(2, $requests);
        self::assertSame($count, $this->countRecipients($requests));
    }

    /**
     * The limit counts messages and not entries. Two messages of six hundred recipients each are
     * twelve hundred messages, so they cannot travel together however few entries they are.
     */
    public function testTheLimitCountsMessagesAndNotEntries(): void
    {
        $messageCollection = new MessageCollection;
        $messageCollection[] = $this->message(600, 0);
        $messageCollection[] = $this->message(600, 600);

        $requests = $this->percolator()->createRequestManyToMany($messageCollection);

        self::assertCount(2, $requests);
        self::assertSame(1200, $this->countRecipients($requests));
    }

    /**
     * The mistake this is here for. The key of chapter 9.2 exists precisely because one number is
     * written to more than once — an order confirmation and the verification code behind it — and
     * the gateway repeats it on the delivery report so that the two receipts can be told apart.
     * Deduplicating by the bare number threw the first of them away, so one of the two messages
     * was never sent and only the surviving key ever reported.
     */
    public function testTwoRecipientsOfOneNumberUnderTwoKeysAreBothKept(): void
    {
        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237', identifier: 'order-1');
        $recipients[] = new Recipient('+421903622237', identifier: 'code-1');

        $unique = $this->percolator()->getUniqueRecipients($recipients);

        self::assertCount(2, $unique);
        self::assertSame(
            ['order-1', 'code-1'],
            array_values(array_map(static fn (Recipient $one): ?string => $one->getIdentifier(), $unique->all()))
        );
    }

    /**
     * A recipient carrying no key is the number it always was, and two of those are still one.
     */
    public function testTheSameNumberWithoutAKeyIsStillOneRecipient(): void
    {
        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237');
        $recipients[] = new Recipient('0903 622 237');

        self::assertCount(1, $this->percolator()->getUniqueRecipients($recipients));
    }

    /**
     * The same number written twice under the same key is the same recipient, key or no key.
     */
    public function testTheSameNumberUnderTheSameKeyIsOneRecipient(): void
    {
        $recipients = new RecipientCollection;
        $recipients[] = new Recipient('+421903622237', identifier: 'order-1');
        $recipients[] = new Recipient('+421903622237', identifier: 'order-1');

        self::assertCount(1, $this->percolator()->getUniqueRecipients($recipients));
    }

    /**
     * The mistake this is here for. "dur" belongs to the root of one transaction, chapter 9.2.1,
     * and a collection too big for one transaction has a root for each of the transactions it is
     * split into. Every one of them carried the whole length and all of them went out at the same
     * time, so a thousand messages asked to leave over an hour left as two thousand over that
     * hour — twice the rate the caller was throttling to, and the very thing "dur" was set for.
     */
    public function testACollectionTooBigToBeSpreadOverTheLengthItAsksForIsRefused(): void
    {
        $messageCollection = $this->collection(GatewayInterface::MAX_ITEMS_PER_REQUEST + 1);
        $messageCollection->offsetGet(0)?->setDuration('01:00');

        $this->expectException(RequestException::class);
        $this->expectExceptionCode(RequestInterface::ERROR_DURATION_NOT_SPLITTABLE);

        (void)$this->percolator()->createRequestManyToMany($messageCollection);
    }

    /**
     * A collection that fits into one transaction is spread over the length it asked for, which is
     * the whole point of not refusing it wholesale.
     */
    public function testACollectionThatFitsKeepsTheLengthItIsSpreadOver(): void
    {
        $messageCollection = $this->collection(GatewayInterface::MAX_ITEMS_PER_REQUEST);
        $messageCollection->offsetGet(0)?->setDuration('01:00');

        $requests = $this->percolator()->createRequestManyToMany($messageCollection);

        self::assertCount(1, $requests);
        self::assertSame('01:00', $requests[0]->getDuration());
    }

    /**
     * Nothing about the splitting changes for a collection that asked to be spread over nothing.
     */
    public function testACollectionThatAsksForNoLengthIsStillSplit(): void
    {
        $requests = $this->percolator()->createRequestManyToMany(
            $this->collection(GatewayInterface::MAX_ITEMS_PER_REQUEST + 1)
        );

        self::assertCount(2, $requests);
        self::assertNull($requests[0]->getDuration());
    }

    /**
     * A transaction states the defaults of the messages it holds, chapter 9.3.8, and of no others.
     * The defaults used to be taken from the first message of the whole collection and written
     * into every transaction it was split into, so the second transaction named a Viber sender for
     * messages that never asked for one and compared its own messages against a sender name none
     * of them carries — which put "sndr" back on every message the root already speaks for.
     */
    public function testEachTransactionOfASplitCollectionCarriesItsOwnDefaults(): void
    {
        $messageCollection = new MessageCollection;
        $messageCollection[] = $this->message(GatewayInterface::MAX_ITEMS_PER_REQUEST, 0, 'Prva');
        $messageCollection[] = $this->message(GatewayInterface::MAX_ITEMS_PER_REQUEST, 1000, 'Druha');

        $requests = $this->percolator()->createRequestManyToMany($messageCollection);

        self::assertCount(2, $requests);
        self::assertSame('Prva', $requests[0]->getDefaultSenderName());
        self::assertSame('Druha', $requests[1]->getDefaultSenderName());
    }

    /**
     * How many messages a list of built transactions sends altogether.
     * @param array<int, \EuroSms\Gateway\Request\RequestManyToMany> $requests
     * @return int
     */
    private function countRecipients(array $requests): int
    {
        $count = 0;

        foreach ($requests as $request) {
            foreach ($request->getRequests() as $one) {
                $count += count($one->getRecipients());
            }
        }

        return $count;
    }

    /**
     * One message addressed to as many numbers as asked for, every number of its own.
     * @param int $recipients
     * @param int $offset
     * @param string $senderName
     * @return Message
     * @throws MessageException
     * @throws RecipientException
     */
    private function message(int $recipients, int $offset = 0, string $senderName = self::SENDER): Message
    {
        $recipientCollection = new RecipientCollection;

        for ($i = 0; $i < $recipients; $i++) {
            $recipientCollection[] = new Recipient(sprintf('+421905%06d', $offset + $i));
        }

        $message = new Message;
        $message->setSenderName($senderName);
        $message->setContent(self::TEXT);
        $message->setRecipientCollection($recipientCollection);

        return $message;
    }

    /**
     * A collection of one message addressed to as many numbers as asked for.
     * @param int $recipients
     * @return MessageCollection
     * @throws MessageException
     * @throws RecipientException
     */
    private function collection(int $recipients): MessageCollection
    {
        $messageCollection = new MessageCollection;
        $messageCollection[] = $this->message($recipients);

        return $messageCollection;
    }

    /**
     * @return Percolator
     */
    private function percolator(): Percolator
    {
        $config = new Config;
        $config->setId(self::INTEGRATION_ID);
        $config->setKey(self::INTEGRATION_KEY);

        return new Percolator($config);
    }
}
