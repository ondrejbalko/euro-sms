<?php

namespace EuroSms\Gateway\Result;

use EuroSms\Exception\RecipientException;
use EuroSms\Gateway\Request\RequestOne;
use EuroSms\Gateway\Response\ResponseInterface;

class ResultOne extends ResultAbstract implements ResultInterface
{
    /**
     * @return void
     * @throws RecipientException
     */
    protected function parse(): void
    {
        /** @var RequestOne $request */
        foreach ($this->requestCollection->all() as $request) {
            /** @var ResponseInterface|null $response */
            $response = $this->responseCollection->offsetGet($request->getId());

            if (null === $response) {
                $this->denied[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberOrig()
                ];
            } elseif ($response->isSent()) {
                $this->sent[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberOrig(),
                    'uuid' => $response->getBody()['uuid']
                ];
            } else {
                $this->failed[$request->getId()][] = [
                    'number' => $this->message->getRecipient()->getNumberOrig(),
                    'error' => array_column($response->getBody()['err_list'], 'err_desc', 'err_code')
                ];
            }
        }
    }
}
