<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Result;

class ResultOneToMany extends ResultMessageAbstract implements ResultInterface
{
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

            /**
             * Chapter 9.3.6: a request the gateway enqueued may still name numbers it refused, so
             * the refused ones are read whether or not the request as a whole got through. Reading
             * them only out of a refused answer would lose them: a partly accepted send would
             * report them as neither sent nor denied.
             *
             * The reason is carried even here, where the gateway names none of its own: every
             * entry of the denied list is shaped alike, so a caller reading it with array_column()
             * gets as many reasons as it gets numbers instead of silently fewer.
             *
             * What was refused is remembered as digits and not as it arrived. A number comes off
             * the wire as whatever JSON carried it, an int or a string, chapter 9.3.6 printing it
             * unquoted and nothing making the gateway keep to that, while the request holds its
             * own numbers as ints. Comparing the two as they stand told a string apart from the
             * int of the same number, so a refused number was reported as denied and once more as
             * failed, and a caller reconciling numbers counted it twice.
             */
            $denied = [];

            foreach ($this->getEntries($response->getBody()['wrong_numbers'] ?? null) as $item) {
                $number = $this->getNumber($item);

                if (null !== $number) {
                    $denied[] = (string)$number;
                }

                $this->denied[$request->getId()][] = [
                    'number' => $number,
                    'reason' => null
                ];
            }

            if ($response->isSent()) {
                foreach ($this->getEntries($response->getBody()['accepted'] ?? null) as $item) {
                    $this->sent[$request->getId()][] = [
                        'number' => $this->getNumber($item),
                        'uuid' => $this->getIdentifiers($item['i'] ?? null),
                        'viber' => $this->getViberIdentifiers($item['i'] ?? null)
                    ];
                }

                continue;
            }

            /**
             * The whole request was refused, so there is no verdict per number to read and every
             * number of the request shares the error the gateway answered with — the same way a
             * refused transaction reports its own. Reporting the error without the numbers left a
             * caller reconciling numbers to outcomes with every recipient of a refused request
             * missing, while the same caller reading a single send or a transaction got them.
             *
             * A number the answer already named as wrong is reported as denied and not a second
             * time here.
             */
            $errors = $response->getErrors();

            foreach ($request->getRecipients() as $number) {
                if (in_array((string)$number, $denied, true)) {
                    continue;
                }

                $this->failed[$request->getId()][] = [
                    'number' => $number,
                    'error' => $errors
                ];
            }
        }
    }
}
