<?php

declare(strict_types=1);

namespace EuroSms\Exception;

use Exception;

/**
 * The one ancestor of everything this library throws. A caller that only needs to know that the
 * call did not go through catches this and is done with it; one that needs to know why still
 * catches the class that says so. The library never throws this class itself, which is why it is
 * abstract: an exception always names what went wrong.
 */
abstract class EuroSmsException extends Exception
{
}
