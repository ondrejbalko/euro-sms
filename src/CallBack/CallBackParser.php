<?php

declare(strict_types=1);

namespace EuroSms\CallBack;

use DateTimeImmutable;
use EuroSms\Enums\DeliveryResultEnum;
use EuroSms\Enums\FieldEnum;
use EuroSms\Exception\CallBackException;
use JsonException;

/**
 * Reads the body of a CallBack request, chapter 6 of SMS API v3.1.15, into the notification it
 * carries.
 *
 * The gateway pushes the same keys in two shapes. Plain text states them as the parameters of a
 * GET, chapters 6.1.2 and 6.2.1; JSON wraps the very same keys into one parameter, called
 * delivery_report for a receipt and received for an incoming message, chapters 6.1.3 and 6.2.2.
 * Which of the two arrives is settled when the CallBack is registered, so a customer system that
 * reads both is one that survives the setting being changed on it. Either way the same object
 * comes out.
 *
 * A CallBack is the only word about a delivery there is, and one that is not confirmed is
 * repeated for 24 hours and then thrown away as undeliverable, chapters 6.1.1 and 6.2. So a body
 * that cannot be read is refused outright rather than answered with a half filled object:
 *
 * * a field that is absent, or stated as an empty string, counts as unstated,
 * * a required field that is unstated is a body that is incomplete,
 * * a field stated in a shape the documentation does not allow is a body that is unreadable.
 *
 * Required is what the caller cannot act without: the identifier and the delivery status of a
 * report, and everything an incoming message carries. Chapter 6.1.4 states the rest of a report
 * as fields the gateway does not fill in for every destination.
 */
final class CallBackParser
{
    /**
     * The body is neither a delivery report nor an incoming message
     */
    public const int ERROR_BODY_NOT_RECOGNISED = 0;

    /**
     * The parameter that should have carried a JSON document does not decode into one
     */
    public const int ERROR_BODY_NOT_READABLE = 1;

    /**
     * A field the notification cannot be acted on without was not stated
     */
    public const int ERROR_FIELD_MISSING = 2;

    /**
     * A field was stated in a shape the documentation does not allow
     */
    public const int ERROR_FIELD_NOT_READABLE = 3;

    /**
     * The format the gateway states its times in, chapters 6.1.4 and 6.2.3
     */
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * The delivery report the parameters of chapter 6.1.4 describe, whichever of the two shapes
     * they arrived in.
     * @param array<string, mixed> $data
     * @return DeliveryReport
     * @throws CallBackException
     */
    #[\NoDiscard('the built report is the only thing this call produces')]
    public static function deliveryReport(array $data): DeliveryReport
    {
        $data = self::unwrap($data, FieldEnum::CALLBACK_DELIVERY_REPORT);

        return new DeliveryReport(
            self::requireString($data, FieldEnum::CALLBACK_UUID),
            self::requireResult($data, FieldEnum::CALLBACK_DELIVERY_RESULT),
            self::readString($data, FieldEnum::CALLBACK_SENT_RESULT),
            self::readTime($data, FieldEnum::CALLBACK_SENT_TIME),
            self::readTime($data, FieldEnum::CALLBACK_DELIVERY_TIME),
            self::readString($data, FieldEnum::CALLBACK_OPERATOR),
            self::readFloat($data, FieldEnum::CALLBACK_PRICE),
            self::readInt($data, FieldEnum::CALLBACK_SEGMENT)
        );
    }

    /**
     * The notification the body carries, told apart by the parameters it states.
     * @param array<string, mixed> $data the parameters of the request, $_GET or $_POST as they came
     * @return DeliveryReport|ReceivedMessage
     * @throws CallBackException
     */
    #[\NoDiscard('the read notification is the only thing this call produces')]
    public static function parse(array $data): DeliveryReport|ReceivedMessage
    {
        if (self::states($data, FieldEnum::CALLBACK_DELIVERY_REPORT)) {
            return self::deliveryReport($data);
        }

        if (self::states($data, FieldEnum::CALLBACK_RECEIVED)) {
            return self::receivedMessage($data);
        }

        if (
            self::states($data, FieldEnum::CALLBACK_DELIVERY_RESULT)
            || self::states($data, FieldEnum::CALLBACK_SENT_RESULT)
        ) {
            return self::deliveryReport($data);
        }

        if (self::states($data, FieldEnum::CALLBACK_TEXT) || self::states($data, FieldEnum::CALLBACK_RECEIVE_TIME)) {
            return self::receivedMessage($data);
        }

        throw new CallBackException(
            'CallBack body states neither a delivery report nor a received message.',
            self::ERROR_BODY_NOT_RECOGNISED
        );
    }

    /**
     * The same thing read straight off the address the gateway called, for a caller that has the
     * request line rather than the parsed parameters at hand. A whole address is accepted as
     * readily as a bare query string.
     * @param string $query
     * @return DeliveryReport|ReceivedMessage
     * @throws CallBackException
     */
    #[\NoDiscard('the read notification is the only thing this call produces')]
    public static function parseQuery(string $query): DeliveryReport|ReceivedMessage
    {
        $position = strpos($query, '?');

        if (false !== $position) {
            $query = substr($query, $position + 1);
        }

        $parsed = [];
        parse_str($query, $parsed);

        /**
         * A parameter named with digits alone comes back keyed as a number, because a PHP array
         * cannot hold such a key as a string. None of the documented parameters is named that
         * way, so the names are put back the way the rest of the parser reads them.
         */
        $data = [];

        foreach ($parsed as $name => $value) {
            $data[(string)$name] = $value;
        }

        return self::parse($data);
    }

    /**
     * The incoming message the parameters of chapter 6.2.3 describe, whichever of the two shapes
     * they arrived in.
     * @param array<string, mixed> $data
     * @return ReceivedMessage
     * @throws CallBackException
     */
    #[\NoDiscard('the built message is the only thing this call produces')]
    public static function receivedMessage(array $data): ReceivedMessage
    {
        $data = self::unwrap($data, FieldEnum::CALLBACK_RECEIVED);

        return new ReceivedMessage(
            self::requireString($data, FieldEnum::CALLBACK_UUID),
            self::requireString($data, FieldEnum::CALLBACK_RECIPIENT),
            self::requireTime($data, FieldEnum::CALLBACK_RECEIVE_TIME),
            self::requireString($data, FieldEnum::CALLBACK_SENDER),
            self::requireString($data, FieldEnum::CALLBACK_TEXT)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return float|null
     * @throws CallBackException
     */
    private static function readFloat(array $data, FieldEnum $field): ?float
    {
        $value = self::readString($data, $field);

        if (null === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new CallBackException(
                sprintf('CallBack field %s does not state a number: %s.', $field->value, $value),
                self::ERROR_FIELD_NOT_READABLE
            );
        }

        return (float)$value;
    }

    /**
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return int|null
     * @throws CallBackException
     */
    private static function readInt(array $data, FieldEnum $field): ?int
    {
        $value = self::readFloat($data, $field);

        return null === $value ? null : (int)$value;
    }

    /**
     * A field that was not stated at all and one stated as an empty string come out the same
     * way, because the plain shape of chapter 6.1.2 states an unfilled parameter as an empty one.
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return string|null
     * @throws CallBackException
     */
    private static function readString(array $data, FieldEnum $field): ?string
    {
        $value = $data[$field->value] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new CallBackException(
                sprintf('CallBack field %s does not state a single value.', $field->value),
                self::ERROR_FIELD_NOT_READABLE
            );
        }

        $value = (string)$value;

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return DateTimeImmutable|null
     * @throws CallBackException
     */
    private static function readTime(array $data, FieldEnum $field): ?DateTimeImmutable
    {
        $value = self::readString($data, $field);

        return null === $value ? null : self::toTime($value, $field);
    }

    /**
     * Chapter 13.2 calls itself the complete list of delivery statuses, so a status that is not
     * on it is a body this library does not understand rather than one it passes on unread.
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return DeliveryResultEnum
     * @throws CallBackException
     */
    private static function requireResult(array $data, FieldEnum $field): DeliveryResultEnum
    {
        $value = self::requireString($data, $field);

        return DeliveryResultEnum::tryFrom($value) ?? throw new CallBackException(
            sprintf('CallBack field %s states no delivery status of chapter 13.2: %s.', $field->value, $value),
            self::ERROR_FIELD_NOT_READABLE
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return string
     * @throws CallBackException
     */
    private static function requireString(array $data, FieldEnum $field): string
    {
        return self::readString($data, $field) ?? throw new CallBackException(
            sprintf('CallBack field %s was not stated.', $field->value),
            self::ERROR_FIELD_MISSING
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return DateTimeImmutable
     * @throws CallBackException
     */
    private static function requireTime(array $data, FieldEnum $field): DateTimeImmutable
    {
        return self::toTime(self::requireString($data, $field), $field);
    }

    /**
     * Whether the body names the field at all, which is what one notification is told from the
     * other by. What the field then carries is the reader's business, not this one's.
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return bool
     */
    private static function states(array $data, FieldEnum $field): bool
    {
        return array_key_exists($field->value, $data);
    }

    /**
     * The time is read as the documentation states it and nothing else: a value the format does
     * not describe exactly is refused rather than bent into whatever the parser makes of it.
     * @param string $value
     * @param FieldEnum $field
     * @return DateTimeImmutable
     * @throws CallBackException
     */
    private static function toTime(string $value, FieldEnum $field): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!' . self::TIME_FORMAT, $value);

        if (false === $time || $time->format(self::TIME_FORMAT) !== $value) {
            throw new CallBackException(
                sprintf(
                    'CallBack field %s does not state a time as %s: %s.',
                    $field->value,
                    self::TIME_FORMAT,
                    $value
                ),
                self::ERROR_FIELD_NOT_READABLE
            );
        }

        return $time;
    }

    /**
     * The keys themselves, taken out of the parameter the JSON shape wraps them into. The plain
     * shape states them at the top already and is handed back as it came.
     * @param array<string, mixed> $data
     * @param FieldEnum $field
     * @return array<string, mixed>
     * @throws CallBackException
     */
    private static function unwrap(array $data, FieldEnum $field): array
    {
        if (!self::states($data, $field)) {
            return $data;
        }

        $wrapped = $data[$field->value];

        if (is_array($wrapped)) {
            /** @var array<string, mixed> $wrapped */
            return $wrapped;
        }

        if (!is_string($wrapped)) {
            throw new CallBackException(
                sprintf('CallBack parameter %s carries no JSON document.', $field->value),
                self::ERROR_BODY_NOT_READABLE
            );
        }

        try {
            $decoded = json_decode($wrapped, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CallBackException(
                sprintf('CallBack parameter %s is no readable JSON: %s', $field->value, $e->getMessage()),
                self::ERROR_BODY_NOT_READABLE,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new CallBackException(
                sprintf('CallBack parameter %s does not decode into an object.', $field->value),
                self::ERROR_BODY_NOT_READABLE
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
