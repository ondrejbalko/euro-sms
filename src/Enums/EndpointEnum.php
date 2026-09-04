<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The paths of the api, chapters 9.3, 9.4 and 9.5 of SMS API v3.1.15. Every send endpoint is mirrored
 * by a test one that runs the whole validation and answers the same way, but delivers nothing.
 */
enum EndpointEnum: string
{
    /**
     * Send one message to one recipient per request
     */
    case SEND_ONE = '/api/v3/send/one';

    /**
     * Test send one message to one recipient per request
     */
    case TEST_ONE = '/api/v3/test/one';

    /**
     * Send one message to multiple recipients per request
     */
    case SEND_ONE_TO_MANY = '/api/v3/send/o2m';

    /**
     * Test send one message to multiple recipients per request
     */
    case TEST_ONE_TO_MANY = '/api/v3/test/o2m';

    /**
     * Send multiple messages to multiple recipients
     */
    case SEND_MANY_TO_MANY = '/api/v3/send/m2m';

    /**
     * Test send multiple messages to multiple recipients
     */
    case TEST_MANY_TO_MANY = '/api/v3/test/m2m';

    /**
     * Get send status of one message
     */
    case STATUS_ONE = '/api/v3/status/one/%s';

    /**
     * Get send status of any message whose status changed since the last call
     */
    case STATUS_ANY = '/api/v3/status/any/%s/%s/%s';

    /**
     * Get send status of the messages of one group transaction
     */
    case STATUS_GROUP = '/api/v3/status/group/%s/%s/%s';

    /**
     * The test counterpart of a send endpoint, the endpoint itself when it has none.
     * @return self
     */
    #[\NoDiscard('the test counterpart is the only thing this call produces')]
    public function toTest(): self
    {
        return match ($this) {
            self::SEND_ONE => self::TEST_ONE,
            self::SEND_ONE_TO_MANY => self::TEST_ONE_TO_MANY,
            self::SEND_MANY_TO_MANY => self::TEST_MANY_TO_MANY,
            default => $this
        };
    }
}
