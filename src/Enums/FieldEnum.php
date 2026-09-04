<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The names the gateway gives the fields it exchanges: chapter 9.3 of SMS API v3.1.15 for the
 * fields of a request, chapters 6.1.4, 6.1.6 and 6.2.3 for the ones a CallBack notification is
 * pushed and answered with.
 *
 * The two chapters name the same things differently, a recipient being rcpt in a request and
 * recipient in a CallBack, which is why the CallBack fields are told apart by their prefix
 * rather than folded into the cases above them.
 *
 * A recipient that carries a key of its own is written as an object instead of as a bare number,
 * chapter 9.2, and r and f are the two fields of that object: the number and the key. The gateway
 * repeats the key on the delivery report, chapter 9.4.2, under that same name.
 */
enum FieldEnum: string
{
    case CALLBACK_DELIVERY_REPORT = 'delivery_report';
    case CALLBACK_DELIVERY_RESULT = 'delivery_result';
    case CALLBACK_DELIVERY_TIME = 'delivery_time';
    case CALLBACK_OPERATOR = 'operator';
    case CALLBACK_PRICE = 'price';
    case CALLBACK_RECEIVED = 'received';
    case CALLBACK_RECEIVE_TIME = 'receive_time';
    case CALLBACK_RECIPIENT = 'recipient';
    case CALLBACK_SEGMENT = 'segment';
    case CALLBACK_SENDER = 'sender';
    case CALLBACK_SENT_RESULT = 'sent_result';
    case CALLBACK_SENT_TIME = 'sent_time';
    case CALLBACK_STATUS = 'status';
    case CALLBACK_TEXT = 'sms_text';
    case CALLBACK_UUID = 'sms_uuid';
    case CLIENT_ID = 'iid';
    case DEFAULT_FLAGS = 'dflgs';
    case DEFAULT_INSTANT_MESSAGE_SENDER_NAME = 'dimsndr';
    case DEFAULT_INSTANT_MESSAGE_TTL = 'dimttl';
    case DEFAULT_SENDER_NAME = 'dsndr';
    case DURATION = 'dur';
    case END = 'end';
    case FLAGS = 'flgs';
    case INSTANT_MESSAGE = 'im';
    case INSTANT_MESSAGE_TEXT = 'msg';
    case MESSAGES = 'msgs';
    case RECIPIENT = 'rcpt';
    case RECIPIENTS = 'rcpts';
    case RECIPIENT_IDENTIFIER = 'f';
    case RECIPIENT_NUMBER = 'r';
    case RESPONSE = 'rsp';
    case SCHEDULE = 'sch';
    case SENDER_NAME = 'sndr';
    case SIGN = 'sgn';
    case START = 'start';
    case TEXT = 'txt';
    case TTL = 'ttl';
}
