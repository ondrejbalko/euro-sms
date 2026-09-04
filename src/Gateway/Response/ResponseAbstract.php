<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Response;

use EuroSms\Enums\SendStatusEnum;

abstract class ResponseAbstract
{
    /** @var array<string, mixed> $body */
    protected array $body;

    /** @var array<string, string> $errors */
    protected array $errors = [];

    /** @var bool $sent */
    protected bool $sent = false;

    /** @var SendStatusEnum|null $status */
    protected ?SendStatusEnum $status = null;

    /**
     * @param string $requestId
     * @param \Psr\Http\Message\ResponseInterface $clientResponse
     */
    public function __construct(protected string $requestId, protected \Psr\Http\Message\ResponseInterface $clientResponse)
    {
        $this->body = [];

        $decoded = json_decode($this->clientResponse->getBody()->__toString(), true);

        if (is_array($decoded)) {
            foreach ($decoded as $field => $value) {
                $this->body[(string)$field] = $value;
            }
        }

        $hasStatus = array_key_exists('err_code', $this->body);
        $hasAccepted = array_key_exists('accepted', $this->body);

        if ($hasStatus) {
            $code = $this->body['err_code'];
            $this->status = SendStatusEnum::fromCode(is_scalar($code) ? (string)$code : null);
            $this->sent = $this->status->isEnqueued();
        }

        /**
         * The basic answer of a group send carries no error code at all, chapters 9.3.4.1 and
         * 9.3.10, only the tally of what got through. It went through when at least one message
         * was accepted.
         *
         * The tally is read whether or not a code stands next to it. Chapter 9.3.6 answers a
         * request it enqueued and still names the numbers it refused, so a code that is a refusal
         * and an "accepted" list that is not empty stand side by side in one answer; reading only
         * the code there said the whole request was unsent, and the numbers the gateway had
         * enqueued — and will charge for — were reported to the caller as failed.
         */
        if ($hasAccepted) {
            $accepted = $this->body['accepted'];
            $this->sent = $this->sent
                || (is_numeric($accepted) ? 0 < (int)$accepted : is_array($accepted) && [] !== $accepted);
        }

        if (!$hasStatus && !$hasAccepted) {
            $this->errors['NO_RESPONSE'] = 'Something went wrong, try again';
        }

        $this->setErrors();
    }

    /**
     * The gateway lists what went wrong as pairs of a code and its description, chapter 11 of
     * SMS API v3.1.15. Anything shaped differently is not an error the caller could act on.
     * @return void
     */
    private function setErrors(): void
    {
        $errors = $this->body['err_list'] ?? [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (!is_array($error) || !isset($error['err_code'], $error['err_desc'])) {
                    continue;
                }

                $code = $error['err_code'];
                $description = $error['err_desc'];

                if (is_scalar($code) && is_scalar($description)) {
                    $this->errors[(string)$code] = (string)$description;
                }
            }
        }

        $this->setRefusal();
    }

    /**
     * A single send states its refusal in the answer itself and lists nothing beside it, chapter
     * 9.3.2: "err_code" with "err_desc" next to it and no "err_list" at all. Reading only the
     * list left such an answer with no reason to report, so the caller was handed a message that
     * failed for nothing anybody could name while the reason sat in the body unread.
     *
     * Only a refusal is read this way. The code an accepted message carries is the acceptance
     * itself, chapter 13.1, and an error is not what it says. Which of the two the code is is
     * asked of the code and not of the send: chapter 9.3.6 enqueues part of a request and refuses
     * the rest of it with a code of its own, so a send that partly got through still arrived with
     * a refusal to name, and asking whether anything was sent left it unnamed.
     * @return void
     */
    private function setRefusal(): void
    {
        if ([] !== $this->errors || null === $this->status || $this->status->isEnqueued()) {
            return;
        }

        $code = $this->body['err_code'] ?? null;

        if (!is_scalar($code)) {
            return;
        }

        $description = $this->body['err_desc'] ?? null;

        $this->errors[(string)$code] = is_scalar($description) && '' !== (string)$description
            ? (string)$description
            : $this->status->getDescription();
    }

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getClientResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->clientResponse;
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * The verdict of chapter 13.1 the whole request was answered with, or null when the answer
     * stated no code at all, which is what a group send does, chapters 9.3.4.1 and 9.3.10. The
     * code as it arrived stays readable in the body next to it.
     * @return SendStatusEnum|null
     */
    public function getSendStatus(): ?SendStatusEnum
    {
        return $this->status;
    }

    /**
     * @return bool
     */
    public function isSent(): bool
    {
        return $this->sent;
    }
}
