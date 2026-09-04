# Recipients

A recipient is a phone number and, where the calling system has one, the key it knows that number
by. `sendOne()` takes one, `sendOneToMany()` and `sendManyToMany()` take a collection of them.

```php
use EuroSms\Entities\Recipient\Recipient;

$recipient = new Recipient('0901 000 000');

$recipient->getNumber();      // +421901000000, E.164
$recipient->getNumberClean(); // 421901000000, what goes on the wire and into the signature
$recipient->getNumberOrig();  // '0901 000 000', exactly as it was handed over
```

## The number

Every number is read into E.164 — a plus and the digits, nothing between them. What is handed over
may be written any way a number is written: with spaces, with a leading zero, with the country code
in either form.

```php
new Recipient('0901 000 000');       // +421901000000
new Recipient('0901000000');         // +421901000000
new Recipient('+421 901 000 000');   // +421901000000
new Recipient('00421901000000');     // +421901000000
```

A number that cannot be read as a number at all is a `RecipientException` with
`ERROR_WRONG_NUMBER`, thrown by the constructor, so nothing half-formed ever reaches a message.

```php
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Exception\RecipientException;

try {
    $recipient = new Recipient($numberFromTheForm);
} catch (RecipientException $e) {
    // RecipientInterface::ERROR_WRONG_NUMBER, and the parse failure of the underlying library
    // as $e->getPrevious()
    $errors[] = sprintf('%s is not a phone number.', $numberFromTheForm);
}
```

**A number in local form needs its country.** The second argument says which one, and it defaults
to `RecipientInterface::RECIPIENT_DEFAULT_COUNTRY`, `SK`. A number already in international form
carries its own and the argument changes nothing.

```php
new Recipient('0601 000 000', 'CZ');  // +420601000000
new Recipient('+420601000000');       // +420601000000, the same number, no country needed
```

## A key of your own

A recipient can carry the key the calling system knows it by, chapter 9.2. Given one, it travels as
the object `{"r": 421903622237, "f": "…"}` rather than as a bare number, and the gateway repeats
that key on every delivery report it sends back. Without it a receipt can only be matched by phone
number, and two messages written to the same number in a row — an order confirmation and the
verification code behind it — cannot be told apart at all.

```php
$key = '21C334C7-CD7E-45D7-AA88-FC9C58A7FF47';

$recipient = new Recipient('0903 622 237', RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, $key);

$recipient = new Recipient('0903 622 237');
$recipient->setIdentifier($key);  // the same thing, named afterwards
$recipient->hasIdentifier();      // true
$recipient->getIdentifier();      // 21C334C7-CD7E-45D7-AA88-FC9C58A7FF47
```

The key is whatever the calling system files its records under and it is sent untouched; an empty
one is read as no key at all. It changes nothing else about the send: the signature is composed of
the phone numbers, chapter 9.2.2, so a message carrying keys is signed exactly as it was without
them, and everything a send is reported back by stays the plain number.

Where the key comes back is in [Delivery status](delivery-status.md), as
`DeliveryReport::getIdentifier()`.

## Collections

`RecipientCollection` is an `ArrayAccess`, `Countable`, `JsonSerializable` list, and it holds
`Recipient` objects — nothing else. A bare string put into it is not read into one; the send fails
on it later instead of at the line that wrote it.

```php
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;

$recipients = new RecipientCollection;

$recipients[] = new Recipient('0901 000 000');
$recipients[] = new Recipient('+421901000002', RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, 'order-4471');
$recipients->offsetSet('customer-88', new Recipient('0901 000 001'));

count($recipients);         // 3
$recipients->all();         // the Recipient objects, keyed as they were set
$recipients['customer-88']; // the one filed under that key
```

The two shapes stand side by side in one send: a recipient carrying no key of its own is written
out as the plain number it always was.

**A number written twice is sent once.** `sendOneToMany()` reduces the collection to its unique
recipients before it builds anything, so a list assembled out of two queries does not charge twice
for whoever is on both. What it hands back to the message afterwards is keyed by the recipient
itself, so an offset of your own does not survive that step — carry your key on the recipient,
where the gateway repeats it back to you.

**Two recipients are the same one when the number and the key are both the same.** The key exists
precisely because one number is written to twice in a row — an order confirmation and the
verification code behind it — so those two are two sends and not one:

```php
$recipients[] = new Recipient('0901 000 000', identifier: 'order-4471');
$recipients[] = new Recipient('0901 000 000', identifier: 'code-4471');
// two messages, two receipts, told apart by the key the gateway hands back

$recipients[] = new Recipient('0901 000 000');
$recipients[] = new Recipient('+421901000000');
// one message — neither carries a key, and they are the same number
```

## What goes on from here

* [Send one](send-one.md) — one message to one of these
* [Send one to many](send-one-to-many.md) — one message to a collection of them
* [Errors](errors.md) — every code the exceptions above carry
