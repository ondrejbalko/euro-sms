# CallBack notifications

The gateway can push what it knows instead of waiting to be asked, chapter 6: a receipt the moment
the operator reports one, and every message that arrives at an inbound number. The address is
registered with EuroSMS on request and the library plays no part in that, nor in the routing on the
receiving side — it is handed the parameters of the request and gives back the answer that belongs
in the response.

**A CallBack that is not confirmed is pushed again for 24 hours and then thrown away as
undeliverable**, so the answer is not an afterthought: it is the whole point of handling one.

## An endpoint, whole

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\CallBack\Acknowledgement;
use EuroSms\CallBack\CallBackParser;
use EuroSms\CallBack\DeliveryReport;
use EuroSms\Exception\CallBackException;

try {
    // a receipt or an incoming message, whichever the gateway pushed, and whether it came as the
    // query parameters of a GET or as the JSON of the delivery_report or received parameter
    $notification = CallBackParser::parse($_GET);
} catch (CallBackException $e) {
    // nothing is confirmed that was not read, so the gateway will push it again
    http_response_code(400);

    exit;
}

if ($notification instanceof DeliveryReport) {
    $notification->getUuid();           // 60c525e2-b28a-4e92-9a0f-58dacfa23a86
    $notification->getDeliveryResult(); // a DeliveryResultEnum case, chapter 13.2
    $notification->isDelivered();       // true only for DELIVRD
    $notification->isSeen();            // true only for SEEN, which only a Viber message reports
    $notification->isFinal();           // false while one more report for this message is to come
    $notification->getSegment();        // which segment of a long message this receipt is about
    $notification->getDeliveryTime();   // DateTimeImmutable|null
    $notification->getSentTime();       // DateTimeImmutable|null
    $notification->getSentResult();     // OK
    $notification->getOperator();       // 231.1, not filled in for every destination
    $notification->getPrice();          // 0.031
} else {
    $notification->getUuid();
    $notification->getRecipient();      // the inbound number the message arrived at
    $notification->getSender();         // the number it came from
    $notification->getReceiveTime();    // DateTimeImmutable
    $notification->getText();           // the whole message, long ones assembled by the gateway
}

// ok|60c525e2-b28a-4e92-9a0f-58dacfa23a86, and nothing around it
header('Content-Type: text/plain');

echo Acknowledgement::of($notification)->toPlain();
```

The gateway works out the shape of the answer on its own, so plain and JSON are equally good:

```php
header('Content-Type: application/json');

echo Acknowledgement::of($notification)->toJson(); // {"sms_uuid":"…","status":"ok"}
```

`Acknowledgement` is `Stringable` and `JsonSerializable`, so it also drops straight into whatever a
framework returns:

```php
Acknowledgement::of($notification)->toArray();     // ['sms_uuid' => …, 'status' => 'ok']
Acknowledgement::ofUuid($uuid);                    // when the uuid is all that is at hand
(string)Acknowledgement::of($notification);        // the plain form
```

## Doing the work before answering

Confirm only what has actually been handled. The gateway repeats an unconfirmed notification, which
is exactly the behaviour a failed database write wants:

```php
try {
    $notification = CallBackParser::parse($_GET);
} catch (CallBackException $e) {
    http_response_code(400);

    exit;
}

try {
    $receipts->store($notification);
} catch (Throwable $e) {
    // say nothing, and the gateway pushes it again for up to 24 hours
    http_response_code(500);

    exit;
}

header('Content-Type: text/plain');

echo Acknowledgement::of($notification)->toPlain();
```

## The four ways in

| Call                                    | For                                                   |
|-----------------------------------------|-------------------------------------------------------|
| `CallBackParser::parse($data)`          | either kind, worked out from what the body carries    |
| `CallBackParser::parseQuery($query)`    | the same thing read off the query string itself       |
| `CallBackParser::deliveryReport($data)` | an endpoint that only ever takes receipts             |
| `CallBackParser::receivedMessage($data)`| an endpoint that only ever takes incoming messages    |

```php
CallBackParser::parseQuery($_SERVER['REQUEST_URI']);
CallBackParser::parse(json_decode($rawBody, true) ?? []);
```

A notification arrives either as the query parameters of a `GET` or as JSON wrapped in a
`delivery_report` or `received` parameter, chapter 6.1. `parse()` takes both shapes, unwraps the
envelope where there is one, and decides from the fields which of the two it is holding.

## What is refused, and why

A body that cannot be read is refused rather than half understood. A field that is absent, or
stated as an empty string, counts as unstated; a required one that is unstated is an incomplete
body, and a field shaped otherwise than the documentation allows is an unreadable one.

| Code                     | When                                                          |
|--------------------------|---------------------------------------------------------------|
| `ERROR_BODY_NOT_RECOGNISED` | the body is neither a receipt nor an incoming message      |
| `ERROR_BODY_NOT_READABLE`   | the envelope could not be decoded                          |
| `ERROR_FIELD_MISSING`       | a required field is absent or empty                        |
| `ERROR_FIELD_NOT_READABLE`  | a field is shaped otherwise than chapter 6 allows          |

```php
use EuroSms\CallBack\CallBackParser;
use EuroSms\Exception\CallBackException;

try {
    $notification = CallBackParser::parse($_GET);
} catch (CallBackException $e) {
    match ($e->getCode()) {
        CallBackParser::ERROR_FIELD_MISSING => error_log('Incomplete CallBack.'),
        default => error_log('Unreadable CallBack: ' . $e->getMessage()),
    };

    http_response_code(400);

    exit;
}
```

Chapter 13.2 calls itself the complete list of delivery statuses, so a `delivery_result` that is
not on it is refused the same way rather than passed on unread. Nothing is ever confirmed that was
not read.

`Acknowledgement` has two refusals of its own, both `CallBackException`:
`ERROR_UUID_NOT_DEFINED` for an answer with no identifier to name, and `ERROR_NOT_ENCODABLE` when
the JSON form cannot be composed.

## One receipt, one segment

One receipt is pushed for one message, or for one segment of a long one, so a message of four
segments arrives as four of them — `getSegment()` says which. An incoming message is the other way
round: the gateway waits for every segment, puts the text back together and pushes it as one.

A Viber message can report `SEEN` on top of its delivery, which no SMS ever does. It follows a
delivery that was already closed, so `isFinal()` is false for it.

## Wiring it into a framework

The parser is handed an array and gives back an object; it knows nothing of routing, of the request
object of any framework, or of how a response is returned. Symfony, Laravel and Nette endpoints are
written out in [Using it elsewhere](integration.md).

## What goes on from here

* [Delivery status](delivery-status.md) — the same reports, asked for instead of pushed
* [Viber](viber.md) — the one thing that reports `SEEN`
* [Errors](errors.md) — every code above, in one table
