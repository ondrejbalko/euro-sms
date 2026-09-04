# Delivery status

The gateway keeps the status of a message for **7 days** after it was sent, chapter 9.4, so a
receipt that is not asked for within that window is lost for good. There are three ways to ask, and
a fourth way of not asking at all — see [CallBack notifications](callback.md), where the gateway
pushes what it knows the moment it knows it.

| Call                        | Asks after                                    | Chapter |
|-----------------------------|-----------------------------------------------|---------|
| `getStatusOne($uuid)`         | one message, one report per segment           | 9.4.1   |
| `getStatusGroup($groupId)`    | a whole group send                            | 9.4.4   |
| `getStatusAny($transaction?)` | everything that changed since the last call   | 9.4.3   |

## Asking

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\Config;
use EuroSms\EuroSmsService;
use EuroSms\Exception\EuroSmsException;
use EuroSms\Exception\SendException;
use Random\RandomException;

$config = new Config;
$config->setId(getenv('EUROSMS_ID') ?: '');
$config->setKey(getenv('EUROSMS_KEY') ?: '');

try {
    $service = new EuroSmsService($config);

    // one message, by one of the uuids a send answered with
    $status = $service->getStatusOne('55b42acb-6862-43a3-81ae-1fa354ba3c6e');

    // a whole group send, by the group the gateway filed it under
    $status = $service->getStatusGroup(1234);

    // everything whose status has changed since this was last asked
    $status = $service->getStatusAny();

    if ($status->isEmpty()) {
        exit("nothing new\n");
    }

    foreach ($status as $report) {
        printf(
            "%s %s %s\n",
            $report->getUuid(),
            $report->getStatus(),
            $report->getDeliveryResult()?->getDescription() ?? ''
        );
    }
} catch (SendException $e) {
    // the call did not go through, or the gateway refused it
    $e->getHttpStatus();
    $e->getResponseBody();
} catch (EuroSmsException $e) {
    // anything else the library throws
    error_log($e->getMessage());
} catch (RandomException $e) {
    // getStatusAny() draws a transaction number when none is named, and the randomness failed
    error_log($e->getMessage());
}
```

**The status endpoints have no test mirror.** A message that was never sent has no status to ask
after, so `setTestMode(true)` changes nothing here.

## What a report carries

```php
foreach ($status as $report) {
    $report->getRecipient();      // 421903170552
    $report->getIdentifier();     // the key the send gave that recipient, null when it gave none
    $report->getStatus();         // DELIVRD, ENROUTE, EXPIRED, UNDELIV, REJECTD, DELETED, UNKNOWN, SEEN
    $report->getDeliveryResult(); // the same status as a DeliveryResultEnum case, or null
    $report->isFinal();           // false while another report for this segment is still to come
    $report->isDelivered();       // true only for DELIVRD
    $report->getSegment();        // which segment of the message this is
    $report->getUuid();           // the identifier of that segment
    $report->getDeliveryTime();   // DateTimeImmutable|null
    $report->getSendTime();       // DateTimeImmutable|null
    $report->getPrice();          // 0.0265
    $report->getCarrier();        // 231.6
    $report->getErrorCode();      // OK
    $report->getFlag();
}
```

`getIdentifier()` is the key given to the recipient at send time — see
[Recipients](recipients.md#a-key-of-your-own). It is the one thing that matches a receipt to a
record without guessing: a phone number written to twice in a row cannot be told apart, a key can.

## The answer itself

`StatusResponse` is `Countable` and `IteratorAggregate`, so it is looped over directly.

```php
count($status);          // how many reports came back
$status->getReports();   // the DeliveryReport objects
$status->getCount();     // the count the gateway stated, chapter 9.4.4, null when it stated none
$status->getErrorCode(); // the code an answer that refused the call carried
$status->getBody();      // the answer decoded, exactly as it arrived
$status->isEmpty();      // nothing to report
```

**An unknown identifier is answered with nothing, not with an error.** So is a group whose statuses
have not changed since the last call. `isEmpty()` is the question to ask; an exception means the
call itself did not go through.

## Polling

A message reaches a final status and is then reported no more. Chapter 13.2 marks `DELIVRD`,
`UNDELIV`, `EXPIRED`, `REJECTD` and `DELETED` as the last word on a segment; everything else is
followed by one more report.

```php
foreach ($status as $report) {
    if (!$report->isFinal()) {
        continue; // ask again later
    }

    printf("%s closed as %s\n", $report->getIdentifier() ?? $report->getUuid(), $report->getStatus());
}
```

**`getStatusAny()` reports a changed status once and not again**, so what comes back has to be read
now — a worker that throws the answer away has thrown the receipt away with it. A long message is
reported segment by segment, so a message of four segments is four reports, each with its own
`getSegment()`.

**Name the transaction number and the call can be made again.** The gateway tells one bulk call
from another by it, chapter 9.4.3, so a call whose answer never arrived — a timeout, a 502, a
worker that died holding it — is asked again under the same number and answers the same reports.
Asked again under a fresh one, it answers whatever changed since, and what it had already reported
is not among it.

```php
$transaction = sprintf('nightly-%s', date('Y-m-d-H'));

try {
    $status = $service->getStatusAny($transaction);
} catch (SendException) {
    $status = $service->getStatusAny($transaction); // the same reports, not the next ones
}
```

The number is 8 to 64 characters, `StatusRequest::TRANSACTION_MIN_LENGTH` to
`TRANSACTION_MAX_LENGTH`; anything outside that is a `RequestException` raised before the call is
made. A caller that names none is given a generated one and never draws the same twice.

## The statuses

| Case            | Value     | Final | Meaning                             |
|-----------------|-----------|-------|-------------------------------------|
| `DELIVERED`     | `DELIVRD` | yes   | it arrived                          |
| `UNDELIVERABLE` | `UNDELIV` | yes   | it cannot be delivered              |
| `EXPIRED`       | `EXPIRED` | yes   | its time to live ran out            |
| `REJECTED`      | `REJECTD` | yes   | the operator refused it             |
| `DELETED`       | `DELETED` | yes   | it was deleted before delivery      |
| `EN_ROUTE`      | `ENROUTE` | no    | still on its way                    |
| `ACCEPTED`      | `ACCEPTD` | no    | taken by the operator               |
| `UNKNOWN`       | `UNKNOWN` | no    | the operator says nothing about it  |
| `SEEN`          | `SEEN`    | no    | opened, reported for Viber alone    |

```php
use EuroSms\Enums\DeliveryResultEnum;

DeliveryResultEnum::DELIVERED->isFinal();       // true
DeliveryResultEnum::SEEN->isFinal();            // false, the delivery it follows was closed already
DeliveryResultEnum::REJECTED->getDescription(); // Refused by the operator
```

## What goes on from here

* [CallBack notifications](callback.md) — the same reports, pushed instead of polled
* [Send one to many](send-one-to-many.md) — where a group id comes from
* [Errors](errors.md) — the exceptions a status call can throw
