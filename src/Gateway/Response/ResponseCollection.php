<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Response;

use EuroSms\Entities\CollectionAbstract;
use EuroSms\Exception\SendException;

/**
 * The answers of one send, filed under the request each of them answers.
 *
 * A batch that never came back has no answer to file and used to leave nothing behind at all, so
 * the result could tell that a request went unanswered but not why. The failures are kept next to
 * the answers rather than among them: a failure is not a body the gateway sent, so counting the
 * collection, serialising it or reading it out still gives the answers alone.
 *
 * @method ResponseInterface[] all()
 * @method ResponseInterface[] jsonSerialize()
 * @method ResponseInterface|null offsetGet(string|int $offset)
 * @method offsetSet(string|int|null $offset, ResponseInterface $value)
 * @method offsetUnset(string|int $offset)
 */
class ResponseCollection extends CollectionAbstract
{
    /** @var array<string, SendException> $failures how a request failed, by request id */
    private array $failures = [];

    /**
     * @param string $requestId
     * @param SendException $failure
     * @return void
     */
    public function addFailure(string $requestId, SendException $failure): void
    {
        $this->failures[$requestId] = $failure;
    }

    /**
     * How this request failed, none when it was answered at all.
     *
     * The failure is kept whole rather than reduced to its message. A batch the gateway refused
     * with 400 or worse states the refusal in the body of that answer, chapter 11, and that body
     * used to travel out on the exception a one-batch send threw; a send of several batches
     * throws nothing while the others go through, so this is where the same thing is read from.
     * @param string $requestId
     * @return SendException|null
     */
    public function getFailure(string $requestId): ?SendException
    {
        return $this->failures[$requestId] ?? null;
    }

    /**
     * @return array<string, SendException>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * Why each request that failed did, in words, keyed the same way the failures themselves are.
     * It is what a caller writing a line into a log of its own asks for, and it saves it walking
     * the exceptions to get there.
     * @return array<string, string>
     */
    public function getReasons(): array
    {
        return array_map(static fn (SendException $failure): string => $failure->getMessage(), $this->failures);
    }

    /**
     * @return bool
     */
    public function hasFailures(): bool
    {
        return [] !== $this->failures;
    }
}
