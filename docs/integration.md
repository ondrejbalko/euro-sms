# Using it elsewhere

The library is a plain set of classes with no framework in it: `Config` is a value object,
`EuroSmsService` takes it and four optional collaborators, and nothing is read from a global, a
constant or a file. Wiring it into an application is therefore always the same three decisions —
where the credentials come from, which HTTP client is used, and which logger.

| Decision       | What to hand over                                                             |
|----------------|--------------------------------------------------------------------------------|
| credentials    | `Config::setId()` and `setKey()`, out of the environment                       |
| HTTP client    | a PSR-18 client and two PSR-17 factories, or nothing at all for the built-in one |
| logger         | any PSR-3 logger, or nothing to write nowhere                                   |

**Keep the credentials in the environment.** They are the account, and the key signs every request.
Never commit them; see [Configuration](configuration.md).

```dotenv
EUROSMS_ID=1-5U8J3C
EUROSMS_KEY=your-integration-key
EUROSMS_TEST_MODE=1
```

## Plain PHP

One factory function, called wherever a service is needed:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use EuroSms\Config;
use EuroSms\EuroSmsService;
use EuroSms\Exception\ConfigException;

function euroSms(): EuroSmsService
{
    static $service = null;

    if (null !== $service) {
        return $service;
    }

    $config = new Config;
    $config->setId(getenv('EUROSMS_ID') ?: '');
    $config->setKey(getenv('EUROSMS_KEY') ?: '');
    $config->setTestMode('1' === getenv('EUROSMS_TEST_MODE'));

    try {
        return $service = new EuroSmsService($config);
    } catch (ConfigException $e) {
        throw new RuntimeException('EuroSMS is not configured.', previous: $e);
    }
}
```

The service holds the state of one send while it is running, so it is not something to share across
concurrent sends in a long-lived process — build one per send there, or keep the container's
instance and send one message at a time, which is what a PHP request does anyway.

## Symfony

`config/services.yaml`:

```yaml
services:
    EuroSms\Config:
        calls:
            - setId: ['%env(EUROSMS_ID)%']
            - setKey: ['%env(EUROSMS_KEY)%']
            - setTestMode: ['%env(bool:EUROSMS_TEST_MODE)%']

    EuroSms\EuroSmsService:
        arguments:
            $config: '@EuroSms\Config'
            $client: '@Psr\Http\Client\ClientInterface'
            $requestFactory: '@Psr\Http\Message\RequestFactoryInterface'
            $streamFactory: '@Psr\Http\Message\StreamFactoryInterface'
            $logger: '@logger'
```

The PSR-18 client is Symfony's own once `symfony/http-client` and a PSR-17 implementation are
installed:

```shell
composer require symfony/http-client nyholm/psr7
```

A service takes it from the container like any other:

```php
namespace App\Notification;

use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;
use EuroSms\Exception\EuroSmsException;
use Psr\Log\LoggerInterface;

final readonly class SmsNotifier
{
    public function __construct(
        private EuroSmsService $euroSms,
        private LoggerInterface $logger
    ) {
    }

    public function notify(string $number, string $text): bool
    {
        try {
            $message = new Message;
            $message->setSenderName('MyShop');
            $message->setContent($text);
            $message->setRecipient(new Recipient($number));

            return [] !== $this->euroSms->sendOne($message)->getSent();
        } catch (EuroSmsException $e) {
            $this->logger->error('SMS not sent.', ['number' => $number, 'reason' => $e->getMessage()]);

            return false;
        }
    }
}
```

The CallBack endpoint is an ordinary controller:

```php
namespace App\Controller;

use EuroSms\CallBack\Acknowledgement;
use EuroSms\CallBack\CallBackParser;
use EuroSms\Exception\CallBackException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EuroSmsCallBackController
{
    #[Route('/callback/euro-sms', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $notification = CallBackParser::parse($request->query->all() + $request->request->all());
        } catch (CallBackException) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // store it before confirming — an unconfirmed notification is pushed again
        return new Response(
            Acknowledgement::of($notification)->toPlain(),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain']
        );
    }
}
```

## Laravel

`config/services.php`:

```php
<?php

return [
    // the services already there

    'eurosms' => [
        'id' => env('EUROSMS_ID'),
        'key' => env('EUROSMS_KEY'),
        'test' => (bool)env('EUROSMS_TEST_MODE', false),
    ],
];
```

A singleton in `AppServiceProvider::register()`:

```php
use EuroSms\Config;
use EuroSms\EuroSmsService;
use Illuminate\Support\Facades\Log;

$this->app->singleton(EuroSmsService::class, static function (): EuroSmsService {
    $config = new Config;
    $config->setId((string)config('services.eurosms.id'));
    $config->setKey((string)config('services.eurosms.key'));
    $config->setTestMode((bool)config('services.eurosms.test'));

    return new EuroSmsService($config, logger: Log::channel('eurosms'));
});
```

Laravel's log channels are PSR-3, so `Log::channel()` is handed over as it is. The default client
of the library is Guzzle, which Laravel ships with anyway, so there is nothing to wire for the
transport unless a client of your own is wanted.

The CallBack route, in `routes/web.php`:

```php
use EuroSms\CallBack\Acknowledgement;
use EuroSms\CallBack\CallBackParser;
use EuroSms\Exception\CallBackException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/callback/euro-sms', static function (Request $request) {
    try {
        $notification = CallBackParser::parse($request->all());
    } catch (CallBackException) {
        return response('', 400);
    }

    return response(Acknowledgement::of($notification)->toPlain())
        ->header('Content-Type', 'text/plain');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
```

**The CallBack endpoint is called by the gateway, not by a browser**, so it carries no session and
no CSRF token. Exempt it, and guard it by the address instead — the notification carries a uuid
that a send handed out, so an unknown one is easy to refuse.

## Nette

`config/services.neon`:

```neon
parameters:
    eurosms:
        id: ::getenv(EUROSMS_ID)
        key: ::getenv(EUROSMS_KEY)

services:
    euroSmsConfig:
        create: EuroSms\Config
        setup:
            - setId(%eurosms.id%)
            - setKey(%eurosms.key%)
            - setTestMode(true)

    euroSms: EuroSms\EuroSmsService(@euroSmsConfig)
```

Tracy is not a PSR-3 logger, so nothing is handed over as one here. Where the calls are wanted in
the log, install a PSR-3 logger — `contributte/monolog` wires Monolog into the container — and add
it to the service:

```neon
    euroSms: EuroSms\EuroSmsService(@euroSmsConfig, logger: @Psr\Log\LoggerInterface)
```

The CallBack presenter:

```php
namespace App\Presenters;

use EuroSms\CallBack\Acknowledgement;
use EuroSms\CallBack\CallBackParser;
use EuroSms\Exception\CallBackException;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;

final class EuroSmsCallBackPresenter extends Presenter
{
    public function actionDefault(): void
    {
        try {
            $notification = CallBackParser::parse($this->getHttpRequest()->getQuery());
        } catch (CallBackException) {
            $this->getHttpResponse()->setCode(400);
            $this->sendResponse(new TextResponse(''));
        }

        $this->getHttpResponse()->setContentType('text/plain');
        $this->sendResponse(new TextResponse(Acknowledgement::of($notification)->toPlain()));
    }
}
```

## Sending from a queue

A send is a network call with a thirty-second timeout, so it belongs behind a queue wherever the
answer is not needed in the request. What the job carries is the send, not the service:

```php
final readonly class SendSmsJob
{
    /** @param list<string> $numbers */
    public function __construct(
        public array $numbers,
        public string $text,
        public string $sender
    ) {
    }
}
```

The worker builds the message, sends it and decides what to do with each of the three lists:

```php
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\EuroSmsService;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\SendException;

function handle(SendSmsJob $job, EuroSmsService $service, Queue $queue): void
{
    $recipients = new RecipientCollection;

    foreach ($job->numbers as $number) {
        try {
            $recipients[] = new Recipient($number);
        } catch (RecipientException) {
            continue; // an unreadable number never becomes readable, so it is dropped, not retried
        }
    }

    $message = new Message;
    $message->setSenderName($job->sender);
    $message->setContent($job->text);
    $message->setRecipientCollection($recipients);

    try {
        $result = $service->sendOneToMany($message);
    } catch (SendException $e) {
        throw $e; // nothing got through, let the queue retry the whole job
    }

    $denied = [];

    foreach ($result->getDenied() as $entries) {
        foreach ($entries as $entry) {
            if (null !== $entry['reason']) {
                $denied[] = $entry['number']; // the batch never went out, this is worth another try
            }
        }
    }

    if ([] !== $denied) {
        $queue->push(new SendSmsJob($denied, $job->text, $job->sender));
    }
}
```

**Retry the denied numbers, not the job.** A send of ten batches in which one failed has already
delivered the other nine; retrying the whole job sends them a second time and charges for both.
`getDenied()` is exactly the list to rebuild the failed batch out of — see [Errors](errors.md) for
what is worth another try at all.

**One service, one send at a time.** The service keeps the requests and the responses of the send
it is running, so a worker that sends two things at once builds two services.

## Asking after the statuses

`getStatusAny()` is written for a cron: it answers with everything that changed since it was last
called, and each change once. A worker running every few minutes reads them and closes the records
it can:

```php
$status = $service->getStatusAny();

foreach ($status as $report) {
    if (!$report->isFinal()) {
        continue;
    }

    printf("%s: %s\n", $report->getIdentifier() ?? $report->getUuid(), $report->getStatus());
}
```

Where the receipts are wanted the moment they exist rather than on the next tick, register a
CallBack address instead and let the gateway push them — [CallBack notifications](callback.md).

## What goes on from here

* [Configuration](configuration.md) — every collaborator the service takes
* [Logging](logging.md) — what lands in the logger wired above
* [Errors](errors.md) — what to catch, and what is worth sending again
