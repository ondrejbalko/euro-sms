<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Response;

class ResponseOneToMany extends ResponseAbstract implements ResponseInterface
{
    /**
     * @return int|null
     */
    #[\Override]
    public function getGroupId(): ?int
    {
        return isset($this->getBody()['group_id']) && is_numeric($this->getBody()['group_id']) ? (int)$this->getBody()['group_id'] : null;
    }
}
