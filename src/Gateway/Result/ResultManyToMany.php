<?php

namespace EuroSms\Gateway\Result;

use EuroSms\Entities\Message\MessageCollection;
use EuroSms\Gateway\GatewayInterface;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Response\ResponseCollection;

class ResultManyToMany extends ResultAbstract implements ResultInterface
{
    /**
     * @param MessageCollection $messages
     * @param RequestCollection $requestCollection
     * @param ResponseCollection $responseCollection
     */
    public function __construct(
        protected MessageCollection $messages,
        protected RequestCollection $requestCollection,
        protected ResponseCollection $responseCollection,
    ) {
    }

    /**
     * @return void
     */
    protected function parse(): void
    {
        foreach ($this->requestCollection->all() as $request) {
            $response = $this->responseCollection->offsetGet($request->getId());

            if (null === $response) {
                $this->denied[$request->getId()][] = [
                    'number' => $request->getRecipient()->getNumberOrig()
                ];
            } else {
                if (isset($response->getBody()['err_list'])) {
                    /** @var array<string, string> $item */
                    foreach ($response->getBody()['err_list'] as $item) {
                        $this->failed[$request->getId()][] = [
                            'error' => array_column($item, 'err_desc', 'err_code')
                        ];
                    }
                }

                if ($response->isSent()) {
                    /** @var array<string, array<int, string>|int|string> $item */
                    foreach ($response->getBody()['result'] as $item) {
                        if ($item['e'] === GatewayInterface::RESPONSE_ENQUEUED) {
                            $this->sent[$request->getId()][] = [
                                'number' => $item['r'],
                                'uuid' => $item['i']
                            ];
                        } else {
                            $this->failed[$request->getId()][] = [
                                'number' => $item['r'],
                                'error' => $item['e']
                            ];
                        }
                    }
                }
            }
        }
    }
}
