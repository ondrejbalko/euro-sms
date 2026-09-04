<?php

declare(strict_types=1);

namespace EuroSms\CallBack;

use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\CallBackException;
use JsonException;
use JsonSerializable;
use Stringable;

/**
 * The answer a CallBack is owed, chapters 6.1.6 and 6.2.4 of SMS API v3.1.15.
 *
 * The gateway works out the shape of the answer on its own, so the plain and the JSON form are
 * equally good and neither has to match the shape the notification arrived in. What it does not
 * forgive is an answer shaped otherwise than these two: a CallBack that goes unconfirmed is
 * pushed again for 24 hours and then thrown away as undeliverable, chapters 6.1.1 and 6.2, and
 * with it the only word about that delivery there was.
 *
 * The answer names the message it confirms, so a notification is only ever confirmed by the
 * handler that read it.
 */
final readonly class Acknowledgement implements JsonSerializable, Stringable
{
    /**
     * The one outcome the gateway reads as confirmed, chapters 6.1.6 and 6.2.4
     */
    public const string STATUS_OK = 'ok';

    /**
     * There is no message to confirm
     */
    public const int ERROR_UUID_NOT_DEFINED = 0;

    /**
     * The answer cannot be stated as JSON
     */
    public const int ERROR_NOT_ENCODABLE = 1;

    /**
     * What stands between the outcome and the identifier in the plain answer, chapter 6.1.6
     */
    private const string PLAIN_SEPARATOR = '|';

    /**
     * @param string $uuid
     */
    private function __construct(private string $uuid)
    {
    }

    /**
     * The answer to whichever of the two notifications was read.
     * @param DeliveryReport|ReceivedMessage $notification
     * @return self
     * @throws CallBackException
     */
    #[\NoDiscard('the built acknowledgement is the only thing this call produces')]
    public static function of(DeliveryReport|ReceivedMessage $notification): self
    {
        return self::ofUuid($notification->getUuid());
    }

    /**
     * @param string $uuid the identifier the notification carried, field sms_uuid
     * @return self
     * @throws CallBackException
     */
    #[\NoDiscard('the built acknowledgement is the only thing this call produces')]
    public static function ofUuid(string $uuid): self
    {
        if ('' === $uuid) {
            throw new CallBackException(
                'CallBack acknowledgement names no message to confirm.',
                self::ERROR_UUID_NOT_DEFINED
            );
        }

        return new self($uuid);
    }

    /**
     * @return string
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->toPlain();
    }

    /**
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @return array{sms_uuid: string, status: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The answer a notification is acknowledged with, chapter 6.2.3 of SMS API v3.1.15:
     *
     * - sms_uuid: the identifier of the notification that is being acknowledged
     * - status: that it was taken over, which is the only thing this answer ever says
     *
     * @return array{sms_uuid: string, status: string}
     */
    public function toArray(): array
    {
        return [
            FieldEnum::CALLBACK_UUID->value => $this->uuid,
            FieldEnum::CALLBACK_STATUS->value => self::STATUS_OK
        ];
    }

    /**
     * @return string
     * @throws CallBackException
     */
    #[\NoDiscard('the built answer is the only thing this call produces')]
    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CallBackException(
                'CallBack acknowledgement cannot be stated as JSON: ' . $e->getMessage(),
                self::ERROR_NOT_ENCODABLE,
                $e
            );
        }
    }

    /**
     * The answer of chapter 6.1.6 as it is written there, with nothing around it: the gateway
     * reads the whole body, so a line break or a space of its own would already be another
     * answer.
     * @return string
     */
    #[\NoDiscard('the built answer is the only thing this call produces')]
    public function toPlain(): string
    {
        return self::STATUS_OK . self::PLAIN_SEPARATOR . $this->uuid;
    }
}
