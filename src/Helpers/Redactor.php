<?php

declare(strict_types=1);

namespace EuroSms\Helpers;

use EuroSms\Enums\EndpointEnum;
use EuroSms\Enums\FieldEnum;

/**
 * What is taken out of a call before it is written to a log. The integration key and the digital
 * signature of chapter 4.2 of SMS API v3.1.15 are what a request is authenticated by, so neither
 * belongs in a file somebody else gets to read: the signature travels in the body of a send under
 * "sgn" and in the path of a bulk status call as its last segment. The key never travels at all,
 * it only signs, and it is masked all the same, so that a value reaching the log by some other
 * route is caught here rather than noticed in the log afterwards.
 */
final readonly class Redactor
{
    /**
     * What a masked value is written as. It says that a value was there without saying which.
     */
    public const string MASK = '***';

    /**
     * The paths whose last segment is a signature, chapters 9.4.3 and 9.4.4. The status of one
     * message is asked without a signature, chapter 9.4.1, so it is not among them.
     * @var list<EndpointEnum>
     */
    private const array SIGNED_ENDPOINTS = [EndpointEnum::STATUS_ANY, EndpointEnum::STATUS_GROUP];

    /**
     * @param string $key the integration key the requests are signed with
     */
    public function __construct(private string $key)
    {
    }

    /**
     * The body of a request with everything secret masked out. A transaction carries a signature
     * of its own for every message it holds, chapter 9.3.8, so the whole structure is walked and
     * not only the level the root signature sits on.
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    #[\NoDiscard('the masked body is the only thing this call produces')]
    public function data(array $data): array
    {
        $redacted = [];

        foreach ($data as $field => $value) {
            $redacted[$field] = match (true) {
                FieldEnum::SIGN->value === $field => self::MASK,
                is_array($value) => $this->data($value),
                $this->key === $value => self::MASK,
                default => $value
            };
        }

        return $redacted;
    }

    /**
     * The path a call is made at, with the signature of a bulk status call masked. The path of a
     * send carries none, chapter 9.3, and neither does the status of one message, so both come
     * back as they were.
     * @param string $path
     * @return string
     */
    #[\NoDiscard('the masked path is the only thing this call produces')]
    public function path(string $path): string
    {
        foreach (self::SIGNED_ENDPOINTS as $endpoint) {
            $placeholder = strpos($endpoint->value, '%s');

            if (false === $placeholder || !str_starts_with($path, substr($endpoint->value, 0, $placeholder))) {
                continue;
            }

            $separator = strrpos($path, '/');

            return false === $separator ? self::MASK : substr($path, 0, $separator + 1) . self::MASK;
        }

        return $path;
    }
}
