<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Status;

use DateTimeImmutable;
use EuroSms\Enums\DeliveryResultEnum;

/**
 * The delivery status of one segment of one message, chapters 9.4.2 and 9.4.5 of
 * SMS API v3.1.15. A long message is technically split into several segments and the gateway
 * reports every one of them on its own, so a message of four segments is four reports.
 *
 * The gateway states the times as plain 'Y-m-d H:i:s' without a zone, so they are read in the
 * time zone the runtime is set to.
 */
final readonly class DeliveryReport
{
    /**
     * The format the gateway states its times in
     */
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Delivered, the one final status that means the message arrived, chapter 13.2
     * @deprecated Use DeliveryResultEnum::DELIVERED, which carries the rest of the chapter with it.
     */
    public const string STATUS_DELIVERED = DeliveryResultEnum::DELIVERED->value;

    /**
     * @param int|null $recipient the number the message went to, field rcpt
     * @param string|null $carrier the network that carried it, field carrier
     * @param float|null $price what the segment cost, field price
     * @param DateTimeImmutable|null $deliveryTime when the status was reached, field dlr_time
     * @param int|null $segment which segment of the message this is, field sgmnt
     * @param string|null $status the delivery status itself, field dlr
     * @param string|null $uuid the identifier of the segment, field i
     * @param DateTimeImmutable|null $sendTime when the segment was sent, field snd
     * @param string|null $errorCode what went wrong with it, field err_code
     * @param string|null $identifier the key the request gave this recipient, field f
     */
    private function __construct(
        private ?int $recipient,
        private ?string $carrier,
        private ?float $price,
        private ?DateTimeImmutable $deliveryTime,
        private ?int $segment,
        private ?string $status,
        private ?string $uuid,
        private ?DateTimeImmutable $sendTime,
        private ?string $errorCode,
        private ?string $identifier
    ) {
    }

    /**
     * Nothing about a body that came off the wire is taken on trust: a field shaped otherwise
     * than documented is read as if the gateway had not stated it at all.
     * @param array<string, mixed> $data
     * @return self
     */
    #[\NoDiscard('the built report is the only thing this call produces')]
    public static function fromArray(array $data): self
    {
        return new self(
            self::readInt($data['rcpt'] ?? null),
            self::readString($data['carrier'] ?? null),
            self::readFloat($data['price'] ?? null),
            self::readTime($data['dlr_time'] ?? null),
            self::readInt($data['sgmnt'] ?? null),
            self::readString($data['dlr'] ?? null),
            self::readString($data['i'] ?? null),
            self::readTime($data['snd'] ?? null),
            self::readString($data['err_code'] ?? null),
            self::readIdentifier($data['f'] ?? null)
        );
    }

    /**
     * @return string|null
     */
    public function getCarrier(): ?string
    {
        return $this->carrier;
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
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return string|null
     * @deprecated Use getIdentifier(): field f is the key the request gave the recipient, not a flag.
     */
    public function getFlag(): ?string
    {
        return $this->identifier;
    }

    /**
     * The key the request gave this recipient under "f", chapter 9.2, handed back untouched. It
     * is what a receipt is matched to a record of one's own by: a phone number written to twice
     * in a row — an order confirmation and the verification code behind it — reports twice and
     * the two reports are otherwise alike. None when the message named no key.
     * @return string|null
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
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
    public function getRecipient(): ?int
    {
        return $this->recipient;
    }

    /**
     * @return int|null
     */
    public function getSegment(): ?int
    {
        return $this->segment;
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getSendTime(): ?DateTimeImmutable
    {
        return $this->sendTime;
    }

    /**
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * The status of chapter 13.2 the report states, or null when it stated none at all. A status
     * the chapter does not list is read as the error the operator left unnamed; the string as it
     * came stays readable through getStatus() next to this.
     * @return DeliveryResultEnum|null
     */
    public function getDeliveryResult(): ?DeliveryResultEnum
    {
        return null === $this->status ? null : DeliveryResultEnum::fromCode($this->status);
    }

    /**
     * @return string|null
     */
    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * @return bool
     */
    public function isDelivered(): bool
    {
        return DeliveryResultEnum::DELIVERED === $this->getDeliveryResult();
    }

    /**
     * Whether the message is done moving, chapter 13.2. This is what a polling loop stops on: a
     * status that is not final is followed by another report for the same segment, and a report
     * that states no status at all says nothing has been settled yet.
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->getDeliveryResult()?->isFinal() ?? false;
    }

    /**
     * The key comes back the way it was sent, and a recipient given no key sends none: an empty
     * one is read as none here too, so that a report and the recipient it belongs to can be
     * matched by comparing the two keys and nothing else.
     * @param mixed $value
     * @return string|null
     */
    private static function readIdentifier(mixed $value): ?string
    {
        $identifier = self::readString($value);

        return '' === $identifier ? null : $identifier;
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private static function readFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function readInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function readString(mixed $value): ?string
    {
        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * The time is read as the documentation states it and nothing else. Left to itself the parser
     * bends whatever it is handed into a date — the thirteenth month of a year rolls over into the
     * next one, and a value that says nothing about the time of day picks up the time of the run —
     * so the result is read back and refused unless it says exactly what came off the wire.
     * @param mixed $value
     * @return DateTimeImmutable|null
     */
    private static function readTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat('!' . self::TIME_FORMAT, $value);

        return false === $time || $time->format(self::TIME_FORMAT) !== $value ? null : $time;
    }
}
