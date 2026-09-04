<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Response;

use EuroSms\Enums\SendStatusEnum;

interface ResponseInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getBody(): array;

    /**
     * @return array<string, string>
     */
    public function getErrors(): array;

    /**
     * @return int|null
     */
    public function getGroupId(): ?int;

    /**
     * @return string
     */
    public function getRequestId(): string;

    /**
     * @return SendStatusEnum|null
     */
    public function getSendStatus(): ?SendStatusEnum;

    /**
     * @return bool
     */
    public function isSent(): bool;
}
