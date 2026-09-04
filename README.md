# EuroSMS

[EuroSMS](https://www.eurosms.com/) is an SMS gateway operating in Slovakia. This is a PHP client
for its REST interface, written against **SMS API v3.1.15** — the specification EuroSMS publishes
for the gateway, and the document every chapter number in this documentation points at.

The library sends one message, one message to many numbers, or many messages in one transaction;
it asks for delivery statuses, takes CallBack notifications apart, and carries a Viber variant of a
message where one is wanted.

## Requirements

* PHP 8.5 or newer
* the `json` and `mbstring` extensions

The library is written against PHP 8.5 and uses its syntax, so it cannot be installed on an older
runtime.

## Installation

```shell
composer require ondrejbalko/euro-sms
```

## Quick start

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;
use EuroSms\Exception\EuroSmsException;

try {
    $config = new Config;
    $config->setId(getenv('EUROSMS_ID') ?: '');
    $config->setKey(getenv('EUROSMS_KEY') ?: '');
    $config->setTestMode(true); // nothing is charged and nothing is delivered

    $service = new EuroSmsService($config);

    $message = new Message;
    $message->setSenderName('MyShop');
    $message->setContent('Your order is on its way.');
    $message->setRecipient(new Recipient('0901 000 000'));

    $result = $service->sendOne($message);

    var_dump($result->getSent());   // the numbers the gateway took
    var_dump($result->getFailed()); // the ones it refused, with the reason
} catch (EuroSmsException $e) {
    // everything this library throws descends from EuroSmsException
    error_log($e->getMessage());
}
```

Turn `setTestMode(false)` off only once the answer looks the way it should — in test mode the
gateway answers exactly as it would, and delivers nothing.

## Documentation

| Document                                       | What is in it                                                             |
|------------------------------------------------|---------------------------------------------------------------------------|
| [Configuration](docs/configuration.md)          | credentials, test mode, timeouts, TLS, concurrency, PSR-18 and PSR-17      |
| [The message](docs/message.md)                  | sender, text, GSM 03.38 and segments, flags, scheduling, time to live      |
| [Recipients](docs/recipients.md)                | numbers, E.164, country, a key of your own on a recipient                  |
| [Send one](docs/send-one.md)                    | `sendOne()` — one message to one number                                    |
| [Send one to many](docs/send-one-to-many.md)    | `sendOneToMany()` — one text to a list of numbers                          |
| [Send many to many](docs/send-many-to-many.md)  | `sendManyToMany()` — many messages in one transaction                      |
| [Viber](docs/viber.md)                          | a Viber variant of a message and what happens when it does not get through |
| [Delivery status](docs/delivery-status.md)      | `getStatusOne()`, `getStatusGroup()`, `getStatusAny()`                     |
| [CallBack notifications](docs/callback.md)      | receipts and incoming messages pushed to an endpoint of yours              |
| [Logging](docs/logging.md)                      | what is written into a PSR-3 logger, and what is masked out of it          |
| [Errors](docs/errors.md)                        | every exception, every error code, and what to do about each               |
| [Using it elsewhere](docs/integration.md)       | plain PHP, Symfony, Laravel, Nette, and sending from a queue               |
| [Upgrading to 2.0](docs/upgrading-2.0.md)       | what changed since `1.x` and how to move                                   |

The version history and the full list of incompatible changes are in [CHANGELOG.md](CHANGELOG.md).

## Checks

```shell
composer phpstan
composer phpunit
```

## License

GPL-3.0-only. See [LICENSE](LICENSE).
