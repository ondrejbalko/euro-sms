<?php

namespace EuroSms\Gateway\Response;

class ResponseOneToMany extends ResponseAbstract implements ResponseInterface
{
    /**
     * @return int|null
     */
    public function getGroupId(): ?int
    {
        return isset($this->getBody()['group_id']) && is_numeric($this->getBody()['group_id']) ? (int)$this->getBody()['group_id'] : null;
    }
}
