<?php

declare(strict_types=1);

namespace EuroSms;

interface EuroSmsInterface
{
    /**
     * How many calls of one send may stand at the gateway at the same time. A send too big for
     * one request is split into batches, chapter 9.2.1, and those batches travel together rather
     * than one after another; this is how many of them are on the wire at once.
     *
     * The default is deliberately modest. The gateway is a shared service and nothing here knows
     * what else the integration is sending at the same moment, so the library opens a handful of
     * connections rather than as many as the batches happen to number.
     * @var int
     */
    const int REQUEST_CONCURRENCY = 5;

    /**
     * What the gateway is asked to answer in, and what a request is sent as.
     * @var string
     */
    const string REQUEST_CONTENT_TYPE = 'application/json';

    /**
     * How long one call may take, in seconds.
     * @var float
     */
    const float REQUEST_TIMEOUT = 30.0;

    /**
     * Whether the certificate of the gateway is checked. The messages and the signatures of
     * chapter 9.2.2 travel over this connection, so it is checked unless the caller says
     * otherwise — turning it off leaves the connection open to whoever stands in the middle.
     * @var bool
     */
    const bool REQUEST_VERIFY_HOST = true;

    /**
     * api host address
     * @var string
     */
    const string API_HOST = 'https://as.eurosms.com';
}
