# The message

`EuroSms\Entities\Message\Message` is what every send is built out of. It carries the text, the
sender, the recipients, the flags and — where a send is spread over time — when it starts and how
long it takes. The same object is handed to `sendOne()` and `sendOneToMany()`; a `MessageCollection`
of them is handed to `sendManyToMany()`.

Everything on it is checked where it is set, not where it is sent. A sender name eleven characters
too long throws from `setSenderName()`, so the mistake is reported at the line that made it.

```php
use EuroSms\Entities\Message\Message;
use EuroSms\Entities\Recipient\Recipient;

$message = new Message;
$message->setSenderName('MyShop');
$message->setContent('Your order is on its way.');
$message->setRecipient(new Recipient('0901 000 000'));

$message->getId(); // a UUID the library gives every message, used to key it in a collection
```

## The sender name

Chapter 9.2.1 gives the sender two shapes and nothing in between: a text of at most eleven
characters written out of letters, digits, a hyphen, a space and a dot, or a phone number in full
international form. Which of the two a name is, is read off the name itself. Written with anything
but digits, a space included, it is a text. Written entirely in digits it is a number when it
really is one some country hands out, and a text when it is not — digits are among the characters
the chapter lets a text sender be written out of, so a short code is a sender as good as a word is.

```php
$message->setSenderName('My-Shop v.2');     // a text sender, kept as it was written
$message->setSenderName('8877');            // a short code, kept as it was written
$message->setSenderName('421905123456');    // a number, kept as it was written
$message->setSenderName('+421905123456');   // '421905123456' — the plus is normalised away
$message->setSenderName('00421905123456');  // '421905123456' — and so is the double zero
```

Every refusal is a `MessageException` carrying a code of its own:

| Written             | Code                             | Why                                       |
|---------------------|----------------------------------|-------------------------------------------|
| `''`                | `ERROR_SENDER_NAME_IS_EMPTY`     | there is no sender to send under          |
| `'Zahradnictvo'`    | `ERROR_SENDER_NAME_TOO_LONG`     | twelve characters, the limit is eleven    |
| `'421 905 123 456'` | `ERROR_SENDER_NAME_TOO_LONG`     | the spaces make it a text, and it is long |
| `'Zahradníc'`       | `ERROR_SENDER_NAME_INVALID`      | an accented letter is not one a sender may carry |
| `'My_Shop'`         | `ERROR_SENDER_NAME_INVALID`      | and neither is an underscore              |
| `'999999999999999'` | `ERROR_SENDER_NAME_NOT_A_NUMBER` | no country hands it out, and it is too long to be a text |
| `'+1188'`           | `ERROR_SENDER_NAME_NOT_A_NUMBER` | the plus says a number was meant, and it is none |

```php
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Exception\MessageException;

try {
    $message->setSenderName($senderFromTheForm);
} catch (MessageException $e) {
    if (MessageInterface::ERROR_SENDER_NAME_TOO_LONG === $e->getCode()) {
        // eleven characters is the whole budget, MessageInterface::MAX_SENDER_NAME_LENGTH
    }
}
```

**Nothing is shortened.** Up to `2.0.0` a name over the limit was cut and the message went out
under something the caller never wrote. The gateway answers a sender it cannot write with
`WRONG_SENDER`, in English and with nothing saying which input was at fault — these exceptions are
what replaced that.

The eleven characters are the limit of the text sender and of nothing else. A number is as long as
the country handing it out makes it, which is why `421905123456` passes at twelve digits.

**A number in local form is never completed and never guessed at.** A sender carries no country of
its own the way a recipient does, so `'0905123456'` is not read as a Slovak number — it is ten
characters out of the set the chapter allows, so it goes out as the text sender those digits spell.
Only digits the text rules cannot take either are refused: over the eleven characters, or written
with the plus or the double zero that leaves no doubt a number was what was meant.

## The text

```php
$message->setContent('Hello world!');
$message->setContent('0');       // a message whose whole text is "0" is a message with a text

$message->setContent('');        // MessageException, ERROR_CONTENT_IS_EMPTY
$message->setContent("  \n  ");  // MessageException, ERROR_CONTENT_IS_EMPTY
```

The text is kept exactly as it was handed over, whitespace and all. Nothing is trimmed off it: the
signature of chapter 9.2.2 is composed over the text as it goes on the wire, so a text quietly
changed here would be one the gateway finds its own signature no longer matches.

## Which alphabet it goes out in

An SMS is written in the GSM 03.38 alphabet, chapters 12.1 and 12.2, and one message holds 160
characters of it. A text carrying anything the alphabet has no place for — most of the accented
letters of Slovak among them — has to go out with diacritics instead, and then a message holds 70.
Nothing needs to be set for that: the library reads the text and decides.

```php
$message->setContent('Dakujeme za objednavku.');
$message->isUnicode();   // false — every character is one the gateway can write

$message->setContent('Ďakujeme za objednávku.');
$message->isUnicode();   // true — and the message now holds 70 characters
```

The decision can be taken away from it, and it stays taken until it is handed back:

```php
$message->setUnicode(true);    // with diacritics whatever the text is
$message->setUnicode(false);   // as a plain message whatever the text is
$message->setUnicode();        // back to reading the text
```

`setUnicode(true)` on a plain text costs nothing but the shorter limit. `setUnicode(false)` on a
text the alphabet cannot carry is the one that costs something: the gateway replaces every
character it has no place for with a question mark, chapter 14.1, so the message arrives mangled
and is charged all the same.

## How long a message is

How many messages the text falls into — what the gateway charges — is counted in the positions of
the alphabet, not in bytes and not in characters.

| Alphabet        | One message | Every part of a longer one |
|-----------------|-------------|----------------------------|
| GSM 03.38       | 160         | 153                        |
| With diacritics | 70          | 67                         |

The ten characters of the extension table of chapter 12.2 take two positions each, so ten euro
signs eat twenty of the 160. A message with diacritics is counted in positions sixteen bits wide,
and everything above U+FFFF takes two of them, so thirty-six emoji are already two messages.

Chapter 9.2.2 recommends staying within four segments, `GatewayInterface::MAX_MESSAGE_SEGMENTS`.
Nothing here refuses a longer message — chapter 14.1 says the gateway takes it and charges every
part — so the caller is told instead:

```php
$result = $service->sendOne($message);

$result->hasWarnings(); // true
$result->getWarnings(); // ['The text falls into 5 segments, chapter 9.2.2 recommends staying within 4.']
```

A send that is fine says nothing, so an empty list is the usual answer. The same warning raised
about two of the batches a send was split into is listed once.

## Flags

The `flgs` value of chapter 14.1 is a set of single bits, and `Message::getFlag()` is the set.

```php
$flag = $message->getFlag();

$flag->addReceipt();       // ask for a delivery receipt
$flag->addPriorityHigh();  // or addPriorityLow()
$flag->addViber();         // see viber.md

$flag->getValue();         // the composed flgs value, the bits combined bitwise
$flag->getFlags();         // the FlagEnum cases this message goes out under
Flag::all();               // every flag the gateway knows, chapter 14.1
```

Reading a flag set leaves it as it was, so `getFlags()` on a message nobody set a flag on answers
`[FlagEnum::DEFAULT]` without turning that into something the message now carries. `Flag::all()` is
static because it says nothing about any one message — it is the whole of the table below.

| Flag            | Value | Set by                                      |
|-----------------|-------|---------------------------------------------|
| `DEFAULT`       | 0     | `addDefault()`                              |
| `RECEIPT`       | 1     | `addReceipt()`                              |
| `LONG`          | 2     | the library, for a message over one segment |
| `UNICODE_SHORT` | 4     | the library, for a text with diacritics     |
| `HIGH_PRIORITY` | 8     | `addPriorityHigh()`                         |
| `LOW_PRIORITY`  | 32    | `addPriorityLow()`                          |
| `VIBER_ONLY`    | 128   | `addViberOnly()`                            |
| `VIBER_PROMO`   | 256   | `addViberPromo()`                           |
| `VIBER`         | 512   | `addViber()`                                |

**The length and the alphabet flags set themselves.** They are worked out from the text while the
request is built, so there is nothing to keep in step by hand.

A value read out of chapter 14.1 as it is printed there can be handed over whole — six is the long
flag together with the diacritics flag, and it is taken apart into the two:

```php
$flag->setFlags([6]);
$flag->getFlags(); // [FlagEnum::LONG, FlagEnum::UNICODE_SHORT]
```

## Spreading a send over time

A campaign for a thousand numbers does not have to leave in one peak. `setScheduleDateTime()` says
when the sending starts, `setDuration()` how long it is spread over, written as `hh:mm`, and
`setEnd()` when it is to be over. A length written any other way is refused where it is set.

```php
use DateTime;
use DateTimeZone;

$message->setDateTimeZone(new DateTimeZone('Europe/Bratislava'));
$message->setScheduleDateTime(new DateTime('2026-01-10 12:00'));
$message->setDuration('01:00');
$message->setEnd(new DateTime('2026-01-10 13:00'));

$message->setDuration('1:00'); // MessageException, ERROR_DURATION_INVALID — hours take two digits
```

Both moments are read in the time zone of the message, so set the zone before them, or hand one to
`setScheduleDateTime()` as its second argument. A single message is scheduled with `sch`, a group
send with `start`, which is what chapter 9.3.3 asks for.

## Time to live

`setTtl()` is how long, in seconds, the gateway keeps trying before it gives the message up.

```php
$message->setTtl(3600);
```

**A transaction carries neither of these on a message.** Chapter 9.3.1 lists neither `sch` nor
`ttl` among the fields of a message inside a transaction, and the root has no default for either,
so `sendManyToMany()` refuses a message given `setTtl()`, `setScheduleDateTime()` or `setEnd()`
rather than sending it with what it asked for quietly missing. See
[Send many to many](send-many-to-many.md).

## What goes on from here

* [Recipients](recipients.md) — the numbers the message is addressed to
* [Viber](viber.md) — a Viber variant of the same message
* [Errors](errors.md) — every code the exceptions above carry
