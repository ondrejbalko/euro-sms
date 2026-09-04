<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Result;

use EuroSms\Entities\Message\Message;
use EuroSms\Gateway\Request\RequestCollection;
use EuroSms\Gateway\Response\ResponseCollection;

/**
 * The result of sending one message, no matter how many recipients it went to and how many
 * requests it had to be split into.
 */
abstract class ResultMessageAbstract extends ResultAbstract
{
    /**
     * @param Message $message
     * @param RequestCollection $requestCollection
     * @param ResponseCollection $responseCollection
     */
    public function __construct(
        protected Message $message,
        RequestCollection $requestCollection,
        ResponseCollection $responseCollection
    ) {
        parent::__construct($requestCollection, $responseCollection);
    }

    /**
     * @return Message
     */
    public function getMessage(): Message
    {
        return $this->message;
    }
}
