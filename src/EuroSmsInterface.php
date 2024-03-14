<?php

namespace EuroSms;

interface EuroSmsInterface
{
    const string REQUEST_CONTENT_TYPE = 'application/json';
    const float REQUEST_TIMEOUT = 30.0;
    const bool REQUEST_VERIFY_HOST = false;

    /**
     * api host address
     */
    const string API_HOST = 'https://as.eurosms.com';

    /**
     * Send one message to one recipient per request
     */
    const string ENDPOINT_SEND_ONE = '/api/v3/send/one';

    /**
     * Test send one message to one recipient per request
     */
    const string ENDPOINT_TEST_ONE = '/api/v3/test/one';

    /**
     * Send one message to multiple recipients per request
     */
    const string ENDPOINT_SEND_ONE_TO_MANY = '/api/v3/send/o2m';

    /**
     * Test send one message to multiple recipients per request
     */
    const string ENDPOINT_TEST_ONE_TO_MANY = '/api/v3/test/o2m';

    /**
     * Send multiple messages to multiple recipients
     */
    const string ENDPOINT_SEND_MANY_TO_MANY = '/api/v3/send/m2m';

    /**
     * Test send multiple messages to multiple recipients
     */
    const string ENDPOINT_TEST_MANY_TO_MANY = '/api/v3/test/m2m';

    /**
     * Get send status of one message
     */
    const string ENDPOINT_STATUS_ONE = '/api/v3/status/one/%s';

    /**
     * Get send status of one message
     */
    const string ENDPOINT_STATUS_ANY = '/api/v3/status/any/%s/%s/%s';
}
