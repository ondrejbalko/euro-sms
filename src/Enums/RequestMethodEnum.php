<?php

declare(strict_types=1);

namespace EuroSms\Enums;

/**
 * The http methods the api is reached with, chapters 9.3 and 9.4 of SMS API v3.1.15. A send
 * hands its request over as a body and is posted; a delivery status carries everything it needs
 * in its path and is read.
 */
enum RequestMethodEnum: string
{
    /**
     * Read a delivery status, chapter 9.4
     */
    case GET = 'GET';

    /**
     * Send a message, chapter 9.3
     */
    case POST = 'POST';
}
