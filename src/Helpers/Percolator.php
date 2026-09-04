<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

use EuroSms\Config;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Request\RequestManyToMany;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Request\RequestOneToMany;

readonly class Percolator
{
    /**
     * @param Config $config
     */
    public function __construct(private Config $config)
    {
    }

    /**
     * What a message of a group transaction cannot carry, chapters 9.3.1 and 9.3.8 of SMS API
     * v3.1.15: the entry of a transaction lists neither a moment to send at nor a time to live,
     * and the root of the transaction has a default for neither, so anything a message was given
     * that way would be dropped somewhere between here and the wire. A batch that was asked to go
     * out on Friday and would instead leave at once is worth refusing rather than sending.
     *
     * The one thing a whole transaction can be spread over time by is "dur", chapter 9.2.1, and
     * that one the root does carry.
     * @param Message $message
     * @return void
     * @throws MessageException
     * @throws RequestException
     */
    private function checkGroupMessage(Message $message): void
    {
        /**
         * The sender every message of a transaction falls back to is the one of its root,
         * chapter 9.3.8, and that name is taken from the messages themselves — so a message
         * that named none went out, and was signed, under the name of whichever other message
         * happened to name one first. A single send has always refused a message with no sender
         * and a transaction refuses one too, before anything is sent under a name the caller
         * never wrote.
         */
        if (null === $message->getSenderName()) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        $unsupported = [];

        if (null !== $message->getScheduleDateTime()) {
            $unsupported[] = 'a moment to be sent at';
        }

        if (null !== $message->getEnd()) {
            $unsupported[] = 'a moment to be over by';
        }

        if (null !== $message->getTtl()) {
            $unsupported[] = 'a time to live';
        }

        if ([] !== $unsupported) {
            throw new RequestException(
                sprintf('A message of a many-to-many transaction cannot carry %s.', implode(' or ', $unsupported)),
                RequestInterface::ERROR_MESSAGE_NOT_SCHEDULABLE
            );
        }
    }

    /**
     * @param Message $message
     * @return RequestOne
     * @throws RecipientException
     * @throws RequestException
     */
    #[\NoDiscard('the built request is the only thing this call produces')]
    public function createRequestOne(Message $message): RequestOne
    {
        $request = new RequestOne($this->config);
        $request->setMessageId($message->getId());
        $request->setRecipient($message->getRecipient());
        $request->setContent($message->getContent(), $message->getFlag(), $message->isUnicode());

        if ($message->getSenderName()) {
            $request->setSenderName($message->getSenderName());
        }

        if ($message->getTtl()) {
            $request->setTtl($message->getTtl());
        }

        if ($message->getScheduleDateTime()) {
            $request->setScheduleDateTime($message->getScheduleDateTime());
        }

        $instantMessage = $message->getInstantMessage();

        if (null !== $instantMessage) {
            $request->setInstantMessage($instantMessage);
        }

        return $request;
    }

    /**
     * A group transaction carries at most 1000 messages, so a bigger collection is split into
     * several requests. What counts towards that limit is how many messages the transaction
     * sends, not how many entries it lists: one entry addressed to a thousand numbers is already
     * a thousand messages. A message addressed to more numbers than one entry may carry is split
     * beforehand, exactly the way a one-to-many send splits it.
     *
     * Both ends of the count are answered for here rather than at the gateway. Over the limit the
     * collection is split and every message is still sent, because a send of more than a thousand
     * messages is perfectly allowed and merely does not fit into one transaction. A collection
     * with nothing in it is refused instead: it used to be built, posted and answered with a
     * tally of nothing, and the caller was never told that what it handed over was empty.
     * @param MessageCollection $messageCollection
     * @return RequestManyToMany[]
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    #[\NoDiscard('the built requests are the only thing this call produces')]
    public function createRequestManyToMany(MessageCollection $messageCollection): array
    {
        if (0 === $messageCollection->count()) {
            throw new MessageException(
                'Message collection is empty.',
                MessageInterface::ERROR_MESSAGE_COLLECTION_IS_EMPTY
            );
        }

        $messages = [];
        $defaultDuration = null;

        foreach ($messageCollection->all() as $message) {
            $this->checkGroupMessage($message);

            $duration = $message->getDuration();

            /**
             * There is one "dur" and it belongs to the root of the transaction, chapter 9.2.1,
             * so two messages naming two different lengths cannot both be honoured: the first
             * one was kept and the second dropped without a word, which is what a schedule is
             * refused for a few lines above. A message naming none still falls back to the one
             * the root carries.
             */
            if (null !== $duration && null !== $defaultDuration && $duration !== $defaultDuration) {
                throw new RequestException(
                    sprintf(
                        'A transaction is spread over one length of time, %s and %s given.',
                        $defaultDuration,
                        $duration
                    ),
                    RequestInterface::ERROR_DURATION_AMBIGUOUS
                );
            }

            $unique = $this->getUniqueRecipients($message->getRecipientCollection());
            $message->setRecipientCollection($unique);
            $defaultDuration ??= $duration;

            foreach ($this->getRecipientBatches($unique) as $batch) {
                $messages[] = $this->createRequestOneToMany($message, $batch);
            }
        }

        $chunks = [];
        $chunk = [];
        $count = 0;

        foreach ($messages as $one) {
            $size = count($one->getRecipients());

            if ([] !== $chunk && GatewayInterface::MAX_ITEMS_PER_REQUEST < $count + $size) {
                $chunks[] = $chunk;
                $chunk = [];
                $count = 0;
            }

            $chunk[] = $one;
            $count += $size;
        }

        if ([] !== $chunk) {
            $chunks[] = $chunk;
        }

        /**
         * "dur" belongs to the root of one transaction, chapter 9.2.1, and a collection too big
         * for one transaction has a root for each of the transactions it is split into. Every one
         * of them carried the whole length and all of them went out at the same time, so a
         * thousand messages asked to leave over an hour left as three thousand over that hour —
         * three times the rate the caller was throttling to, and the very thing "dur" was set for.
         * Splitting the length between the transactions does not answer it either, because they
         * still travel together. A length that cannot be honoured is refused the same way a
         * schedule a transaction cannot carry is, rather than sent as something else.
         */
        if (null !== $defaultDuration && 1 < count($chunks)) {
            throw new RequestException(
                sprintf(
                    'A collection spread over %s does not fit into one transaction of %d messages, and %d of them cannot be spread over it together.',
                    $defaultDuration,
                    GatewayInterface::MAX_ITEMS_PER_REQUEST,
                    count($chunks)
                ),
                RequestInterface::ERROR_DURATION_NOT_SPLITTABLE
            );
        }

        $requests = [];

        foreach ($chunks as $one) {
            $requests[] = $this->createGroupRequest($one, $defaultDuration);
        }

        return $requests;
    }

    /**
     * One transaction out of the messages it is to carry. The sender name and the Viber sender the
     * whole group falls back to are the ones of the first message of this transaction that named
     * any — of this one and not of the collection: a transaction states its defaults for the
     * messages it holds, chapter 9.3.8, and a collection split into several of them was given the
     * defaults of the first message of the first transaction throughout. A message of the second
     * transaction was then compared against a name no message in it carries, so what the root
     * already says was written out on every message again, and "dimsndr" named a Viber sender for
     * messages that never asked for one.
     * @param RequestOneToMany[] $requests
     * @param string|null $duration how long the sending of the whole transaction is spread over
     * @return RequestManyToMany
     * @throws MessageException
     * @throws RequestException
     */
    private function createGroupRequest(array $requests, ?string $duration = null): RequestManyToMany
    {
        $request = new RequestManyToMany($this->config);
        $senderName = null;

        /** @var InstantMessage|null $instantMessage */
        $instantMessage = null;

        foreach ($requests as $one) {
            if (null === $senderName && $one->hasSenderName()) {
                $senderName = $one->getSenderName();
            }

            $instantMessage ??= $one->getInstantMessage();
        }

        if (null !== $senderName) {
            $request->setDefaultSenderName($senderName);
        }

        if (null !== $duration) {
            $request->setDuration($duration);
        }

        if (null !== $instantMessage) {
            $request->setDefaultInstantMessage($instantMessage);
        }

        foreach ($requests as $one) {
            $request->addRequest($one);
        }

        return $request;
    }

    /**
     * @param Message $message
     * @param RecipientCollection $recipientCollection
     * @return RequestOneToMany
     * @throws RequestException
     */
    #[\NoDiscard('the built request is the only thing this call produces')]
    public function createRequestOneToMany(Message $message, RecipientCollection $recipientCollection): RequestOneToMany
    {
        $request = new RequestOneToMany($this->config);
        $request->setMessageId($message->getId());
        $request->setRecipientCollection($recipientCollection);
        $request->setContent($message->getContent(), $message->getFlag(), $message->isUnicode());

        if ($message->getSenderName()) {
            $request->setSenderName($message->getSenderName());
        }

        if ($message->getTtl()) {
            $request->setTtl($message->getTtl());
        }

        if ($message->getScheduleDateTime()) {
            $request->setScheduleDateTime($message->getScheduleDateTime());
        }

        if ($message->getDuration()) {
            $request->setDuration($message->getDuration());
        }

        if ($message->getEnd()) {
            $request->setEnd($message->getEnd());
        }

        $instantMessage = $message->getInstantMessage();

        if (null !== $instantMessage) {
            $request->setInstantMessage($instantMessage);
        }

        return $request;
    }

    /**
     * @param RecipientCollection $recipientCollection
     * @param int<2, max> $size
     * @return RecipientCollection[]
     */
    #[\NoDiscard('the batches are the only thing this call produces')]
    public function getRecipientBatches(RecipientCollection $recipientCollection, int $size = GatewayInterface::MAX_ITEMS_PER_REQUEST): array
    {
        if ($size > GatewayInterface::MAX_ITEMS_PER_REQUEST) {
            $size = GatewayInterface::MAX_ITEMS_PER_REQUEST;
        }

        $chunks = array_chunk($recipientCollection->all(), $size);

        $batches = [];
        foreach ($chunks as $chunk) {
            $collection = new RecipientCollection;
            foreach ($chunk as $number) {
                $collection[] = $number;
            }
            $batches[] = $collection;
        }

        return $batches;
    }

    /**
     * The recipients with the duplicates dropped. What makes two of them the same is the number
     * together with the key each carries, chapter 9.2, and not the number alone: the key exists
     * precisely because one number is written to more than once — an order confirmation and the
     * verification code behind it — and the gateway repeats it on the delivery report so the two
     * can be told apart. Keyed by the bare number, the second of them overwrote the first, one of
     * the two messages was never sent and only the surviving key ever reported.
     *
     * A recipient carrying no key is the number it always was, and two of those are still one.
     * @param RecipientCollection $recipientCollection
     * @return RecipientCollection
     */
    #[\NoDiscard('the deduplicated collection is the only thing this call produces')]
    public function getUniqueRecipients(RecipientCollection $recipientCollection): RecipientCollection
    {
        $result = new RecipientCollection;

        foreach ($recipientCollection->all() as $recipient) {
            $identifier = $recipient->getIdentifier();
            $number = $recipient->getNumberClean();

            $result->offsetSet(
                null === $identifier ? $number : $number . ':' . $identifier,
                $recipient
            );
        }

        return $result;
    }
}
