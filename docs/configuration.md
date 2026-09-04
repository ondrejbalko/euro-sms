# Configuration

Everything the library needs to reach the gateway lives in one `EuroSms\Config` object, and the
service is built out of it. Nothing is read from the environment, from a file or from a global by
the library itself — what `Config` is given is what is used.

## The credentials

An integration id and its key are handed out by EuroSMS. Both are required: a service built on a
configuration missing either throws `ConfigException` from its constructor, before a single call is
made.

```php
use EuroSms\Config;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;

try {
    $config = new Config;
    $config->setId(getenv('EUROSMS_ID') ?: '');
    $config->setKey(getenv('EUROSMS_KEY') ?: '');

    $service = new EuroSmsService($config);
} catch (ConfigException $e) {
    // 'Id not specified.' or 'Key not specified.'
    exit($e->getMessage());
}
```

**A value that says nothing is refused where it is set**, not carried until the gateway answers for
it. The `?: ''` above is the shape this turns up in: an environment variable nobody filled in used
to pass every check the library makes, sign every request into a `WRONG_SIGNATURE` that named
nothing, and — because the log masks a value by comparing it against the key — write every empty
field of every logged body as `***`. `setId()` and `setKey()` raise `ConfigException` on an empty
value the way `setRequestConcurrency()` does on nought.

**The key never leaves the machine.** It signs every request, chapter 9.2.2, and the signature is
what travels; the key itself is sent nowhere. Keep it out of the repository and out of the log —
[Logging](logging.md) masks it and the signatures composed with it, but only in the records this
library writes.

## Test mode

In test mode every send goes to the `test` mirror of its endpoint. The gateway answers exactly as
it would answer the real one, and delivers nothing and charges nothing.

```php
$config->setTestMode(true);

$config->isTestMode(); // true
```

| Real                 | Test                  |
|----------------------|-----------------------|
| `/api/v3/send/one`   | `/api/v3/test/one`    |
| `/api/v3/send/o2m`   | `/api/v3/test/o2m`    |
| `/api/v3/send/m2m`   | `/api/v3/test/m2m`    |

The status endpoints have no test mirror — a message that was never sent has no status to ask for.
Every example in this documentation is written with test mode on, so it can be run as it stands
once the two credentials are filled in.

## How much of an answer to ask for

The `rsp` parameter of chapter 9.2.1 decides how much the gateway writes back: `BASIC` is the tally
alone, `FULL` writes every number out on its own. The gateway falls back to the basic answer, so
the library does the same.

```php
use EuroSms\Enums\ResponseFormatEnum;

$config->setResponseFormat(ResponseFormatEnum::FULL);
```

`sendOneToMany()` and `sendManyToMany()` ask for the full answer whatever is configured — their
results are read number by number out of the `accepted`, `wrong_numbers` and `result` lists, which
the basic answer does not carry at all. The setting decides what `sendOne()` asks for.

## The transport

| Setting                     | Default                            | What it does                                         |
|-----------------------------|------------------------------------|------------------------------------------------------|
| `setRequestTimeout()`       | `30.0`                             | how long one call may take, in seconds               |
| `setRequestVerifyHost()`    | `true`                             | whether the certificate of the gateway is checked    |
| `setRequestConcurrency()`   | `5`                                | how many batches of one send are on the wire at once |
| `setRequestContentType()`   | `application/json`                 | what a request is sent as and an answer asked in     |

```php
$config->setRequestTimeout(10.0);
$config->setRequestConcurrency(10);
```

**Leave the certificate check on.** The messages and the signatures of chapter 9.2.2 travel over
that connection; turning it off leaves it open to whoever stands in the middle. It is a setting for
a broken local trust store and nothing else.

**Concurrency below one is refused.** A send with no call on the wire is a send that never happens,
so `setRequestConcurrency(0)` throws `ConfigException`. One is accepted and posts the batches one
after another.

```php
use EuroSms\Exception\ConfigException;

try {
    $config->setRequestConcurrency(0);
} catch (ConfigException $e) {
    // 'At least one call at a time is needed, 0 given.'
}
```

Where the batches come from and what happens when one of them fails is in
[Send one to many](send-one-to-many.md).

## A client of your own

The transport is PSR-18. Any client implementing `Psr\Http\Client\ClientInterface` drives the
library, together with the PSR-17 factories the requests are built by. A caller handing over none
of them gets the Guzzle client the library ships with, configured out of `Config`.

```php
use EuroSms\EuroSmsService;

$service = new EuroSmsService($config, $client, $requestFactory, $streamFactory, $logger);
```

| Argument         | Type                                              | Handed over as nothing         |
|------------------|---------------------------------------------------|--------------------------------|
| `$config`        | `EuroSms\Config`                                  | required                       |
| `$client`        | `Psr\Http\Client\ClientInterface`                 | the Guzzle client of the library |
| `$requestFactory`| `Psr\Http\Message\RequestFactoryInterface`        | the Guzzle factory             |
| `$streamFactory` | `Psr\Http\Message\StreamFactoryInterface`         | the Guzzle factory             |
| `$logger`        | `Psr\Log\LoggerInterface`                         | nothing is written anywhere    |

Two things change with a client of your own:

* **The timeout and the certificate check of `Config` no longer apply.** They configure the default
  client and nothing else — a client from outside brings its own, so set them where it is built.
* **The batches of one send go out one after another.** A PSR-18 client states one call at a time
  and nothing besides, chapter 3 of the standard. Everything else stays the same: a failed batch
  still leaves the others sent and is reported the same way.

Frameworks that already have a PSR-18 client of their own — Symfony, Laravel, Nette — are wired up
in [Using it elsewhere](integration.md).
