<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Response;

class ResponseOne extends ResponseAbstract implements ResponseInterface
{
    /**
     * @return int|null
     */
    #[\Override]
    public function getGroupId(): ?int
    {
        return null;
    }
}
