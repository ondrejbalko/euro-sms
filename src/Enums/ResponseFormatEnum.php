<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * How much the gateway is asked to answer with, the "rsp" parameter of chapter 9.2.1 of
 * SMS API v3.1.15. The gateway itself falls back to the basic answer when the request states
 * nothing, so that is what the library sends unless it needs more.
 */
enum ResponseFormatEnum: string
{
    /**
     * The tally alone: how many messages were accepted and how many were refused.
     */
    case BASIC = 'basic';

    /**
     * Every number written out on its own, which is what the "accepted", "wrong_numbers" and
     * "result" lists of chapters 9.3.4.1 and 9.3.11 are read from.
     */
    case FULL = 'full';
}
