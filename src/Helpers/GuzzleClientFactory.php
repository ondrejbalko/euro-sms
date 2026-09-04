<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

use EuroSms\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\RequestOptions;

/**
 * The one place in the library that still names Guzzle. Everything above it speaks PSR-18 and
 * PSR-17 alone, so a caller handing over a client of its own never reaches any of this; a caller
 * handing over nothing gets the Guzzle client built here, configured the way the configuration
 * asks for. Replacing Guzzle is therefore a matter of handing over another client, not of
 * touching the send path.
 */
final class GuzzleClientFactory
{
    /**
     * The client the library falls back to when the caller hands over none.
     * @param Config $config
     * @return Client
     */
    #[\NoDiscard('the built client is the only thing this call produces')]
    public static function createClient(Config $config): Client
    {
        return new Client(self::createOptions($config));
    }

    /**
     * The PSR-17 factory the requests and their bodies are built with. Guzzle's own covers both
     * of the interfaces the send path asks for, so one instance serves as both.
     * @return HttpFactory
     */
    #[\NoDiscard('the built factory is the only thing this call produces')]
    public static function createFactory(): HttpFactory
    {
        return new HttpFactory;
    }

    /**
     * What the default client is configured with. The address of the gateway is written into
     * every request in full, so nothing here has to resolve it and no base address is set; what
     * is left is how long a call may take and whether the certificate of the gateway is checked.
     * Both are transport, so a client handed over from outside brings its own.
     * @param Config $config
     * @return array{timeout: float, verify: bool}
     */
    #[\NoDiscard('the options are the only thing this call produces')]
    public static function createOptions(Config $config): array
    {
        return [
            RequestOptions::TIMEOUT => $config->getRequestTimeout(),
            RequestOptions::VERIFY => $config->getRequestVerifyHost()
        ];
    }
}
