# Viber

A message can carry a Viber variant of its own, chapter 10. The variant travels in an `im` object
next to the SMS text, and the flags decide what the gateway does when Viber does not get through.

| Flag                  | What it does when Viber does not get through           |
|-----------------------|--------------------------------------------------------|
| `addViber()`          | the SMS text goes out instead                          |
| `addViberOnly()`      | the message is thrown away                             |
| `addViberPromo()`     | marks it as a promotional message                      |

**The variant alone turns nothing on.** `setInstantMessage()` attaches it; the flag is what asks
for it, chapter 14.1. A message given a variant and no flag goes out as a plain SMS.

## Sending one

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\Config;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;
use EuroSms\Exception\EuroSmsException;
use EuroSms\Exception\InstantMessageException;

$config = new Config;
$config->setId(getenv('EUROSMS_ID') ?: '');
$config->setKey(getenv('EUROSMS_KEY') ?: '');
$config->setTestMode(true);

try {
    $service = new EuroSmsService($config);

    $variant = new InstantMessage;
    $variant->setSenderName('MyViberName'); // registered with the operator, not the SMS sender
    $variant->setContent('Hello world, up to 1000 characters, diacritics and all!');
    $variant->setTtl(600);                  // 15 seconds to 24 hours

    $message = new Message;
    $message->setSenderName('MyShop');
    $message->setContent('Hello world!');   // what goes out when Viber does not get through
    $message->setRecipient(new Recipient('0901 000 000'));
    $message->setInstantMessage($variant);
    $message->getFlag()->addViber();

    $result = $service->sendOne($message);

    foreach ($result->getSent() as $entries) {
        foreach ($entries as $entry) {
            printf("every segment: %s\n", implode(', ', $entry['uuid']));
            printf("over viber:    %s\n", implode(', ', $entry['viber']));
        }
    }
} catch (InstantMessageException $e) {
    // the variant itself was refused, see the table below
    printf("viber variant refused: %s\n", $e->getMessage());
} catch (EuroSmsException $e) {
    // anything else the library throws
    error_log($e->getMessage());
}
```

## What the variant refuses

Everything is checked where it is set, so nothing half-formed is ever paid for. Each refusal is an
`InstantMessageException` carrying a code of `MessageInterface`.

| Written                        | Code                                    |
|--------------------------------|-----------------------------------------|
| an empty text                  | `ERROR_INSTANT_MESSAGE_TEXT_IS_EMPTY`   |
| over 1000 characters           | `ERROR_INSTANT_MESSAGE_TEXT_TOO_LONG`   |
| an empty sender name           | `ERROR_INSTANT_MESSAGE_SENDER_IS_EMPTY` |
| a time to live outside 15–86400 seconds | `ERROR_INSTANT_MESSAGE_TTL_OUT_OF_RANGE` |

The text holds a thousand characters, `GatewayInterface::IM_MAX_LENGTH`, diacritics and all — it is
not an SMS and is not segmented. The Viber sender is registered with the operator and is neither
the SMS sender nor derived from it, so the eleven-character rule of
[the message](message.md#the-sender-name) does not apply to it.

## Which segments went out over Viber

The identifier of a segment that went out over Viber carries a `v:` prefix, so the result names
those again on their own:

```php
$sent = $result->getSent();

$sent[$requestId][0]['uuid'];  // ['v:55b42acb-…', 'c8a8bc0f-…'] every segment
$sent[$requestId][0]['viber']; // ['v:55b42acb-…'] the ones that went out over Viber
```

A delivery report for a Viber message can also say `SEEN` — the recipient opened it — which no SMS
ever reports. See [Delivery status](delivery-status.md) and [CallBack](callback.md).

## In a transaction

A group send falls back to the `dimsndr` and `dimttl` of the whole transaction, chapter 9.3.8,
taken from the first message that names a variant. A message only has to name what differs.

```php
$first->setInstantMessage($variant);  // becomes dimsndr and dimttl for the transaction
```

## Registering the sender

Registering the Viber sender and uploading its logo is an administrative process on the EuroSMS
side and takes about two weeks; the library plays no part in it. Until it is done the gateway
answers a variant with `IM_UNREGISTERED_SENDER`, chapter 10.6 — one of the five verdicts a Viber
message can be refused with, all of them listed in [Errors](errors.md).

## What goes on from here

* [The message](message.md) — the flags, and what the SMS half of the message carries
* [Send many to many](send-many-to-many.md) — the Viber defaults of a transaction
* [Errors](errors.md) — the `IM_*` verdicts of chapter 10.6
