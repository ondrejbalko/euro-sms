<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

/**
 * The digital signature of a request, chapter 4.2 of SMS API v3.1.15: the values it is built
 * from are joined into one continuous string without separators and signed with the integration
 * key. The gateway detects the algorithm itself and reads the result case insensitively.
 */
final class Signature
{
    /**
     * The algorithm the gateway accepts alongside HMAC_SHA256, chapter 4.2.
     */
    private const string ALGORITHM = 'sha1';

    /**
     * @param string $key the integration key
     * @param string|int ...$data the values the signature is built from, in the documented order
     * @return string
     */
    #[\NoDiscard('the signature is the only thing this call produces')]
    public static function calc(string $key, string|int ...$data): string
    {
        return hash_hmac(self::ALGORITHM, implode('', $data), $key);
    }
}
