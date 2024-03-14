<?php

namespace EuroSms\Gateway;

interface GatewayInterface
{
    /**
     * How much messages can be sent per one request
     */
    const int MAX_ITEMS_PER_REQUEST = 1000;

    /**
     * Default length of one SMS message
     */
    const int MAX_MESSAGE_LENGTH = 160;

    /**
     * Default length of next SMS message
     */
    const int MAX_NEXT_MESSAGE_LENGTH = 153;

    /**
     * Default length of one SMS message (with Unicode characters)
     */
    const int MAX_UNICODE_MESSAGE_LENGTH = 70;

    /**
     * Default length of next SMS message (with Unicode characters)
     */
    const int MAX_UNICODE_NEXT_MESSAGE_LENGTH = 67;

    const string FIELD_CLIENT_ID = 'iid';
    const string FIELD_FLAGS = 'flgs';
    const string FIELD_RECIPIENT = 'rcpt';
    const string FIELD_RECIPIENTS = 'rcpts';
    const string FIELD_RESPONSE = 'rsp';
    const string FIELD_SCHEDULE = 'sch';
    const string FIELD_SIGN = 'sgn';
    const string FIELD_SENDER_NAME = 'sndr';
    const string FIELD_START = 'start';
    const string FIELD_TEXT = 'txt';
    const string FIELD_TTL = 'ttl';

    /**
     * Message accepted and enqueued to send.
     */
    const string RESPONSE_ENQUEUED = 'ENQUEUED';
}
