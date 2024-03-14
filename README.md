# EuroSMS

## Examples

### Send one

```php
use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;

$config = new Config;
$config->setDebugMode(true);
$config->setId('euro-sms-id');
$config->setKey('euro-sms-key');
$config->setTestMode(true);
$config->setDebugMode(true);

if ($config->isDebugMode()) {
    error_reporting(E_ALL);
    ini_set('display_errors', 'on');
    ini_set('display_startup_errors', 'on');
}

$euroSmsService = new EuroSmsService($config);

$recipient = new Recipient('0901 000 000');

$message = new Message;
$message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
$message->setSenderName('MyName');
$message->setContent('Hello world!');
$message->setRecipient($recipient);

$response = $euroSmsService->sendOne($message);

/**
 * denied messages
 */
dump($response->getDenied());

/**
 * failed messages
 */
dump($response->getFailed());

/**
 * sent messages
 */
dump($response->getSent());

/**
 * $response->getDenied(), $response->getFailed(), $response->getSent() - all 3 got same response format
 */
$resp = [
    '4125eadc-68ba-41b9-929b-2563ecd95421' => [ // request and response id, generated locally
        [
            'number' => '0901000000',
            'uuid' => [
                '55b42acb-6862-43a3-81ae-1fa354ba3c6e', // if message is long and did not fit into one sms, there are multiple response ids, you can use them later to check message status in euro sms service
                'c8a8bc0f-76ad-4133-9522-75c91b6f2ee8'
            ]
        ]
    ]
];

/**
 * message that was sent
 */
dump($response->getMessage());

/**
 * see what requests was build and prepared for send
 */
dump($response->getRequestCollection());

/**
 * see what responses was received from euro sms service
 */
dump($response->getResponseCollection());
```

### Send one to many

```php
use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;

$config = new Config;
$config->setDebugMode(true);
$config->setId('euro-sms-id');
$config->setKey('euro-sms-key');

$euroSmsService = new EuroSmsService($config);

$recipientCollection = new RecipientCollection;
$recipientCollection->offsetSet('some key', '0901 000 000');
$recipientCollection->offsetSet(null, new Recipient('0901 000 001'));
$recipientCollection[] = new Recipient('+421901000002');
$recipientCollection[] = new Recipient('0901000003');
$recipientCollection[] = new Recipient('00421 0901 000 004');

$message = new Message;
$message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
$message->setSenderName('MyName');
$message->setContent('Hello world!');
$message->setRecipientCollection($recipientCollection);

$response = $euroSmsService->sendOneToMany($message);

dump($response);
```

### Send many to many

```php
use EuroSms\Config;
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\EuroSmsService;

$config = new Config;
$config->setDebugMode(true);
$config->setId('euro-sms-id');
$config->setKey('euro-sms-key');

$euroSmsService = new EuroSmsService($config);

$messageCollection = new MessageCollection;

$recipientCollection = new RecipientCollection;
$recipientCollection->offsetSet('some key', '0901 000 000');
$recipientCollection->offsetSet(null, new Recipient('0901 000 001'));
$recipientCollection[] = new Recipient('+421901000002');
$recipientCollection[] = new Recipient('0901000003');
$recipientCollection[] = new Recipient('00421 0901 000 004');

$message1 = new Message;
$message1->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
$message1->setSenderName('MyName');
$message1->setContent('Hello world!');
$message1->setRecipientCollection($recipientCollection);

$messageCollection->offsetSet($message1->getId(), $message1);

$recipientCollection = new RecipientCollection;
$recipientCollection[] = new Recipient('0901 000 000');
$recipientCollection[] = new Recipient('00421 0901 000 001');

$message2 = new Message;
$message2->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
$message2->setSenderName('MyName 2');
$message2->setContent('Hello world 2!');
$message2->setRecipientCollection($recipientCollection);

$messageCollection[$message2->getId()] = $message2;

$response = $euroSmsService->sendManyToMany($messageCollection);

dump($response);
```
