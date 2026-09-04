<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Status;

use EuroSms\Config;
use EuroSms\Enums\EndpointEnum;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Helpers\Signature;
use Random\RandomException;

/**
 * The addresses the three delivery status operations of chapter 9.4 of SMS API v3.1.15 are
 * reached at. All three are read with GET and carry everything they need in the path, so a
 * status request is nothing but the path it is asked at.
 *
 * The signature is built the documented way for each of them: status/one is asked without one,
 * status/group signs the group_id and status/any signs the transaction number.
 */
readonly class StatusRequest
{
    /**
     * The shortest transaction number the gateway accepts, chapter 9.4.3
     */
    public const int TRANSACTION_MIN_LENGTH = 8;

    /**
     * The longest transaction number the gateway accepts, chapter 9.4.3
     */
    public const int TRANSACTION_MAX_LENGTH = 64;

    /**
     * How many random bytes a generated transaction number is built from. Hexadecimal states a
     * byte in two characters, so the number itself comes out twice as long and well within the
     * documented range.
     */
    private const int TRANSACTION_BYTES = 16;

    /**
     * @param Config $config
     */
    public function __construct(private Config $config)
    {
    }

    /**
     * The status of whatever changed since the last call, chapter 9.4.3, no matter which send it
     * came from. The transaction number tells the gateway one call from another, so every call
     * is given a new one and the signature is built from it.
     * @param string|null $transaction the transaction number, a generated one when none is given
     * @return string
     * @throws RandomException
     * @throws RequestException
     */
    #[\NoDiscard('the built path is the only thing this call produces')]
    public function any(?string $transaction = null): string
    {
        $transaction ??= $this->createTransaction();

        $length = strlen($transaction);

        if (self::TRANSACTION_MIN_LENGTH > $length || self::TRANSACTION_MAX_LENGTH < $length) {
            throw new RequestException(sprintf(
                'Transaction number has to be %d to %d characters long, %d given.',
                self::TRANSACTION_MIN_LENGTH,
                self::TRANSACTION_MAX_LENGTH,
                $length
            ));
        }

        return sprintf(
            EndpointEnum::STATUS_ANY->value,
            rawurlencode((string)$this->config->getId()),
            rawurlencode($transaction),
            $this->sign($transaction)
        );
    }

    /**
     * A transaction number of its own for every call, so that a call never reads the answer
     * meant for an earlier one.
     * @return string
     * @throws RandomException
     */
    #[\NoDiscard('the generated transaction number is the only thing this call produces')]
    public function createTransaction(): string
    {
        return bin2hex(random_bytes(self::TRANSACTION_BYTES));
    }

    /**
     * The status of the messages of one group transaction, chapter 9.4.4. The group is the
     * group_id the o2m and m2m answers carried, and it is what the signature is built from.
     * @param int $groupId
     * @return string
     */
    #[\NoDiscard('the built path is the only thing this call produces')]
    public function group(int $groupId): string
    {
        return sprintf(
            EndpointEnum::STATUS_GROUP->value,
            rawurlencode((string)$this->config->getId()),
            $groupId,
            $this->sign((string)$groupId)
        );
    }

    /**
     * The status of every segment of one message, chapter 9.4.1. The identifier is the uuid the
     * send answered with and the call carries no signature.
     * @param string $uuid
     * @return string
     * @throws RequestException
     */
    #[\NoDiscard('the built path is the only thing this call produces')]
    public function one(string $uuid): string
    {
        if ('' === $uuid) {
            throw new RequestException('Message identifier not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        return sprintf(EndpointEnum::STATUS_ONE->value, rawurlencode($uuid));
    }

    /**
     * @param string $data
     * @return string
     */
    private function sign(string $data): string
    {
        return Signature::calc((string)$this->config->getKey(), $data);
    }
}
