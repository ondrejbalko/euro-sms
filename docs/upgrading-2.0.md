# Upgrading to 2.0

**The three entry points are unchanged.** `sendOne()`, `sendOneToMany()` and `sendManyToMany()` are
called the way they always were. What changed is everything underneath them. The full list of
incompatible changes, with the reason for each, is in [CHANGELOG.md](../CHANGELOG.md); what follows
is what reaches code that only ever used the library from the outside.

## Before anything else

**PHP 8.5 is required.** The library is written against its syntax and cannot be installed on an
older runtime. `ext-mbstring` is now declared as well — it was always leant on and never stated, so
an installation without it fails at `composer install` rather than at the first message.

## Every exception has one ancestor

A caller that only needs to know that the call did not go through has a single class to catch,
instead of the seven it used to name one by one.

```php
use EuroSms\Exception\EuroSmsException;

try {
    $result = $service->sendOne($message);
} catch (EuroSmsException $e) {
    // whatever the library throws lands here; the concrete classes still work on their own
}
```

`EuroSmsException` is abstract and is never thrown itself. See [Errors](errors.md).

## The transport is PSR-18

`EuroSmsService::__construct()` takes `Psr\Http\Client\ClientInterface` where it used to take
`GuzzleHttp\ClientInterface`, followed by the PSR-17 factories the requests are built by. Handing
over none of them still gets the Guzzle client the library ships with, configured out of `Config`.

```php
use EuroSms\EuroSmsService;

$service = new EuroSmsService($config, $client, $requestFactory, $streamFactory, $logger);
```

The certificate verification and the timeout of `Config` configure that default client only. A
client handed over from outside brings its own, so set them where that client is built.

**The certificate of the gateway is now checked by default.** An installation that really has to
talk to a host with a certificate of its own turns it off with `setRequestVerifyHost(false)`.

## A request to a list of numbers has no single recipient

`RequestOneToMany` no longer inherits from `RequestOne` and answers neither `getRecipient()` nor
`setRecipient()`. Code that takes any request and asks it for its one recipient checks for the
interface that has one:

```php
use EuroSms\Gateway\Request\RequestRecipientInterface;

if ($request instanceof RequestRecipientInterface) {
    $recipient = $request->getRecipient();
}
```

`ResultManyToMany` answers `getMessageCollection()` where the other two answer `getMessage()`;
neither reaches for a property it was never given any more.

## The debug mode is gone

`Config::setDebugMode()` and `Config::isDebugMode()` switched nothing inside the library — what
they did was whatever the calling code did with the answer, usually turning on `display_errors`.
Code that used them that way keeps doing it on its own:

```php
error_reporting(E_ALL);
ini_set('display_errors', 'on');
```

What the switch was reached for — seeing what actually went to the gateway — is what a PSR-3
logger now does, and which levels are written is the logger's own threshold to decide. See
[Logging](logging.md).

## What used to pass and now throws

Eight things the library used to swallow are refused where they are written. Nothing here changes a
send that was correct; each of them was a send that went out wrong or not at all.

| Written                                              | Was                                             | Is now                                             |
|------------------------------------------------------|-------------------------------------------------|----------------------------------------------------|
| a sender name over eleven characters                 | cut with `substr()`, sometimes mid-UTF-8        | `MessageException`, `ERROR_SENDER_NAME_TOO_LONG`   |
| a sender name with a character the gateway refuses   | sent, and answered `WRONG_SENDER`               | `MessageException`, `ERROR_SENDER_NAME_INVALID`    |
| an empty text, or one of nothing but whitespace      | sent, and answered `EMPTY_MESSAGE`              | `MessageException`, `ERROR_CONTENT_IS_EMPTY`       |
| an empty `MessageCollection`                         | posted, and answered with a tally of nothing    | `MessageException`, `ERROR_MESSAGE_COLLECTION_IS_EMPTY` |
| a transaction message given `sch`, `end` or `ttl`    | sent with the schedule silently gone            | `RequestException`, `ERROR_MESSAGE_NOT_SCHEDULABLE`|
| a configuration with no integration id               | an uninitialized property, an `Error`           | `ConfigException` from the constructor             |
| an empty integration id or key                       | signed every request into a `WRONG_SIGNATURE`   | `ConfigException` from `setId()` and `setKey()`    |
| a duration on a collection too big for one transaction | sent at as many times the rate as there were transactions | `RequestException`, `ERROR_DURATION_NOT_SPLITTABLE` |

A caller that fed the library whatever a form gave it now has somewhere to catch that — see
[Errors](errors.md) for the code of each.

## What used to throw and now sends

**A sender written entirely in digits is a text sender when it is no phone number.** Chapter 9.2.1
lists digits among the characters the eleven-character text set is written out of, so every
all-digit sender being read as a number refused the ones no country hands out — an integration
sending under `8877` could not send at all.

```php
$message->setSenderName('8877');       // was ERROR_SENDER_NAME_NOT_A_NUMBER, now the short code
$message->setSenderName('0905123456'); // was ERROR_SENDER_NAME_NOT_A_NUMBER, now the text sender
$message->setSenderName('+1188');      // still ERROR_SENDER_NAME_NOT_A_NUMBER — the plus means a number
```

Code that caught `ERROR_SENDER_NAME_NOT_A_NUMBER` to fall back to a name of its own no longer
reaches that branch for a short code. A local number typed where an international one was meant now
goes out as the digits it spells rather than being refused, so validate the shape you require
before handing it over if that matters to you.

## The alphabet is worked out, not switched

`Message::setUnicode()` was a plain switch standing at off, so a text with diacritics nobody had
flipped it for went out as a plain message and the gateway replaced every accented character with a
question mark. It now takes three answers instead of two:

```php
$message->setUnicode(true);   // with diacritics whatever the text is
$message->setUnicode(false);  // as a plain message whatever the text is
$message->setUnicode();       // null, the default: the text decides
```

**`Message::isUnicode()` answers what the message will really be sent as**, so one carrying
diacritics answers `true` where it used to answer `false`. Code that read it to decide something of
its own is the code to look at.

How many messages a text falls into is counted in septets of GSM 03.38 now, and a text with
diacritics in sixteen-bit positions, so a message that used to be charged as two may now be one and
the other way round. See [The message](message.md#how-long-a-message-is).

## Signatures that changed

| Was                                                        | Is                                                        |
|-------------------------------------------------------------|-----------------------------------------------------------|
| `Config::getId(): string`                                   | `Config::getId(): ?string`                                |
| `RequestInterface::getRecipients(): int[]`                  | `RequestInterface::getRecipients(): list<int\|string>`    |
| `RequestManyToMany::addRequest(RequestOne $request)`        | `addRequest(RequestMessageAbstract $request)`             |
| `RequestMessageAbstract::setContent(…, bool $isUnicode = false)` | `setContent(…, ?bool $isUnicode = null)`             |
| `RequestInterface::getRecipient()`                          | moved to `RequestRecipientInterface`                      |
| `EuroSmsService::getStatusAny()`                            | `getStatusAny(?string $transaction = null)`               |
| `Flag::all(): FlagEnum[]`                                   | `Flag::all(): FlagEnum[]`, static                         |

`RequestInterface` also declares `getWarnings()` and `hasWarnings()` now, so an implementation of
it that is not built on `RequestAbstract` has to answer them.

**`ResultOne` reports `number` as an `int`.** The one key that carried the string the caller wrote
now carries the digits, the way every other result already did. Code matching a result entry
against its own records with `===` is the code to look at; `Recipient::getNumberOrig()` still hands
back the number exactly as it was written.

## What is new, and worth adopting

None of it is required to move from `1.x`, and all of it was the reason for the move.

* [Delivery status](delivery-status.md) — asking after a message, a group, or everything that changed
* [CallBack notifications](callback.md) — receipts pushed instead of polled
* [Viber](viber.md) — a Viber variant of a message
* [Recipients](recipients.md#a-key-of-your-own) — a key of your own on a recipient, repeated on every receipt
* [Logging](logging.md) — every call written into a PSR-3 logger, the key and the signature masked out
* [Send one to many](send-one-to-many.md#batches) — the batches of one send posted at the same time

## What goes on from here

* [CHANGELOG.md](../CHANGELOG.md) — every incompatible change, with the reason for it
* [Configuration](configuration.md) — the service as it is built now
