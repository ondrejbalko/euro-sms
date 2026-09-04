<?php

declare(strict_types=1);

namespace EuroSms\Tests\Helpers;

use EuroSms\Config;
use EuroSms\EuroSmsInterface;
use EuroSms\Helpers\GuzzleClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The client the library falls back to when the caller hands over none. Everything the transport
 * is configured by lives here, so this is where the configuration has to be shown to reach it.
 */
#[CoversClass(GuzzleClientFactory::class)]
final class GuzzleClientFactoryTest extends TestCase
{
    /**
     * The gateway is reached over TLS, so whether its certificate is checked has to follow the
     * configuration rather than the compiled-in default.
     * @return void
     */
    public function testCertificateVerificationFollowsTheConfiguration(): void
    {
        $config = new Config;
        $config->setRequestVerifyHost(false);

        self::assertFalse(GuzzleClientFactory::createOptions($config)['verify']);
    }

    /**
     * The messages and the signatures of chapter 9.2.2 travel over this connection, so the
     * certificate is checked unless the caller goes out of its way to say otherwise.
     * @return void
     */
    public function testCertificateVerificationIsOnUntilItIsTurnedOff(): void
    {
        self::assertTrue(GuzzleClientFactory::createOptions(new Config)['verify']);
        self::assertTrue(EuroSmsInterface::REQUEST_VERIFY_HOST);
    }

    /**
     * @return void
     */
    public function testHowLongACallMayTakeFollowsTheConfiguration(): void
    {
        $config = new Config;
        $config->setRequestTimeout(2.5);

        self::assertSame(2.5, GuzzleClientFactory::createOptions($config)['timeout']);
        self::assertSame(EuroSmsInterface::REQUEST_TIMEOUT, GuzzleClientFactory::createOptions(new Config)['timeout']);
    }

    /**
     * Every request carries the address of the gateway in full, so the client is given no base
     * address to resolve one against.
     * @return void
     */
    public function testTheDefaultClientResolvesNoAddressOfItsOwn(): void
    {
        self::assertArrayNotHasKey('base_uri', GuzzleClientFactory::createOptions(new Config));
    }

    /**
     * @return void
     */
    public function testTheDefaultClientIsAPsrHttpClient(): void
    {
        $client = GuzzleClientFactory::createClient(new Config);

        self::assertInstanceOf(Client::class, $client);
        self::assertInstanceOf(ClientInterface::class, $client);
    }

    /**
     * One factory covers both of the interfaces the send path builds a request with.
     * @return void
     */
    public function testTheDefaultFactoryBuildsBothTheRequestAndItsBody(): void
    {
        $factory = GuzzleClientFactory::createFactory();

        self::assertInstanceOf(HttpFactory::class, $factory);
        self::assertInstanceOf(RequestFactoryInterface::class, $factory);
        self::assertInstanceOf(StreamFactoryInterface::class, $factory);
    }
}
