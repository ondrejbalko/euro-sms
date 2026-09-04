# Send many to many

`sendManyToMany()` sends a whole collection of messages as one transaction: different texts,
different recipients, different senders, one call. It is the send for anything personalised — an
invoice reminder naming its amount, a booking naming its time.

Endpoint `POST /api/v3/send/m2m`, chapter 9.3.8. In test mode `POST /api/v3/test/m2m`.

## The shortest thing that works

On a `$service` built as in [Configuration](configuration.md):

```php
$messages = new MessageCollection;

$first = new Message;
$first->setSenderName('MyShop');
$first->setContent('Your invoice 4471 is due tomorrow.');
$first->setRecipientCollection($firstRecipients);

$messages[$first->getId()] = $first;

$result = $service->sendManyToMany($messages);
```

## The whole thing, errors and all

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Message\MessageCollection;
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

$reminders = [
    ['number' => '0901 000 000', 'key' => 'invoice-4471', 'text' => 'Invoice 4471, 24.90 EUR, due tomorrow.'],
    ['number' => '0901 000 001', 'key' => 'invoice-4472', 'text' => 'Invoice 4472, 12.00 EUR, due tomorrow.'],
];

try {
    $service = new EuroSmsService($config);

    $messages = new MessageCollection;

    foreach ($reminders as $reminder) {
        try {
            $recipients = new RecipientCollection;
            $recipients[] = new Recipient(
                $reminder['number'],
                RecipientInterface::RECIPIENT_DEFAULT_COUNTRY,
                $reminder['key']
            );

            $message = new Message;
            $message->setSenderName('MyShop');
            $message->setContent($reminder['text']);
            $message->setRecipientCollection($recipients);
            $message->setDuration('02:00'); // the whole transaction is spread over two hours
            $message->getFlag()->addReceipt();

            $messages[$message->getId()] = $message;
        } catch (MessageException|RecipientException $e) {
            // one bad row does not have to stop the batch
            printf("%s skipped: %s\n", $reminder['key'], $e->getMessage());
        }
    }

    $result = $service->sendManyToMany($messages);

    foreach ($result->getSent() as $entries) {
        foreach ($entries as $entry) {
            // ['number' => …, 'uuid' => [...], 'viber' => [...], 'group_id' => 1234]
            printf("%s in group %s\n", $entry['number'], $entry['group_id'] ?? '-');
        }
    }

    foreach ($result->getFailed() as $entries) {
        foreach ($entries as $entry) {
            printf("%s refused: %s\n", $entry['number'], implode(', ', $entry['error']));
        }
    }

    foreach ($result->getDenied() as $entries) {
        foreach ($entries as $entry) {
            printf("%s denied: %s\n", $entry['number'], $entry['reason'] ?? 'unknown');
        }
    }
} catch (ConfigException $e) {
    // the id or the key is missing
} catch (MessageException $e) {
    // the collection was empty, or a message named no sender at all
} catch (RecipientException $e) {
    // a message was never given a recipient collection
} catch (RequestException $e) {
    // a message asked for something a transaction cannot carry, see below
} catch (SendException $e) {
    // not one transaction of the send got through
    $e->getHttpStatus();
    $e->getResponseBody();
}
```

## What a transaction refuses

Five things are refused before anything is sent, because chapter 9.3.8 has nowhere to put them.

| Refused                                                     | Exception                                        |
|-------------------------------------------------------------|--------------------------------------------------|
| an empty collection                                         | `MessageException`, `ERROR_MESSAGE_COLLECTION_IS_EMPTY` |
| a message naming no sender                                  | `MessageException`, `ERROR_SENDER_NOT_DEFINED`   |
| a message given `setScheduleDateTime()`, `setEnd()` or `setTtl()` | `RequestException`, `ERROR_MESSAGE_NOT_SCHEDULABLE` |
| two messages asking for two different durations             | `RequestException`, `ERROR_DURATION_AMBIGUOUS`   |
| a duration on a collection too big for one transaction      | `RequestException`, `ERROR_DURATION_NOT_SPLITTABLE` |

```php
$service->sendManyToMany(new MessageCollection);
// MessageException: Message collection is empty.

$message->setTtl(3600);
$service->sendManyToMany($messages);
// RequestException: A message of a many-to-many transaction cannot carry a time to live.
```

Chapter 9.3.1 lists neither `sch` nor `ttl` among the fields of a message inside a transaction, and
the root carries no default for either, so a message asking for one would be sent with what it
asked for quietly missing. Refusing it is the whole point. `dur` is the exception: there is one of
it and it sits at the root, so the transaction is spread over the length the first message names —
and a second message naming a different one is a contradiction rather than a preference.

**A duration only reaches one transaction.** A collection over the thousand messages of chapter
9.3.8 is split into several, each with a root of its own, and those go out at the same time — so
two transactions each spreading a thousand messages over an hour send two thousand in that hour,
twice the rate the duration was set for. Splitting the length between them does not help either,
because they still travel together. Either send the collection in transactions of your own, each
under a thousand messages and each with its own duration, or drop the duration and let the whole
collection leave at once.

## Defaults of the transaction

The root of a transaction states what its messages fall back to, chapter 9.3.8: `dsndr` the sender
name, `dflgs` the flags, `dimsndr` and `dimttl` the Viber sender and its time to live. **Each of
them is taken from the first message of the collection that names any.** A message that asks for
nothing of its own repeats neither, and it is signed with the name the gateway will send it under.

```php
$first->setSenderName('MyShop');   // becomes dsndr for the whole transaction
$second->setSenderName('MyShop2'); // states its own sndr, so it overrides the default
```

## How big a transaction is

A transaction holds a thousand messages, `GatewayInterface::MAX_ITEMS_PER_REQUEST`, and what counts
towards that is **how many messages it sends, not how many entries it lists** — one entry addressed
to a thousand numbers is already a thousand messages.

A collection carrying more is split into several transactions and every message is still sent: a
send of more than a thousand messages is allowed, it merely does not fit into one request. The
transactions travel together, the same way the batches of a one-to-many send do, and one that fails
leaves the others sent — see [Send one to many](send-one-to-many.md) for how a failed batch is read
back.

**A number written twice inside one message is sent once.** Each message is reduced to its unique
recipients on its own; the same number in two different messages is two different messages and is
sent twice, which is the point of this send.

## What comes back

`sendManyToMany()` answers with a `ResultManyToMany`. The lists are the ones every result carries,
with the group the transaction was filed under beside every entry.

| List          | When                                               | The entry                              |
|---------------|-----------------------------------------------------|----------------------------------------|
| `getSent()`   | the gateway enqueued the number                     | `number`, `uuid`, `viber`, `group_id`  |
| `getFailed()` | the gateway refused the number, or the transaction  | `number`, `error`, `group_id`          |
| `getDenied()` | the transaction never went out                      | `number`, `reason`                     |

```php
$result->getMessageCollection(); // the collection that was sent, not getMessage()
$result->getGroupIds();          // one group per transaction the send was split into
$result->getWarnings();
```

**`getMessageCollection()`, not `getMessage()`.** A transaction carries a collection and no single
message, so there is nothing for `getMessage()` to answer — it does not exist on this result.

Every number is answered for on its own, chapter 9.3.11: the `result` list names the code each
number was refused with, and those codes are the `SendStatusEnum` cases of chapter 13.1. A
transaction refused as a whole has no per-number verdict to read, so every number of it shares the
error the answer carried.

## What goes on from here

* [The message](message.md) — the flags and the defaults each message can name
* [Viber](viber.md) — `dimsndr` and `dimttl`, the Viber defaults of a transaction
* [Delivery status](delivery-status.md) — asking after a group afterwards
* [Errors](errors.md) — every exception above, with its codes
