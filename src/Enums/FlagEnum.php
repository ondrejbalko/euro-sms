<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The single bits the "flgs" value of a message is composed of, chapter 14.1 of SMS API v3.1.15.
 * A value that is not listed here is no flag of its own but the bits it is composed of, six for
 * instance being a long message with diacritics, that is two flags at once. Such a number is
 * taken apart rather than looked up, which is what Flag::setFlags() does with it.
 */
enum FlagEnum: int
{
    case DEFAULT = 0;
    case RECEIPT = 1;
    case LONG = 2;
    case UNICODE_SHORT = 4;
    case HIGH_PRIORITY = 8;
    case LOW_PRIORITY = 32;
    case VIBER_ONLY = 128;
    case VIBER_PROMO = 256;
    case VIBER = 512;
}
