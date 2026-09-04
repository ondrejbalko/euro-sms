# Logging

The library writes into any PSR-3 logger it is handed as the fifth argument of the service. One
that is handed none writes nowhere — there is no logger of its own inside, and nothing is written
to a file, to the error log or to the output.

```php
use EuroSms\EuroSmsService;

$service = new EuroSmsService($config, logger: $logger);
```

## What is written

Every call produces two records at the `debug` level, one before it goes out and one when the
answer arrives, and a call that did not go through produces one at `error` instead. What tells one
record from another is in the context, where PSR-3 expects it:

| Message                | Level   | Context                                           |
|------------------------|---------|---------------------------------------------------|
| `EuroSMS request.`     | `debug` | `method`, `endpoint`, `request`, `body`           |
| `EuroSMS response.`    | `debug` | `endpoint`, `request`, `status`, `body`           |
| `EuroSMS call failed.` | `error` | `endpoint`, `status`, `body`, `reason`, `request` |

A call the gateway refused with 400 or worse carries its status and the body it stated the refusal
in; one that never reached the gateway carries `null` for both and names the transport failure in
`reason`.

`request` is the identifier of the batch the record is about — the same one `getSent()`,
`getFailed()`, `getDenied()` and `ResponseCollection::getFailures()` are keyed by. The batches of
one send travel together and are answered in whatever order the gateway answers them, so the
endpoint alone, the same string for every batch of the send, does not say which of them a record
belongs to. A status call is no batch and carries `null`.

```php
$context = [
    'method' => 'POST',
    'endpoint' => '/api/v3/send/one',
    'request' => '9f3a1c88-6d0e-4f2b-9a55-1b7c0d2e4a61',
    'body' => ['iid' => '1-5U8J3C', 'rsp' => 'basic', 'sgn' => '***', 'rcpt' => 421903622237],
];
```

## What is not written

**The integration key and the signature of chapter 4.2 never reach a record.** The `sgn` of a send
is masked, and so is the one of every message of a transaction; the signature a bulk status call
carries in the last segment of its path is masked there. The status of one message carries none,
chapter 9.4.1, so that path is written out in full. The key itself is never sent anywhere — it
only signs — and any value equal to it is masked all the same.

The body of the answer is read for the record and put back where it was found, so the result is
built out of it as it always was. An answer whose body cannot be rewound is not read at all and the
record carries `null` — taking the body away from the caller would cost more than the record is
worth.

**Message texts and recipient numbers are not masked.** They are what a send is, and a record of a
call that says nothing about what was sent answers no question worth asking. Where that is more
than the log should hold, keep the logger at `error` and let the `debug` records fall away.

## There is no debug switch

A PSR-3 logger decides for itself which levels it writes, so the threshold is set where the logger
is built. With Monolog, by the level the handler is given:

```php
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$logger = new Logger('euro-sms');
$logger->pushHandler(new StreamHandler('log/euro-sms.log', Level::Error)); // only the failures
$logger->pushHandler(new StreamHandler('log/euro-sms.log', Level::Debug)); // every call as well

$service = new EuroSmsService($config, logger: $logger);
```

`Config::setDebugMode()` and `Config::isDebugMode()` were removed in `2.0.0`. They switched nothing
inside the library — see [Upgrading to 2.0](upgrading-2.0.md).

## A logger of a framework

Symfony, Laravel and Nette all bring a PSR-3 logger of their own, and it is handed over the same
way — [Using it elsewhere](integration.md) writes each of them out.

## What goes on from here

* [Configuration](configuration.md) — the other four arguments of the service
* [Errors](errors.md) — what a failed call throws while it is being logged
