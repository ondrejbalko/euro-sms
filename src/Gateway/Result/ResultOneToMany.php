<?php

namespace EuroSms\Gateway\Result;

class ResultOneToMany extends ResultAbstract implements ResultInterface
{
    /**
     * @return void
     */
    protected function parse(): void
    {
        foreach ($this->requestCollection->all() as $request) {
            $response = $this->responseCollection->offsetGet($request->getId());

            if (null === $response) {
                foreach ($request->getRecipients() as $number) {
                    $this->denied[$request->getId()][] = [
                        'number' => $number
                    ];
                }
            } elseif ($response->isSent()) {
                /** @var array<string, array<int, string>|int> $item */
                foreach ($response->getBody()['accepted'] as $item) {
                    $this->sent[$request->getId()][] = [
                        'number' => $item['r'],
                        'uuid' => $item['i']
                    ];
                }
            } else {
                /** @var array<string, int> $item */
                foreach ($response->getBody()['wrong_numbers'] as $item) {
                    $this->denied[$request->getId()][] = [
                        'number' => $item['r']
                    ];
                }

                /** @var array<string, string> $item */
                foreach ($response->getBody()['err_list'] as $item) {
                    $this->failed[$request->getId()][] = [
                        'error' => array_column($item, 'err_desc', 'err_code')
                    ];
                }
            }
        }
    }
}
