<?php

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\CollectionAbstract;

/**
 * @method RequestInterface[] all()
 * @method RequestInterface[] jsonSerialize()
 * @method RequestInterface|null offsetGet(string|int $offset)
 * @method offsetSet(string|int|null $offset, RequestInterface $value)
 * @method offsetUnset(string|int $offset)
 */
class RequestCollection extends CollectionAbstract
{
}
