<?php

declare(strict_types=1);

namespace EuroSms\Exception;

use Throwable;

/**
 * A call that did not go through. It comes in two shapes and carries what each of them has: a
 * gateway that answered with 400 or worse states its refusal in the body of that answer, chapter
 * 11 of SMS API v3.1.15, so both the status and the body are handed over; a call that never
 * reached the gateway has neither and says so with null.
 */
class SendException extends EuroSmsException
{
    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     * @param int|null $httpStatus the status the gateway answered with, none when it never did
     * @param string|null $responseBody the body of that answer, as it came off the wire
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $httpStatus = null,
        private readonly ?string $responseBody = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The status of the answer that refused the call. A call that never reached the gateway has
     * none, and the code of the exception is then whatever the transport itself stated.
     * @return int|null
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The body of the answer that refused the call, as it came off the wire and undecoded — the
     * gateway names the error in it, chapter 11, and that is the half a status code cannot say.
     * @return string|null
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
