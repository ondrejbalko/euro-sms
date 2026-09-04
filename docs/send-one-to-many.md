# Send one to many

`sendOneToMany()` sends one text to a list of numbers. It is the campaign send: one message, one
sender, one schedule, as many recipients as the list holds.

Endpoint `POST /api/v3/send/o2m`, chapter 9.3. In test mode `POST /api/v3/test/o2m`.

## The shortest thing that works

On a `$service` built as in [Configuration](configuration.md), and recipients as in
[Recipients](recipients.md):

```php
$recipients = new RecipientCollection;
$recipients[] = new Recipient('0901 000 000');
$recipients[] = new Recipient('0901 000 001');

$message = new Message;
$message->setSenderName('MyShop');
$message->setContent('We are open until 20:00 today.');
$message->setRecipientCollection($recipients);

$result = $service->sendOneToMany($message);
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
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
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
$config->setRequestConcurrency(5); // how many batches stand at the gateway at once

$numbers = ['0901 000 000', '0901 000 001', '+421901000002', 'not a number'];

try {
    $service = new EuroSmsService($config);

    $recipients = new RecipientCollection;

    foreach ($numbers as $index => $number) {
        try {
            $recipients[] = new Recipient(
                $number,
                RecipientInterface::RECIPIENT_DEFAULT_COUNTRY,
                'customer-' . $index // repeated back on every delivery report
            );
        } catch (RecipientException $e) {
            // one unreadable number does not have to stop the campaign
            printf("%s skipped: %s\n", $number, $e->getMessage());
        }
    }

    $message = new Message;
    $message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
    $message->setSenderName('MyShop');
    $message->setContent('We are open until 20:00 today.');
    $message->setRecipientCollection($recipients);
    $message->setScheduleDateTime(new DateTime('2026-01-10 12:00'));
    $message->setDuration('01:00');                        // spread over an hour
    $message->setEnd(new DateTime('2026-01-10 13:00'));
    $message->getFlag()->addReceipt();

    $result = $service->sendOneToMany($message);

    foreach ($result->getSent() as $entries) {
        foreach ($entries as $entry) {
            printf("%s: %s\n", $entry['number'], implode(', ', $entry['uuid']));
        }
    }

    foreach ($result->getFailed() as $entries) {
        foreach ($entries as $entry) {
            // the gateway answered and refused this number
            printf("%s refused: %s\n", $entry['number'], implode(', ', $entry['error']));
        }
    }

    foreach ($result->getDenied() as $entries) {
        foreach ($entries as $entry) {
            // the batch never got through, or the gateway listed the number as a wrong one
            printf("%s denied: %s\n", $entry['number'], $entry['reason'] ?? 'wrong number');
        }
    }

    foreach ($result->getGroupIds() as $groupId) {
        // ask after the whole send later, see delivery-status.md
        printf("group %d\n", $groupId);
    }
} catch (ConfigException $e) {
    // the id or the key is missing, or the concurrency was set below one
} catch (MessageException $e) {
    // the sender name, the text or the duration was refused, or every number was dropped
    // above and the collection handed over was empty
} catch (RecipientException $e) {
    // the message was never given a recipient collection at all
} catch (RequestException $e) {
    // the request could not be built out of the message
} catch (SendException $e) {
    // not one batch of the send got through
    $e->getHttpStatus();
    $e->getResponseBody();
}
```

## Batches

A send that does not fit into one request is split into batches of a thousand numbers,
`GatewayInterface::MAX_ITEMS_PER_REQUEST`, and the batches go out at the same time rather than one
after another. Ten thousand numbers are ten calls, five of them on the wire at once by default.

```php
$config->setRequestConcurrency(10); // more of them at a time
$config->setRequestConcurrency(1);  // one after another, the way it used to go
```

**A number written twice is sent once.** The collection is reduced to its unique numbers before
anything is built.

**A batch that fails does not take the rest of the send with it.** The others are still sent, and
every number of the failed batch is in `getDenied()` with the reason the call did not go through
beside it. A send in which not one batch got through is a `SendException` — there is no partial
outcome to report there. Nothing is retried: when to send again is for the application to decide.

```php
$responses = $result->getResponseCollection();

$responses->hasFailures();          // whether any batch failed at all
$responses->getReasons();           // the reason of each of them, in words, by request id
$responses->getFailures();          // the SendException of each of them, by request id

$failure = $responses->getFailure($requestId);
$failure?->getHttpStatus();         // 500, or null when the call never reached the gateway
$failure?->getResponseBody();       // the refusal as the gateway stated it, undecoded
```

**With a PSR-18 client of your own the batches go out one after another.** The standard states one
call at a time and nothing besides, chapter 3. Everything else is the same. See
[Configuration](configuration.md).

## What comes back

`sendOneToMany()` answers with a `ResultOneToMany`, and every list is keyed by the identifier of
the batch the numbers belonged to.

| List          | When                                                 | The entry                 |
|---------------|------------------------------------------------------|---------------------------|
| `getSent()`   | the gateway enqueued the number                      | `number`, `uuid`, `viber` |
| `getFailed()` | the whole batch was refused, and this number with it | `number`, `error`         |
| `getDenied()` | the batch never went out, or the gateway named the number a wrong one | `number`, `reason` |

```php
$result->getGroupIds();          // the groups the gateway filed the send under, one per batch
$result->getMessage();           // the Message that was sent
$result->getWarnings();          // a text over four segments is reported here, not refused
$result->getRequestCollection(); // what was built and posted
```

**Chapter 9.3.6: a batch the gateway enqueued may still name numbers it refused.** Those are read
whether or not the batch as a whole got through, so a partly accepted send reports them rather than
losing them. Their `reason` is `null` — the gateway names none of its own — while a number denied
because its batch never went out carries the transport failure in words.

**The full answer is asked for whatever is configured.** The results are read number by number out
of the `accepted` and `wrong_numbers` lists, which the basic answer does not carry at all.

## What goes on from here

* [Recipients](recipients.md) — the numbers, their country, and a key of your own on each
* [Send many to many](send-many-to-many.md) — different texts in one transaction
* [Delivery status](delivery-status.md) — what happened to a group afterwards
* [Errors](errors.md) — every exception above, with its codes
