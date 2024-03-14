<?php

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Gateway\GatewayInterface;

class RequestOneToMany extends RequestOne implements RequestInterface
{
    /** @var int[] $recipients */
    private array $recipients;

    /**
     * @return array<string, array<int, int|null>|int<min, -1>|int<1, max>|string>
     * @throws MessageException
     * @throws RecipientException
     */
    public function getData(): array
    {
        if (!isset($this->recipients)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        if (!isset($this->senderName)) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        $this->sign = $this->calcSignature(
            $this->senderName,
            implode('', $this->recipients),
            $this->content
        );

        return array_filter([
            GatewayInterface::FIELD_CLIENT_ID => $this->clientId,
            GatewayInterface::FIELD_RESPONSE => 'full',
            GatewayInterface::FIELD_SIGN => $this->sign,
            GatewayInterface::FIELD_RECIPIENTS => $this->recipients,
            GatewayInterface::FIELD_FLAGS => $this->flag->getValue(),
            GatewayInterface::FIELD_TTL => $this->ttl ?? null,
            GatewayInterface::FIELD_SENDER_NAME => $this->senderName,
            GatewayInterface::FIELD_START => isset($this->scheduleDateTime) ? $this->scheduleDateTime->format('Y-m-d H:i') : null,
            GatewayInterface::FIELD_TEXT => $this->content
        ]);
    }

    /**
     * @return int[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @param RecipientCollection $recipientCollection
     * @return void
     */
    public function setRecipientCollection(RecipientCollection $recipientCollection): void
    {
        $this->recipients = [];
        foreach ($recipientCollection->all() as $recipient) {
            $this->recipients[] = $recipient->getNumberClean();
        }
    }
}
