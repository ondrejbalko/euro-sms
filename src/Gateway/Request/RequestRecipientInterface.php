<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Exception\RecipientException;

/**
 * A request addressed to exactly one recipient, and so the only kind of request that has a single
 * recipient to hand out at all. Chapter 9.3.1 of SMS API v3.1.15 writes that one under "rcpt"; a
 * request naming a whole list of numbers writes them under "rcpts" and does not implement this,
 * rather than answering for a recipient it does not have.
 */
interface RequestRecipientInterface extends RequestInterface
{
    /**
     * @return Recipient
     * @throws RecipientException when the request was never given a recipient
     */
    public function getRecipient(): Recipient;

    /**
     * @param Recipient $recipient
     * @return void
     */
    public function setRecipient(Recipient $recipient): void;
}
