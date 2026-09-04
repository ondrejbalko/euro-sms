<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Status;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Psr\Http\Message\ResponseInterface;
use Traversable;

/**
 * What the three delivery status operations of chapter 9.4 of SMS API v3.1.15 answered with.
 *
 * The two answers are shaped differently: status/one is a bare list of reports, chapter 9.4.2,
 * while status/group and status/any wrap the same reports into an envelope carrying dlrs, count
 * and err_code, chapter 9.4.5. Both are read here into the one list the caller iterates over.
 *
 * Asked about something the gateway knows nothing about, an unknown identifier or a group whose
 * statuses have not changed since the last call, it answers with no reports rather than with an
 * error, so an empty result is an answer and not a failure.
 *
 * @implements IteratorAggregate<int, DeliveryReport>
 */
class StatusResponse implements Countable, IteratorAggregate
{
    /** @var array<int|string, mixed> $body */
    protected array $body = [];

    /** @var int|null $count */
    protected ?int $count = null;

    /** @var string|null $errorCode */
    protected ?string $errorCode = null;

    /** @var list<DeliveryReport> $reports */
    protected array $reports = [];

    /**
     * @param ResponseInterface $clientResponse
     */
    public function __construct(protected ResponseInterface $clientResponse)
    {
        $decoded = json_decode($this->clientResponse->getBody()->__toString(), true);

        if (!is_array($decoded)) {
            return;
        }

        $this->body = $decoded;

        /**
         * The envelope of a group answer names its list; a status/one answer is the list itself.
         */
        $reports = array_key_exists('dlrs', $decoded) ? $decoded['dlrs'] : $decoded;

        if (is_array($reports)) {
            foreach ($reports as $report) {
                if (is_array($report)) {
                    /** @var array<string, mixed> $report */
                    $this->reports[] = DeliveryReport::fromArray($report);
                }
            }
        }

        if (is_numeric($decoded['count'] ?? null)) {
            $this->count = (int)$decoded['count'];
        }

        if (is_scalar($decoded['err_code'] ?? null)) {
            $this->errorCode = (string)$decoded['err_code'];
        }
    }

    /**
     * How many reports the answer actually carried.
     * @return int
     */
    #[\Override]
    public function count(): int
    {
        return count($this->reports);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @return ResponseInterface
     */
    public function getClientResponse(): ResponseInterface
    {
        return $this->clientResponse;
    }

    /**
     * The tally the envelope of a group answer stated, none when the answer carried no envelope.
     * What was really read is what count() says.
     * @return int|null
     */
    public function getCount(): ?int
    {
        return $this->count;
    }

    /**
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return Traversable<int, DeliveryReport>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->reports);
    }

    /**
     * @return list<DeliveryReport>
     */
    public function getReports(): array
    {
        return $this->reports;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return [] === $this->reports;
    }
}
