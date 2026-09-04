# Errors

Everything this library throws descends from `EuroSms\Exception\EuroSmsException`. It is abstract
and is never thrown itself, so one `catch` holds whatever went wrong while the concrete classes
stay catchable on their own.

```php
use EuroSms\Exception\EuroSmsException;

try {
    $result = $service->sendOne($message);
} catch (EuroSmsException $e) {
    error_log($e->getMessage());
}
```

## The tree

```text
EuroSmsException (abstract)
├── ConfigException          the configuration cannot build a working service
├── MessageException         the message itself was refused
├── InstantMessageException  the Viber variant was refused
├── RecipientException       a phone number was refused
├── RequestException         a request could not be built out of what was given
├── SendException            the call did not go through, or the gateway refused it
└── CallBackException        a pushed notification could not be read
```

`$e->getCode()` carries the constant, `$e->getMessage()` says what happened in words. The
constants live on the interface of the thing that refused — `MessageInterface`,
`RecipientInterface`, `RequestInterface`, `CallBackParser`, `Acknowledgement` — so a `catch` block
compares against a named value and not against a number.

**One exception is not in the tree.** `getStatusAny()` draws a transaction number when the caller
names none, so it can throw `\Random\RandomException` when the source of randomness fails. It is a
PHP exception and is caught as one. A transaction number the caller names itself is checked against
the length of chapter 9.4.3 instead and raises a `RequestException`.

## Where each is thrown

| Exception                | Thrown by                                                              |
|--------------------------|-------------------------------------------------------------------------|
| `ConfigException`        | `new EuroSmsService()`, `Config::setId()`, `setKey()`, `setRequestConcurrency()` |
| `MessageException`       | `Message::setSenderName()`, `setContent()`, `setDuration()`, `setRecipientCollection()`, and the send of an empty message collection |
| `InstantMessageException`| `InstantMessage::setSenderName()`, `setContent()`, `setTtl()`           |
| `RecipientException`     | `new Recipient()`, and a message asked for recipients it was never given |
| `RequestException`       | building a request: a transaction refusing what it cannot carry         |
| `SendException`          | `sendOne()`, `sendOneToMany()`, `sendManyToMany()`, all three status calls |
| `CallBackException`      | `CallBackParser::*`, `Acknowledgement::*`                               |

## Every code

### `MessageInterface`

| Constant                                  | What was written                                          |
|-------------------------------------------|-----------------------------------------------------------|
| `ERROR_SENDER_NOT_DEFINED`                | a message of a transaction naming no sender at all        |
| `ERROR_SENDER_COLLECTION_IS_EMPTY`        | `setRecipientCollection()` given an empty collection      |
| `ERROR_SENDER_NAME_IS_EMPTY`              | `setSenderName('')`                                       |
| `ERROR_SENDER_NAME_TOO_LONG`              | a text sender over eleven characters                      |
| `ERROR_SENDER_NAME_INVALID`               | a character a sender may not carry                        |
| `ERROR_SENDER_NAME_NOT_A_NUMBER`          | digits that are no number and no text sender either       |
| `ERROR_CONTENT_IS_EMPTY`                  | `setContent('')`, or a text of nothing but whitespace     |
| `ERROR_DURATION_INVALID`                  | a duration not written as `hh:mm`                         |
| `ERROR_MESSAGE_COLLECTION_IS_EMPTY`       | `sendManyToMany()` on an empty collection                 |
| `ERROR_INSTANT_MESSAGE_TEXT_IS_EMPTY`     | a Viber variant with no text                              |
| `ERROR_INSTANT_MESSAGE_TEXT_TOO_LONG`     | a Viber text over 1000 characters                         |
| `ERROR_INSTANT_MESSAGE_SENDER_IS_EMPTY`   | a Viber variant with no sender                            |
| `ERROR_INSTANT_MESSAGE_TTL_OUT_OF_RANGE`  | a Viber time to live outside 15 to 86400 seconds          |

### `RecipientInterface`

| Constant                                  | What was written                                          |
|-------------------------------------------|-----------------------------------------------------------|
| `ERROR_WRONG_NUMBER`                      | a number that could not be read into E.164                |
| `ERROR_RECIPIENT_NOT_DEFINED`             | a message asked for its recipient, having none            |
| `ERROR_RECIPIENT_COLLECTION_NOT_DEFINED`  | a message asked for its recipients, having none           |

### `RequestInterface`

| Constant                        | What was written                                                    |
|---------------------------------|---------------------------------------------------------------------|
| `ERROR_MESSAGE_NOT_DEFINED`     | a request built without a text                                      |
| `ERROR_DURATION_INVALID`        | a duration not written as `hh:mm`                                   |
| `ERROR_MESSAGE_NOT_SCHEDULABLE` | a message of a transaction given a schedule, an end or a time to live |
| `ERROR_DURATION_AMBIGUOUS`      | two messages of one transaction asking for different durations      |
| `ERROR_DURATION_NOT_SPLITTABLE` | a collection asking for a duration and too big for one transaction  |

### `CallBackParser` and `Acknowledgement`

| Constant                     | What arrived                                              |
|------------------------------|-----------------------------------------------------------|
| `ERROR_BODY_NOT_RECOGNISED`  | neither a receipt nor an incoming message                 |
| `ERROR_BODY_NOT_READABLE`    | an envelope that could not be decoded                     |
| `ERROR_FIELD_MISSING`        | a required field absent or empty                          |
| `ERROR_FIELD_NOT_READABLE`   | a field shaped otherwise than chapter 6 allows            |
| `ERROR_UUID_NOT_DEFINED`     | an acknowledgement with no identifier to name             |
| `ERROR_NOT_ENCODABLE`        | an acknowledgement whose JSON form could not be composed  |

## `SendException` carries the answer

A call the gateway refused with 400 or worse states the refusal in the body of that answer, chapter
11, so the exception carries both:

```php
use EuroSms\Exception\SendException;

try {
    $result = $service->sendOne($message);
} catch (SendException $e) {
    $e->getHttpStatus();   // 500, or null when the call never reached the gateway
    $e->getResponseBody(); // the refusal as the gateway stated it, undecoded
    $e->getMessage();      // 'Gateway answered with http status 500.', or the transport failure
}
```

A `SendException` says one of three things, and which one tells the caller where the call stopped:

| Message                                 | What happened                                              |
|-----------------------------------------|-------------------------------------------------------------|
| `Gateway answered with http status %d.` | the gateway answered, with 400 or worse                     |
| `Batch was not sent.`                   | the batch never went out and the transport named no reason  |
| whatever the transport said             | the call failed before an answer, and said why              |

The first two are the private constants `ERROR_HTTP_STATUS` and `ERROR_BATCH_FAILED` of
`EuroSmsService`; they are messages and not codes, so there is nothing to compare `getCode()`
against — read `getHttpStatus()` instead.

**A send of several batches throws nothing while the others go through.** Only a send in which not
one batch got through is a `SendException`. The refusal of a single batch is read off the response
collection instead:

```php
$responses = $result->getResponseCollection();

$responses->hasFailures();
$responses->getReasons();                 // by request id, in words
$responses->getFailure($requestId)?->getHttpStatus();
```

## The verdicts of the gateway

A send the gateway answered is not an exception — it is a result carrying a verdict. Every send is
answered with one code, chapter 13.1, and a Viber message with five more of its own, chapter 10.6.
`SendStatusEnum` is that list and nothing besides.

```php
use EuroSms\Enums\SendStatusEnum;

$response->getSendStatus();                 // a SendStatusEnum case, or null when no code was stated
$response->getSendStatus()?->isEnqueued();  // true only for ENQUEUED
$response->isSent();                        // the same verdict as a bool

SendStatusEnum::NO_BALANCE->getDescription();          // The account has no credit left
SendStatusEnum::WRONG_NUMBER->getDescription();        // The recipient is not a number the gateway can send to
SendStatusEnum::IM_TTL_OUT_OF_RANGE->getDescription(); // The time to live of the Viber message is out of range
```

| Group             | Cases                                                                                     |
|-------------------|-------------------------------------------------------------------------------------------|
| it went out       | `ENQUEUED`                                                                                |
| the account       | `NO_BALANCE`, `NO_IID`, `WRONG_IID`, `NO_SGN`, `WRONG_SIGNATURE`                          |
| the message       | `NO_MSG`, `NO_TXT`, `EMPTY_MESSAGE`, `MSG_TOO_LONG`, `TOO_MANY_MESSAGES`                  |
| the addressing    | `NO_RCPT`, `WRONG_NUMBER`, `NO_SNDR`, `WRONG_SENDER`                                      |
| the Viber variant | `IM_MSG_EMPTY`, `IM_MSG_TOO_LONG`, `IM_NOT_ALLOWED`, `IM_TTL_OUT_OF_RANGE`, `IM_UNREGISTERED_SENDER` |
| anything else     | `ERR_OTHER`                                                                               |

A code neither chapter lists is read as `ERR_OTHER`, the refusal the gateway did not name, and the
string it came as stays where it was, in the body of the answer:

```php
SendStatusEnum::fromCode('FAILED');      // SendStatusEnum::ERR_OTHER
SendStatusEnum::isKnownCode('FAILED');   // false, the gateway never said ERR_OTHER itself
$response->getBody()['err_code'];        // FAILED, as it arrived
```

A group send reports every number on its own, so the same codes come back a second time inside
`getFailed()`, keyed by the code the gateway refused that number with.

The delivery statuses of chapter 13.2 are a list of their own, `DeliveryResultEnum` — see
[Delivery status](delivery-status.md).

## What is worth sending again

Nothing is retried inside the library: it reports and the application decides.

| Outcome                                              | Worth another try                             |
|------------------------------------------------------|-----------------------------------------------|
| `SendException` with `getHttpStatus() === null`      | yes — the gateway was never reached           |
| `SendException` with a 5xx status                    | yes, after a wait                             |
| a batch in `getDenied()` with a reason               | yes, that batch alone                         |
| `getFailed()` with `WRONG_NUMBER`, `WRONG_SENDER`    | no — the same send fails the same way         |
| `NO_BALANCE`                                         | no, not until the account is topped up        |
| any `MessageException` or `RecipientException`       | no — the input was refused before it was sent  |

**A denied batch is the one to resend**, not the whole campaign. Every number of it is in
`getDenied()`, so the batch can be rebuilt out of that list alone and the numbers that got through
are not written to twice.

## What goes on from here

* [Send one to many](send-one-to-many.md) — how a partly failed send is read back
* [CallBack notifications](callback.md) — refusing a notification so the gateway repeats it
* [Logging](logging.md) — what a failed call writes into the log
