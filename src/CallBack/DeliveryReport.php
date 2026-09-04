<?php

declare(strict_types=1);

namespace EuroSms\CallBack;

use DateTimeImmutable;
use EuroSms\Enums\DeliveryResultEnum;

/**
 * One delivery report the gateway pushed as a CallBack, chapters 6.1.1 and 6.1.4 of SMS API
 * v3.1.15. There is always exactly one message or one segment behind it, so a message of four
 * segments is pushed as four reports of its own.
 *
 * This is not the report of chapter 9.4 that a status call answers with: the fields the gateway
 * pushes are named and shaped differently from the ones it answers with, which is why the two
 * live side by side rather than as one class.
 *
 * Reading the request is the parser's job and a report is only what the parser found in it. The
 * times come without a zone, so they are read in the time zone the runtime is set to.
 */
final readonly class DeliveryReport
{
    /**
     * @param string $uuid the identifier of the message or of its segment, field sms_uuid
     * @param DeliveryResultEnum $deliveryResult the delivery status of chapter 13.2, field
     *     delivery_result
     * @param string|null $sentResult how the sending itself ended, mostly OK, field sent_result
     * @param DateTimeImmutable|null $sentTime when it went to the operator, field sent_time
     * @param DateTimeImmutable|null $deliveryTime when it reached the phone, field delivery_time
     * @param string|null $operator the network as MCC.MNC, field operator, which chapter 6.1.4
     *     states is not filled in for every destination
     * @param float|null $price what the message or the segment cost in euro, field price
     * @param int|null $segment which segment of a long message this is, one for a short one,
     *     field segment
     */
    public function __construct(
        private string $uuid,
        private DeliveryResultEnum $deliveryResult,
        private ?string $sentResult = null,
        private ?DateTimeImmutable $sentTime = null,
        private ?DateTimeImmutable $deliveryTime = null,
        private ?string $operator = null,
        private ?float $price = null,
        private ?int $segment = null
    ) {
    }

    /**
     * @return DeliveryResultEnum
     */
    public function getDeliveryResult(): DeliveryResultEnum
    {
        return $this->deliveryResult;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getDeliveryTime(): ?DateTimeImmutable
    {
        return $this->deliveryTime;
    }

    /**
     * @return string|null
     */
    public function getOperator(): ?string
    {
        return $this->operator;
    }

    /**
     * @return float|null
     */
    public function getPrice(): ?float
    {
        return $this->price;
    }

    /**
     * @return int|null
     */
    public function getSegment(): ?int
    {
        return $this->segment;
    }

    /**
     * @return string|null
     */
    public function getSentResult(): ?string
    {
        return $this->sentResult;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getSentTime(): ?DateTimeImmutable
    {
        return $this->sentTime;
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return bool
     */
    public function isDelivered(): bool
    {
        return DeliveryResultEnum::DELIVERED === $this->deliveryResult;
    }

    /**
     * Whether this is the last word on the message, chapter 13.2. A report that is not final is
     * followed by another one for the same message, so nothing is closed on it.
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->deliveryResult->isFinal();
    }

    /**
     * Whether the recipient opened the message, which only a Viber message ever reports.
     * @return bool
     */
    public function isSeen(): bool
    {
        return DeliveryResultEnum::SEEN === $this->deliveryResult;
    }
}
