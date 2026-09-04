<?php

declare(strict_types=1);

namespace EuroSms\Tests;

use EuroSms\Config;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;
use EuroSms\Helpers\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a configuration takes and what it turns down. The integration id and the integration key of
 * chapters 4.1 and 4.2 of SMS API v3.1.15 are what every request is sent and signed under, and
 * neither of them is a value that can say nothing: the service only ever asked whether they had
 * been set at all, so a value that had been set to the empty string passed every check the library
 * makes and was answered for by the gateway instead.
 */
#[CoversClass(Config::class)]
#[CoversClass(Redactor::class)]
final class ConfigTest extends TestCase
{
    public function testAConfigurationThatWasNeverGivenAnythingHandsOutNothing(): void
    {
        $config = new Config;

        self::assertNull($config->getId());
        self::assertNull($config->getKey());
    }

    public function testTheIdAndTheKeyAreKeptAsTheyWereGiven(): void
    {
        $config = new Config;
        $config->setId('2-A2gHjk');
        $config->setKey('Gh-s7-J6');

        self::assertSame('2-A2gHjk', $config->getId());
        self::assertSame('Gh-s7-J6', $config->getKey());
    }

    /**
     * The mistake this is here for. An empty key passed the "was it set at all" check the service
     * makes, signed every request into a WRONG_SIGNATURE the gateway answers after the send, and
     * was never named as the reason. It is turned down where it is set instead, the same way a
     * concurrency of nought is.
     * @param string $key
     */
    #[DataProvider('provideValuesThatSayNothing')]
    public function testAKeyThatSaysNothingIsRefused(string $key): void
    {
        $config = new Config;

        $this->expectException(ConfigException::class);

        $config->setKey($key);
    }

    /**
     * The id is the other half of the same thing and is turned down for the same reason: it goes
     * into "iid" of every request, chapter 4.1, and an empty one is answered with WRONG_IID.
     * @param string $id
     */
    #[DataProvider('provideValuesThatSayNothing')]
    public function testAnIdThatSaysNothingIsRefused(string $id): void
    {
        $config = new Config;

        $this->expectException(ConfigException::class);

        $config->setId($id);
    }

    /**
     * A value made of nothing but whitespace says as little as the empty string does.
     * @return array<string, array{string}>
     */
    public static function provideValuesThatSayNothing(): array
    {
        return [
            'the empty string' => [''],
            'a space' => [' '],
            'a tab and a newline' => ["\t\n"]
        ];
    }

    /**
     * A refused value leaves the configuration as it was rather than half written.
     */
    public function testARefusedValueLeavesTheOneBeforeItStanding(): void
    {
        $config = new Config;
        $config->setKey('Gh-s7-J6');

        try {
            $config->setKey('');
        } catch (ConfigException) {
            // the refusal is what the test above is for; what it left behind is what is asked here
        }

        self::assertSame('Gh-s7-J6', $config->getKey());
    }

    /**
     * The second half of what an empty key cost. A logged body is masked by comparing every value
     * against the key, so an empty key made "$this->key === $value" true for every empty field of
     * every request written into the log, and fields that are no secret at all were written as the
     * mask. Nothing but a real key can mask anything now, because nothing else reaches the
     * Redactor.
     */
    public function testTheRedactorMasksTheKeyAndNotEveryEmptyFieldBesideIt(): void
    {
        $redactor = new Redactor('Gh-s7-J6');

        self::assertSame(
            ['iid' => '2-A2gHjk', 'sgn' => Redactor::MASK, 'txt' => '', 'key' => Redactor::MASK],
            $redactor->data(['iid' => '2-A2gHjk', 'sgn' => 'a1b2c3', 'txt' => '', 'key' => 'Gh-s7-J6'])
        );
    }

    /**
     * A service is still refused the configuration it cannot send under, which is what the two
     * checks in its constructor have always been for.
     */
    public function testAServiceWithoutAnIntegrationIsRefused(): void
    {
        $this->expectException(ConfigException::class);

        new EuroSmsService(new Config);
    }
}
