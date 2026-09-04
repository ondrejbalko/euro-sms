<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Result;

use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Enums\SendStatusEnum;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Response\ResponseCollection;
use EuroSms\Gateway\Response\ResponseInterface;

/**
 * The answer to a group transaction reports every number on its own, chapter 9.3.11 of
 * SMS API v3.1.15: an enqueued number carries the identifiers of its segments, a refused one
 * carries the code that refused it. The group the transaction was filed under is repeated on
 * every entry, so a caller reading a single list still knows which group it belongs to.
 */
class ResultManyToMany extends ResultAbstract implements ResultInterface
{
    /**
     * @param MessageCollection $messageCollection
     * @param RequestCollection $requestCollection
     * @param ResponseCollection $responseCollection
     */
    public function __construct(
        protected MessageCollection $messageCollection,
        RequestCollection $requestCollection,
        ResponseCollection $responseCollection
    ) {
        parent::__construct($requestCollection, $responseCollection);
    }

    /**
     * @return MessageCollection
     */
    public function getMessageCollection(): MessageCollection
    {
        return $this->messageCollection;
    }

    /**
     * @return void
     */
    #[\Override]
    protected function parse(): void
    {
        foreach ($this->requestCollection->all() as $request) {
            $response = $this->responseCollection->offsetGet($request->getId());

            if (null === $response) {
                $this->denyRequest($request);

                continue;
            }

            $result = $this->getEntries($response->getBody()['result'] ?? null);

            /**
             * The whole transaction was refused, so there is no verdict per number to read and
             * every number of the request shares the error the gateway answered with.
             *
             * Whether it was refused is asked of the answer and not of the list. A transaction the
             * gateway enqueued and answered the tally of, chapters 9.3.4.1 and 9.3.10, carries no
             * "result" at all, and reading the missing list as a refusal reported every recipient
             * of an accepted send as failed — with no error beside them either, because an
             * accepted answer names none. Such a send is left to the tally and the group it was
             * filed under, the same way ResultOneToMany leaves an accepted request whose answer
             * lists no numbers: nothing is invented about numbers the gateway did not speak of.
             */
            if ([] === $result) {
                if (!$response->isSent()) {
                    foreach ($request->getRecipients() as $number) {
                        $this->failed[$request->getId()][] = [
                            'number' => $number,
                            'error' => $response->getErrors(),
                            'group_id' => $response->getGroupId()
                        ];
                    }
                }

                continue;
            }

            foreach ($result as $item) {
                $code = $this->getCode($item);
                $number = $this->getNumber($item);

                if (SendStatusEnum::fromCode($code)->isEnqueued()) {
                    $this->sent[$request->getId()][] = [
                        'number' => $number,
                        'uuid' => $this->getIdentifiers($item['i'] ?? null),
                        'viber' => $this->getViberIdentifiers($item['i'] ?? null),
                        'group_id' => $response->getGroupId()
                    ];

                    continue;
                }

                $this->failed[$request->getId()][] = [
                    'number' => $number,
                    'error' => $this->getError($code, $response),
                    'group_id' => $response->getGroupId()
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return string
     */
    private function getCode(array $item): string
    {
        $code = $item['e'] ?? null;

        return is_scalar($code) ? (string)$code : '';
    }

    /**
     * A single number is refused the same way the whole transaction is: by a code, mapped to the
     * description the answer carried for it, so that both lists read alike.
     * @param string $code
     * @param ResponseInterface $response
     * @return array<string, string>
     */
    private function getError(string $code, ResponseInterface $response): array
    {
        if ('' === $code) {
            return [];
        }

        return [$code => $response->getErrors()[$code] ?? $code];
    }

}
