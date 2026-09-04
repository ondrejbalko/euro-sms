<?php

declare(strict_types=1);

namespace EuroSms\Tests\Gateway\Status;

use EuroSms\Config;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Status\StatusRequest;
use EuroSms\Helpers\Signature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The three addresses come from chapters 9.4.1, 9.4.3 and 9.4.4 of SMS API v3.1.15, the
 * signature from chapter 4.2. The integration id and key below are the ones the documentation
 * works its own example with.
 */
#[CoversClass(StatusRequest::class)]
#[CoversClass(Signature::class)]
final class StatusRequestTest extends TestCase
{
    private const string INTEGRATION_ID = '2-A2gHjk';
    private const string INTEGRATION_KEY = 'Gh-s7-J6';
    private const string UUID = 'be863034-df6b-5e3f-b681-649bf7017b9e';
    private const int GROUP_ID = 2231232;

    /**
     * Chapter 4.2: the signature of the documented example is quoted verbatim, so the way this
     * library signs is checked against the gateway's own worked example rather than against
     * itself.
     */
    public function testTheSignatureMatchesTheWorkedExampleFromTheDocumentation(): void
    {
        self::assertSame(
            '6f56060b6b7db97ca25782b771cca0a65077bd5b',
            Signature::calc(self::INTEGRATION_KEY, 'RZi', 421903622237, 'Testovacia sprava')
        );
    }

    /**
     * Chapter 9.4.1: the status of one message is asked by its identifier alone and carries no
     * signature at all.
     */
    public function testTheAddressOfOneMessageIsItsIdentifierAndNothingElse(): void
    {
        self::assertSame('/api/v3/status/one/' . self::UUID, $this->request()->one(self::UUID));
    }

    public function testAnEmptyIdentifierIsRefused(): void
    {
        $this->expectException(RequestException::class);

        (void)$this->request()->one('');
    }

    /**
     * Chapter 9.4.4: the group is asked by the integration id, the group_id and the signature
     * of that group_id.
     */
    public function testTheAddressOfAGroupIsSignedWithTheGroupId(): void
    {
        self::assertSame(
            sprintf(
                '/api/v3/status/group/%s/%d/%s',
                self::INTEGRATION_ID,
                self::GROUP_ID,
                Signature::calc(self::INTEGRATION_KEY, (string)self::GROUP_ID)
            ),
            $this->request()->group(self::GROUP_ID)
        );
    }

    public function testTheGroupSignatureIsTheDocumentedHmacOfTheGroupIdAlone(): void
    {
        self::assertSame(
            '88997487197a03b4008cc7ca3cb36a8dac6a4102',
            $this->signatureOf($this->request()->group(self::GROUP_ID))
        );
    }

    /**
     * Chapter 9.4.3: the bulk status is asked by the integration id, a transaction number and
     * the signature of that transaction number.
     */
    public function testTheAddressOfTheBulkStatusIsSignedWithTheTransactionNumber(): void
    {
        $transaction = 'a1b2c3d4e5f6';

        self::assertSame(
            sprintf(
                '/api/v3/status/any/%s/%s/%s',
                self::INTEGRATION_ID,
                $transaction,
                Signature::calc(self::INTEGRATION_KEY, $transaction)
            ),
            $this->request()->any($transaction)
        );
    }

    /**
     * Chapter 9.4.3: the transaction number is a random string of eight to sixty-four
     * characters, so what the library generates has to fall within that.
     */
    public function testTheGeneratedTransactionNumberIsWithinTheDocumentedLengths(): void
    {
        $transaction = $this->request()->createTransaction();

        self::assertGreaterThanOrEqual(StatusRequest::TRANSACTION_MIN_LENGTH, strlen($transaction));
        self::assertLessThanOrEqual(StatusRequest::TRANSACTION_MAX_LENGTH, strlen($transaction));
    }

    /**
     * A transaction number tells one call from another, so a call reusing the number of an
     * earlier one would read an answer that was already handed out.
     */
    public function testEveryCallIsGivenATransactionNumberOfItsOwn(): void
    {
        $request = $this->request();

        self::assertNotSame($request->any(), $request->any());
    }

    public function testTheGeneratedAddressCarriesTheSignatureOfItsOwnTransactionNumber(): void
    {
        $parts = explode('/', trim($this->request()->any(), '/'));

        self::assertSame(Signature::calc(self::INTEGRATION_KEY, $parts[5]), $parts[6]);
    }

    public function testATransactionNumberShorterThanTheDocumentedMinimumIsRefused(): void
    {
        $this->expectException(RequestException::class);

        (void)$this->request()->any(str_repeat('a', StatusRequest::TRANSACTION_MIN_LENGTH - 1));
    }

    public function testATransactionNumberLongerThanTheDocumentedMaximumIsRefused(): void
    {
        $this->expectException(RequestException::class);

        (void)$this->request()->any(str_repeat('a', StatusRequest::TRANSACTION_MAX_LENGTH + 1));
    }

    /**
     * Nothing the caller hands over ends up in the path unescaped, so an identifier carrying a
     * slash cannot make the call reach an address of its own.
     */
    public function testAnIdentifierCannotAddSegmentsToTheAddress(): void
    {
        self::assertSame(
            '/api/v3/status/one/..%2F..%2Fsend%2Fone',
            $this->request()->one('../../send/one')
        );
    }

    /**
     * The last segment of the address is the signature it was built with.
     * @param string $uri
     * @return string
     */
    private function signatureOf(string $uri): string
    {
        $parts = explode('/', $uri);

        return (string)end($parts);
    }

    /**
     * @return StatusRequest
     */
    private function request(): StatusRequest
    {
        $config = new Config;
        $config->setId(self::INTEGRATION_ID);
        $config->setKey(self::INTEGRATION_KEY);

        return new StatusRequest($config);
    }
}
