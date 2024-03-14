<?php

namespace EuroSms\Helpers;

use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;
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
     * @param Message $message
     * @return RequestOne
     * @throws RecipientException
     * @throws RequestException
     */
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

        return $request;
    }

    /**
     * @param Message $message
     * @param RecipientCollection $recipientCollection
     * @return RequestOneToMany
     * @throws RequestException
     */
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

        return $request;
    }

    /**
     * @param RecipientCollection $recipientCollection
     * @param int<2, max> $size
     * @return RecipientCollection[]
     */
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
     * @param RecipientCollection $recipientCollection
     * @return RecipientCollection
     */
    public function getUniqueRecipients(RecipientCollection $recipientCollection): RecipientCollection
    {
        $result = new RecipientCollection;

        foreach ($recipientCollection->all() as $recipient) {
            $result->offsetSet($recipient->getNumberClean(), $recipient);
        }

        return $result;
    }
}
