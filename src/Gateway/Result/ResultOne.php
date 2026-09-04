<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Result;

use EuroSms\Exception\RecipientException;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Response\ResponseInterface;

/**
 * The result of sending one message to one number, chapter 9.3.2 of SMS API v3.1.15.
 *
 * The number is reported as its digits, the way every other result reports one. It used to be
 * handed back as the caller wrote it — "0900 123 456" where a one-to-many send of the same number
 * reported 421900123456 — so one key of the three lists carried a string here and an int there,
 * and a caller matching the entries against its own records with a strict comparison matched for
 * one kind of send and silently missed for the others. The number as it was written stays readable
 * on the recipient itself.
 */
class ResultOne extends ResultMessageAbstract implements ResultInterface
{
    /**
     * @return void
     * @throws RecipientException
     */
    #[\Override]
    protected function parse(): void
    {
        /** @var RequestOne $request */
        foreach ($this->requestCollection->all() as $request) {
            /** @var ResponseInterface|null $response */
            $response = $this->responseCollection->offsetGet($request->getId());

            if (null === $response) {
                $this->denied[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberClean(),
                    'reason' => $this->getFailureReason($request->getId())
                ];
            } elseif ($response->isSent()) {
                $this->sent[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberClean(),
                    'uuid' => $this->getIdentifiers($response->getBody()['uuid'] ?? null),
                    'viber' => $this->getViberIdentifiers($response->getBody()['uuid'] ?? null)
                ];
            } else {
                $this->failed[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberClean(),
                    'error' => $response->getErrors()
                ];
            }
        }
    }
}
