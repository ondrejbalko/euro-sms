<?php

namespace EuroSms\Gateway\Response;

interface ResponseInterface
{
    /**
     * @return array<string, array<int, string>>
     */
    public function getBody(): array;

    /**
     * @return int|null
     */
    public function getGroupId(): ?int;

    /**
     * @return string
     */
    public function getRequestId(): string;

    /**
     * @return bool
     */
    public function isSent(): bool;
}
