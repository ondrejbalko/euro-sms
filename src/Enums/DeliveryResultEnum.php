<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The delivery statuses of chapter 13.2 of SMS API v3.1.15, which calls itself the complete list
 * of them. A CallBack delivery report states one of these and nothing else, chapter 6.1.4.
 *
 * The chapter marks each of them as final or not: a status that is not final is one the message
 * passes through, and the status it ends in is reported on its own afterwards.
 */
enum DeliveryResultEnum: string
{
    /**
     * Accepted by the SMS centre of the operator and waiting to go out
     */
    case ACCEPTED = 'ACCEPTD';

    /**
     * Cancelled by the SMS centre of the operator
     */
    case DELETED = 'DELETED';

    /**
     * Delivered, the one status that means the message arrived
     */
    case DELIVERED = 'DELIVRD';

    /**
     * Queued at the operator
     */
    case EN_ROUTE = 'ENROUTE';

    /**
     * Undelivered within the expiry the operator keeps, seven days at the most
     */
    case EXPIRED = 'EXPIRED';

    /**
     * Refused by the operator, an unpaid or long switched off subscriber
     */
    case REJECTED = 'REJECTD';

    /**
     * The recipient saw the message on their phone. Instant messages only, so a report saying
     * this came from Viber and never from an SMS.
     */
    case SEEN = 'SEEN';

    /**
     * Undeliverable, an unknown number or a phone switched off
     */
    case UNDELIVERABLE = 'UNDELIV';

    /**
     * An error of the operator that it did not name
     */
    case UNKNOWN = 'UNKNOWN';

    /**
     * The status a code stands for. A code the chapter does not list is the error the operator
     * left unnamed, which is what UNKNOWN is for; the code itself stays where it came from, in
     * the body of the report, so the original wording is never lost by reading it.
     * @param string|null $code
     * @return self
     */
    public static function fromCode(?string $code): self
    {
        return null === $code ? self::UNKNOWN : self::tryFrom($code) ?? self::UNKNOWN;
    }

    /**
     * Whether the code is one the chapter lists. Anything else is read as UNKNOWN, and this is
     * what tells such a code apart from an UNKNOWN the operator itself reported.
     * @param string|null $code
     * @return bool
     */
    public static function isKnownCode(?string $code): bool
    {
        return null !== $code && null !== self::tryFrom($code);
    }

    /**
     * The status written out, so that what is shown does not depend on the wording of the report
     * the operator happened to push.
     * @return string
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::ACCEPTED => 'Accepted by the SMS centre of the operator',
            self::DELETED => 'Cancelled by the SMS centre of the operator',
            self::DELIVERED => 'Delivered',
            self::EN_ROUTE => 'Queued at the operator',
            self::EXPIRED => 'Undelivered before the expiry ran out',
            self::REJECTED => 'Refused by the operator',
            self::SEEN => 'The recipient opened the message',
            self::UNDELIVERABLE => 'Undeliverable',
            self::UNKNOWN => 'An error the operator did not name'
        };
    }

    /**
     * Whether this is the last word on the message. The chapter marks five statuses as final;
     * the rest are followed by a report of their own, so nothing is closed on them. SEEN is not
     * among the five: it says the recipient opened an instant message that a report of its own
     * had already delivered.
     *
     * UNKNOWN closes the message all the same. It stands for an error the operator did not
     * name, and nothing follows an error.
     * @return bool
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::ACCEPTED, self::EN_ROUTE, self::SEEN => false,
            default => true
        };
    }
}
