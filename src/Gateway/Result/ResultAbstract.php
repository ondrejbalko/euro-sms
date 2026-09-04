<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Result;

use EuroSms\Gateway\GatewayInterface;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Request\RequestInterface;
use EuroSms\Gateway\Response\ResponseCollection;

/**
 * What every result has in common: the requests that were built, the answers that came back and the
 * three lists the caller reads them through. What was actually sent differs between a single message
 * and a whole collection of them, so it is left to the classes below.
 *
 * @phpstan-type ResultEntry array<string, array<int|string, string>|int|string|null>
 * @phpstan-type ResultList array<string, array<int, ResultEntry>>
 */
abstract class ResultAbstract
{
    /** @var ResultList $denied */
    protected array $denied = [];

    /** @var ResultList $failed */
    protected array $failed = [];

    /** @var ResultList $sent */
    protected array $sent = [];

    /**
     * @param RequestCollection $requestCollection
     * @param ResponseCollection $responseCollection
     */
    public function __construct(
        protected RequestCollection $requestCollection,
        protected ResponseCollection $responseCollection
    ) {
        $this->parse();
    }

    /**
     * @return ResultList
     */
    public function getDenied(): array
    {
        return $this->denied;
    }

    /**
     * @return ResultList
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * The groups the gateway filed this send under, chapters 9.3.4.1 and 9.3.11, each of them
     * once. A group is what the delivery status of a whole send is asked by, and a send too big
     * for one transaction was split into several, so there can be more than one of them. A send
     * that belongs to no group at all, a single message, was filed under none.
     * @return list<int>
     */
    public function getGroupIds(): array
    {
        $groupIds = [];

        foreach ($this->responseCollection->all() as $response) {
            $groupId = $response->getGroupId();

            if (null !== $groupId && !in_array($groupId, $groupIds, true)) {
                $groupIds[] = $groupId;
            }
        }

        return $groupIds;
    }

    /**
     * @return RequestCollection
     */
    public function getRequestCollection(): RequestCollection
    {
        return $this->requestCollection;
    }

    /**
     * @return ResponseCollection
     */
    public function getResponseCollection(): ResponseCollection
    {
        return $this->responseCollection;
    }

    /**
     * @return ResultList
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * What was worth telling the caller about this send without refusing it, gathered from every
     * request it was split into. A text over the four segments chapter 9.2.2 recommends is the
     * one thing that lands here: chapter 14.1 says the gateway takes it and charges every part,
     * so the send goes through and the caller is told rather than stopped. The same thing said
     * about two requests is said once.
     * @return list<string>
     */
    public function getWarnings(): array
    {
        $warnings = [];

        foreach ($this->requestCollection->all() as $request) {
            foreach ($request->getWarnings() as $warning) {
                if (!in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    /**
     * Whether there is anything to tell the caller about this send. It is the same question a
     * request answers, asked of the whole result, so that a caller reading the two together does
     * not have to build the list only to find out that it is empty.
     * @return bool
     */
    public function hasWarnings(): bool
    {
        foreach ($this->requestCollection->all() as $request) {
            if ($request->hasWarnings()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an identifier belongs to a Viber message, chapter 9.3.11: those carry the "v:"
     * prefix, an SMS identifier carries no prefix at all.
     * @param string $identifier
     * @return bool
     */
    public static function isViberIdentifier(string $identifier): bool
    {
        return str_starts_with($identifier, GatewayInterface::VIBER_IDENTIFIER_PREFIX);
    }

    /**
     * A batch that never reached the gateway: every number it carried is denied, and every one of
     * them carries the reason the call did not go through. Nothing about such a batch was sent, so
     * none of its numbers belongs among the failed ones — those were refused by the gateway, and
     * this one was never put to it.
     *
     * The reason is what makes the entry worth reading. A batch of a thousand numbers reported as
     * denied and nothing else left the caller to guess between a refusal, a timeout and a
     * certificate nobody renewed.
     * @param RequestInterface $request
     * @return void
     */
    protected function denyRequest(RequestInterface $request): void
    {
        $reason = $this->getFailureReason($request->getId());

        foreach ($request->getRecipients() as $number) {
            $this->denied[$request->getId()][] = [
                'number' => $number,
                'reason' => $reason
            ];
        }
    }

    /**
     * The entries of a list the gateway answered with, none of them when it answered with
     * something else. Nothing about a body that came off the wire is taken on trust.
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    protected function getEntries(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Why the request under this identifier was never answered, none when it was answered at all
     * or when the transport named no reason.
     * @param string $requestId
     * @return string|null
     */
    protected function getFailureReason(string $requestId): ?string
    {
        return $this->responseCollection->getFailure($requestId)?->getMessage();
    }

    /**
     * A message is split into segments and every one of them is given an identifier of its own,
     * so what identifies a sent message is a list and never a single value.
     * @param mixed $value
     * @return array<int, string>
     */
    protected function getIdentifiers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $identifiers = [];

        foreach ($value as $identifier) {
            if (is_scalar($identifier)) {
                $identifiers[] = (string)$identifier;
            }
        }

        return $identifiers;
    }

    /**
     * The number an entry of the answer speaks about, none when it names none.
     * @param array<string, mixed> $entry
     * @return int|string|null
     */
    protected function getNumber(array $entry): int|string|null
    {
        $number = $entry['r'] ?? null;

        return is_int($number) || is_string($number) ? $number : null;
    }

    /**
     * The identifiers of the segments that went out over Viber, chapter 9.3.11. They are handed
     * over next to the whole list rather than taken out of it, so a caller that does not care
     * which way a segment travelled still reads every identifier in one place.
     * @param mixed $value
     * @return array<int, string>
     */
    protected function getViberIdentifiers(mixed $value): array
    {
        return array_values(array_filter($this->getIdentifiers($value), self::isViberIdentifier(...)));
    }

    /**
     * @return void
     */
    abstract protected function parse(): void;
}
