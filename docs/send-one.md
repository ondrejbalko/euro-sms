# Send one

`sendOne()` sends one message to one number. It is the transactional send: an order confirmation,
a verification code, a password reset — one recipient, one text, one answer to read.

Endpoint `POST /api/v3/send/one`, chapter 9.2. In test mode `POST /api/v3/test/one`.

## The shortest thing that works

On a `$service` built as in [Configuration](configuration.md), and a `Message` as in
[The message](message.md):

```php
$message = new Message;
$message->setSenderName('MyShop');
$message->setContent('Your code is 4471.');
$message->setRecipient(new Recipient('0901 000 000'));

$result = $service->sendOne($message);

$result->getSent(); // the number, with the identifiers of its segments
```

## The whole thing, errors and all

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use DateTime;
use DateTimeZone;
use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Exception\SendException;

$config = new Config;
$config->setId(getenv('EUROSMS_ID') ?: '');
$config->setKey(getenv('EUROSMS_KEY') ?: '');
$config->setTestMode(true);

try {
    $service = new EuroSmsService($config);

    $recipient = new Recipient('0901 000 000');
    $recipient->setIdentifier('order-4471'); // repeated back on every delivery report

    $message = new Message;
    $message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
    $message->setSenderName('MyShop');
    $message->setContent('Your order 4471 has been dispatched.');
    $message->setRecipient($recipient);
    $message->setTtl(3600);                                  // give it up after an hour
    $message->setScheduleDateTime(new DateTime('+10 minutes'));
    $message->getFlag()->addReceipt();                       // ask for a delivery receipt

    $result = $service->sendOne($message);

    foreach ($result->getSent() as $requestId => $entries) {
        foreach ($entries as $entry) {
            // ['number' => 421901000000, 'uuid' => ['55b4…', 'c8a8…'], 'viber' => []]
            printf("%s: %s\n", $entry['number'], implode(', ', $entry['uuid']));
        }
    }

    foreach ($result->getFailed() as $entries) {
        foreach ($entries as $entry) {
            // ['number' => …, 'error' => ['WRONG_SENDER' => 'Wrong sender name']]
            printf("%s refused: %s\n", $entry['number'], implode(', ', $entry['error']));
        }
    }

    foreach ($result->getWarnings() as $warning) {
        printf("%s\n", $warning); // over four segments, and charged for every one of them
    }
} catch (ConfigException $e) {
    // the id or the key is missing — nothing was sent
} catch (RecipientException $e) {
    // the number could not be read, ERROR_WRONG_NUMBER
} catch (MessageException $e) {
    // the sender name or the text was refused, see docs/errors.md for the code
} catch (RequestException $e) {
    // the request could not be built out of the message
} catch (SendException $e) {
    // the call did not go through at all
    $e->getHttpStatus();   // 500, or null when the gateway was never reached
    $e->getResponseBody(); // the refusal as the gateway stated it, undecoded
}
```

A caller that only needs to know whether the message went out catches the one ancestor instead:

```php
use EuroSms\Exception\EuroSmsException;

try {
    $result = $service->sendOne($message);
} catch (EuroSmsException $e) {
    error_log('SMS not sent: ' . $e->getMessage());
}
```

## What comes back

`sendOne()` answers with a `ResultOne`. The number lands in exactly one of three lists, all of them
keyed by the identifier of the request it belonged to.

| List           | When                                           | The entry                                     |
|----------------|------------------------------------------------|-----------------------------------------------|
| `getSent()`    | the gateway enqueued the message               | `number`, `uuid`, `viber`                     |
| `getFailed()`  | the gateway answered and refused it            | `number`, `error`                             |
| `getDenied()`  | the call never got an answer at all            | `number`, `reason`                            |

```php
$result->getSent();     // ['9f3a…' => [['number' => 421901000000, 'uuid' => [...], 'viber' => []]]]
$result->getGroupIds(); // [] — a single message is filed under no group
$result->getMessage();  // the Message that was sent
$result->hasWarnings();
```

`uuid` holds one identifier per segment: a text of three segments comes back with three of them,
and each is asked after on its own in [Delivery status](delivery-status.md). `viber` names the
segments that went out over Viber, see [Viber](viber.md).

**`number` is the digits of the number**, the same value a one-to-many or many-to-many send reports
it as. It used to be the string as it was handed over — `'0901 000 000'` where that is what was
typed — so the one key carried a string here and an int everywhere else, and a strict comparison
against your own records matched for a single send and silently missed for the rest. The number as
it was written stays readable on the recipient itself:

```php
$message->getRecipient()->getNumberOrig(); // '0901 000 000', exactly as it was handed over
```

## Reading the answer itself

```php
$responses = $result->getResponseCollection();

foreach ($responses->all() as $requestId => $response) {
    $response->isSent();          // the verdict as a bool
    $response->getSendStatus();   // a SendStatusEnum case, or null when no code was stated
    $response->getErrors();       // ['NO_BALANCE' => 'The account has no credit left']
    $response->getBody();         // the answer decoded, exactly as it arrived
}
```

`$config->setResponseFormat(ResponseFormatEnum::FULL)` decides how much the gateway writes back
here — see [Configuration](configuration.md).

## What goes on from here

* [The message](message.md) — everything the message itself can be given
* [Send one to many](send-one-to-many.md) — the same text to a list of numbers
* [Errors](errors.md) — every exception above, with its codes
