<?php

namespace EuroSms\Gateway\Response;

class ResponseOne extends ResponseAbstract implements ResponseInterface
{
    /**
     * @return int|null
     */
    public function getGroupId(): ?int
    {
        return null;
    }
}
