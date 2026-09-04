<?php

declare(strict_types=1);

namespace EuroSms\CallBack;

use DateTimeImmutable;

/**
 * One message that arrived at an inbound number, chapter 6.2.3 of SMS API v3.1.15.
 *
 * A long message is notified in one piece: the gateway waits for every segment the operator
 * sends, puts them back together and only then pushes the whole text, chapter 6.2. That is why
 * an incoming message knows nothing about segments while a delivery report does.
 *
 * Reading the request is the parser's job and a message is only what the parser found in it. The
 * time comes without a zone, so it is read in the time zone the runtime is set to.
 */
final readonly class ReceivedMessage
{
    /**
     * @param string $uuid the identifier of the message, field sms_uuid
     * @param string $recipient the inbound number it arrived at, field recipient
     * @param DateTimeImmutable $receiveTime when the operator handed it over, field receive_time
     * @param string $sender the number it came from, field sender
     * @param string $text the whole text of the message, field sms_text
     */
    public function __construct(
        private string $uuid,
        private string $recipient,
        private DateTimeImmutable $receiveTime,
        private string $sender,
        private string $text
    ) {
    }

    /**
     * @return DateTimeImmutable
     */
    public function getReceiveTime(): DateTimeImmutable
    {
        return $this->receiveTime;
    }

    /**
     * @return string
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * @return string
     */
    public function getSender(): string
    {
        return $this->sender;
    }

    /**
     * @return string
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }
}
