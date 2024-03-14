<?php

namespace EuroSms\Entities\Message;

interface MessageInterface
{
    /**
     * Default length of senders name
     */
    const int MAX_SENDER_NAME_LENGTH = 11;

    const int FLAG_DEFAULT = 0;
    const int FLAG_RECEIPT = 1;
    const int FLAG_LONG = 2;
    const int FLAG_UNICODE_SHORT = 4;
    const int FLAG_UNICODE_LONG = 6;
    const int FLAG_HIGH_PRIORITY = 8;
    const int FLAG_LOW_PRIORITY = 32;
    const int FLAG_VIBER_ONLY = 128;
    const int FLAG_VIBER_PROMO = 256;
    const int FLAG_VIBER = 512;
//
    const int ERROR_SENDER_NOT_DEFINED = 0;
    const int ERROR_SENDER_COLLECTION_IS_EMPTY = 1;
}
