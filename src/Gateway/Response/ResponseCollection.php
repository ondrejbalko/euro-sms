<?php

namespace EuroSms\Gateway\Response;

use EuroSms\Entities\CollectionAbstract;

/**
 * @method ResponseInterface[] all()
 * @method ResponseInterface[] jsonSerialize()
 * @method ResponseInterface|null offsetGet(string|int $offset)
 * @method offsetSet(string|int|null $offset, ResponseInterface $value)
 * @method offsetUnset(string|int $offset)
 */
class ResponseCollection extends CollectionAbstract
{
}
