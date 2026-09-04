# Changelog

| Version | Released   | Description                                                                                                 |
|---------|------------|-------------------------------------------------------------------------------------------------------------|
| 2.0.0   | 2026-09-02 | Php 8.5, bitwise flags, m2m in one request, enums, TLS verify from config, delivery status, Viber, CallBack, send and delivery statuses as enums with descriptions, root request parameters (response format, spreading a send over time, transaction defaults), a key of one's own on a recipient, the numbers a partly accepted group send refused, the segment count of a concatenated message, a flags value that is several flags at once, a gateway layer built on composition instead of inheritance, one ancestor for every exception, a PSR-18 transport, TLS verification on by default, a message text of "0" no longer dropped, a transaction refusing a message it cannot carry whole, the alphabet of a message worked out from its text, segments counted in GSM 03.38 septets, a configuration without an integration refused, a request without a text refused, sender names and message texts checked before the send, an empty message collection refused, a text over the recommended four segments reported instead of stopped, a delivery report time read as documented, a PSR-3 logger the calls and the failures are written into with the key and the signature masked out, the debug mode gone, a send exception carrying the status and the body of the answer that refused the call, a recipient read into E.164, a transaction with no message identifier to hand out, a refusal that lists nothing still reported with its reason, a message of a transaction refused when it names no sender or a duration another message contradicts, the numbers of a refused group request named, the batches of one send posted at the same time with the concurrency configurable, one failed batch no longer stopping the rest, a batch that never went out denied with the reason beside every number, the refusal of a batch readable where nothing was thrown, every log record naming the batch it is about, a numeric short code sent as the text sender it is, a refused number reported once, an accepted transaction failing nothing, a partly accepted answer read as the sent one it is, whatever the transport itself throws still a send exception, an unreadable body failing its own batch alone, a recipient told apart by its key as well as its number, a bulk status call repeatable under its transaction number, an empty id or key refused where it is set, a duration refused where it cannot be honoured, each transaction of a split collection carrying its own defaults, a failed send thrown as its first batch, every result reporting a number as its digits, a logged body masked from the array it was composed as, a message that signs itself without building its own request, a flag set that reading does not change |
| 1.1.0   | 2024-05-22 | Update dependencies and allow php 8.4 install                                                               |
| 1.0.0   | 2024-03-15 | Initial commit with basic functions                                                                         |

## 2.0.0 — incompatible changes

Version 2.0.0 changes the interface of the library. The entry points `sendOne()`, `sendOneToMany()`
and `sendManyToMany()` are the ones they always were; what changed is everything below them.

What this means for code that only ever used the library from the outside, and how to move it, is
in [docs/upgrading-2.0.md](docs/upgrading-2.0.md). What follows is the whole list.

* **PHP 8.5 is required.** The library is written against its syntax and cannot be installed on an
  older runtime.
* **The transport is PSR-18.** `EuroSmsService::__construct()` takes
  `Psr\Http\Client\ClientInterface` where it used to take `GuzzleHttp\ClientInterface`, followed by
  an optional `Psr\Http\Message\RequestFactoryInterface` and `StreamFactoryInterface`. A caller
  handing over nothing still gets the Guzzle client the library ships with. The certificate
  verification and the timeout of `Config` configure that default client only — a client handed
  over from outside brings its own. A PSR-18 client answers a refused call rather than throwing at
  it, so the library reads the status itself: an answer of 400 or worse is a `SendException`,
  whichever client answered it.
* **Every exception descends from `EuroSms\Exception\EuroSmsException`.** One `catch` holds
  whatever the library throws; the seven concrete classes are unchanged and can still be caught on
  their own. The ancestor is abstract and is never thrown itself.
* **No request class inherits from another one.** `RequestOneToMany` no longer extends
  `RequestOne`: both extend `RequestMessageAbstract`, which carries the text, the flags, the Viber
  variant and the signature, and leaves the recipients to each of them. `RequestOneToMany::getRecipient()`
  and `setRecipient()` are gone — a request addressed to a list of numbers has no single recipient
  to hand out.
* **`RequestInterface` no longer declares `getRecipient()`.** It moved to the new
  `RequestRecipientInterface`, which only `RequestOne` implements. Code that takes any request and
  asks it for its one recipient has to check for that interface first.
* **`RequestInterface::getRecipients()` returns `list<int|string>`** instead of `int[]`.
* **`RequestManyToMany::addRequest()` takes `RequestMessageAbstract`** instead of `RequestOne`, and
  `getRequests()` returns those.
* **`ResultAbstract` carries no message.** The result over one message descends from
  `ResultMessageAbstract` and answers `getMessage()`; `ResultManyToMany` carries the collection and
  answers `getMessageCollection()`. Neither can reach for a property it was never given.
* **The certificate of the gateway is checked by default.** `EuroSmsInterface::REQUEST_VERIFY_HOST`
  is `true` where it used to be `false`. An installation that really has to talk to a host with a
  certificate of its own turns it off with `Config::setRequestVerifyHost(false)`; the messages and
  the signatures of chapter 9.2.2 travel over that connection, so it is no longer off by default.
* **A message whose whole text is `"0"` keeps its text.** The body of a request left out every
  field that merely read as false, so a text of `"0"` was dropped while the signature had been
  composed over it. Only a field that was never given anything is left out now. The flags are
  still left out when they are the plain default of zero.
* **`sendManyToMany()` refuses a message it cannot carry whole.** Chapter 9.3.1 lists neither `sch`
  nor `ttl` among the fields of a message of a transaction and the root has a default for neither,
  so a message given `setScheduleDateTime()`, `setEnd()` or `setTtl()` used to go out at once with
  its schedule silently gone. It now raises a `RequestException` with the code
  `RequestInterface::ERROR_MESSAGE_NOT_SCHEDULABLE`. `setDuration()` is unaffected — `dur` is what
  the root does carry.
* **The alphabet a message goes out in is worked out from its text.** `Message::setUnicode()` was a
  plain switch standing at off, so a text with diacritics nobody had flipped it for went out as a
  plain message and the gateway replaced every accented character with a question mark, chapter
  14.1 — while flipping it on a text that did not need it cut the limit from 160 characters to 70
  and charged the segments that cost. It now takes three answers instead of two: `null`, the one a
  message starts with, leaves it to the text; `true` and `false` force it either way, and
  `setUnicode()` with nothing at all hands the decision back to the text. `Message::isUnicode()`
  answers what the message will really be sent as, so one carrying diacritics answers `true` where
  it used to answer `false`. Forcing a text the alphabet cannot carry into it still loses those
  characters — that is now something the caller asked for rather than the default.
* **How many messages a text falls into is counted in septets, not in bytes.** A plain text was
  measured with `strlen()`, so every accented character of the GSM alphabet counted twice over and
  a euro sign three times: 160 characters, five of them two bytes long, were charged as two
  messages. The new `EuroSms\Helpers\Encoder` counts the alphabet of chapters 12.1 and 12.2
  instead — one septet per character, two for each of the ten of the extension table
  (`^ { } [ ] ~ | \ €` and the form feed), so ten euro signs no longer pass for ten positions when
  they take twenty. `RequestMessageAbstract::setContent()` takes `?bool $isUnicode = null` where
  it took `bool $isUnicode = false`; a caller handing over nothing gets the detection now and no
  longer a plain message by default.
* **A message with diacritics is counted in positions, not in characters.** Those positions are
  sixteen bits wide, so everything above U+FFFF — emoji, most of all — takes two of them. Counting
  the characters let a text of forty emoji pass for one message of seventy, so it went out without
  the long flag of chapter 14.1 while the gateway split it in two and charged for both.
* **`ext-mbstring` is a declared dependency.** The library has always leant on it and never said
  so; the alphabet of `Encoder` now sits on every send path, so an installation without the
  extension has to fail at `composer install` rather than at the first message.
* **`GatewayInterface::MAX_MESSAGE_SEGMENTS`** names the four segments chapter 9.2.2 recommends
  staying within. Nothing refuses a longer message — chapter 14.1 says the gateway takes it and
  charges every part of it — but the caller is told: `getWarnings()` on the built request, and on
  the whole result, lists what was worth saying about a send without stopping it.
* **`Config::getId()` returns `?string`** where it returned `string`, the way `getKey()` already
  did. A configuration that was never given an integration id used to pass the constructor of the
  service and read an uninitialized property at the first send, which is an `Error` and none of
  the exceptions this library can be caught by. `EuroSmsService::__construct()` now refuses such a
  configuration with a `ConfigException` next to the one it already threw for a missing key, and
  building a request on one raises the same exception.
* **A request without a text raises `RequestException`.** `getData()` guarded the recipient and
  the sender name and then read the content property straight off, so a request that had been
  given everything but its text died on an `Error`. It now says so with the code
  `RequestInterface::ERROR_MESSAGE_NOT_DEFINED`, the one `getAmount()` has always used, and
  `RequestMessageAbstract::getContent()` answers the same way.
* **The sender name is checked, not cut.** `Message::setSenderName()` and
  `RequestManyToMany::setDefaultSenderName()` cut to the eleven characters of chapter 9.2.1 with
  `substr()`, so a name carrying diacritics ended in the middle of a UTF-8 sequence and the whole
  request body was then no longer encodable. Neither cuts anything now: chapter 9.2.1 gives the
  sender two shapes — a text of at most eleven characters out of letters, digits, a hyphen, a space
  and a dot, or a phone number in full international form — and anything else is a `MessageException`
  carrying the code that says which of the four things went wrong,
  `MessageInterface::ERROR_SENDER_NAME_IS_EMPTY`, `_TOO_LONG`, `_INVALID` or `_NOT_A_NUMBER`. A
  name written entirely in digits is read as the number, and the eleven characters are not its
  limit; a `+` or a leading `00` in front of it is normalised away, a number in local form is
  refused. Both entry points go through the new `EuroSms\Helpers\SenderName`.
* **A message with no text raises `MessageException`.** `Message::setContent()` took whatever it
  was given and the empty string was only noticed by the request, or by the gateway answering
  `EMPTY_MESSAGE`. An empty text and one made of nothing but whitespace are now refused where they
  are written, with `MessageInterface::ERROR_CONTENT_IS_EMPTY`. The text itself is still stored
  exactly as handed over — nothing is trimmed off it, because the signature of chapter 9.2.2 is
  composed over the text as it goes on the wire.
* **A many-to-many send with no message raises `MessageException`.** An empty `MessageCollection`
  used to be built into a transaction, posted and answered with a tally of nothing.
  `Percolator::createRequestManyToMany()` refuses it with
  `MessageInterface::ERROR_MESSAGE_COLLECTION_IS_EMPTY`. A collection over the thousand messages of
  chapter 9.3.8 is still split into several transactions and every message is still sent — that
  send is allowed, it merely does not fit into one request.
* **`RequestInterface` declares `getWarnings()` and `hasWarnings()`.** An implementation of the
  interface that is not built on `RequestAbstract` has to answer them; everything shipped here
  already does. `ResultAbstract::getWarnings()` and `ResultAbstract::hasWarnings()` gather them
  over every request a send was split into, and a transaction answers both off the messages it
  carries.
* **`GatewayInterface::DURATION_PATTERN` ends where the value ends.** It gained the `D` modifier,
  the same one the two new sender patterns carry: without it PCRE lets `$` match in front of a
  closing newline, so `setDuration("01:00\n")` passed the check and the newline travelled into
  `dur`. Such a value now raises the `MessageException` or `RequestException` it always should
  have.
* **A delivery report time is read as the documentation states it.** `DeliveryReport` handed
  whatever came off the wire to `createFromFormat()` without anchoring it or reading it back, so a
  `dlr_time` of `2026-13-45 99:99:99` rolled over into a moment the gateway never named and a
  value carrying no time of day picked up the time of the run. Such a field is now read as if it
  had not been stated, which is what the rest of the class already did with a malformed one.
* **The library writes into a PSR-3 logger.** `EuroSmsService::__construct()` takes an optional
  `Psr\Log\LoggerInterface` as its fifth argument, and `psr/log` is a declared dependency.
  Everything that goes out and everything that comes back is written at the `debug` level, under
  `EuroSMS request.` and `EuroSMS response.`; a call that did not go through is written at `error`
  under `EuroSMS call failed.`. A caller handing over no logger writes nowhere, exactly as before —
  the library holds no logger of its own and touches neither the error log nor the output. The
  integration key and the signature of chapter 4.2 never reach a record: `sgn` is masked in the
  body of a send and in every message of a transaction, chapter 9.3.8, and so is the signature a
  bulk status call carries in the last segment of its path, chapters 9.4.3 and 9.4.4. The status
  of one message is asked without a signature, chapter 9.4.1, so that path is written out in full.
  The answer is read for the record and the stream is put back where it was found, so the result
  is still built out of it; a body that cannot be rewound is not read and the record carries
  `null` instead.
* **`Config::setDebugMode()` and `Config::isDebugMode()` are gone.** Nothing inside the library
  ever read them — the switch did whatever the calling code did with the answer, which was usually
  `display_errors`, and inside the library the debug mode had no effect of any kind. Code that
  used it that way sets `error_reporting()` and `ini_set()` on its own; code that reached for it
  to see what actually went to the gateway hands over a logger instead. Binding it to the level
  the records are written at was the other way out and was not taken: a PSR-3 logger already
  decides which levels it writes, and a second switch in front of that one only makes a log go
  missing for a reason nobody looks for.
* **`SendException` carries the status and the body of the answer that refused the call.**
  `getHttpStatus()` and `getResponseBody()` answer what the gateway said; the status was already
  the code of the exception and the body used to be thrown away with the response, which is the
  half that names the error, chapter 11. A call that never reached the gateway answers `null` to
  both and keeps whatever the transport stated as its own message and code. The constructor takes
  the two as its fourth and fifth arguments, after `$previous`, so anything constructing the
  exception the way it always did is unaffected.
* **A recipient is read into E.164 and not into the readable international form.** `Recipient`
  asked libphonenumber for the international format and took the spaces back out of it, but that
  format is the one a country writes its numbers in for people to read, and a good half of the
  world separates the groups with hyphens — the United States, Canada and Russia among them. The
  hyphens stayed, and `getNumberClean()` casts to an int, so `+1 212 555 0100` was addressed and
  signed as `1212`. `getNumber()` now answers `+12125550100`, which is what its own documentation
  always said it did.
* **`RequestInterface::getMessageId()` returns `?string`.** A transaction is built for a whole
  collection and is never given a message identifier, chapter 9.3.8, so reading one off the
  interface was a fatal `Error` over a typed property that had never been written to — the very
  thing `getDuration()`, `getContent()` and `getRecipient()` all guard against. It answers `null`
  there now. An implementation of the interface that is not built on `RequestAbstract` has to
  widen its own return type.
* **A refusal that lists nothing is still reported with its reason.** `ResponseAbstract` read the
  errors out of `err_list` alone, and a single send states its refusal as `err_code` with
  `err_desc` beside it and no list at all, chapter 9.3.2. `ResultOne::getFailed()` therefore
  handed back a message that had failed with an empty `error`, while the reason sat unread in the
  body. The top-level pair is now read when the list carries nothing and the answer is a refusal;
  a refusal naming no description of its own is reported with the one chapter 13.1 gives its code.
  The code an accepted message carries is the acceptance and is still not an error.
* **A message of a transaction that names no sender raises `MessageException`.** The sender the
  whole group falls back to, `dsndr` of chapter 9.3.8, was taken from whichever message happened
  to name one first — so a message that named none went out, and was signed, under the name of a
  different message entirely. Such a message is refused with
  `MessageInterface::ERROR_SENDER_NOT_DEFINED`, the code a single send has always refused one
  with. Messages that all name the same sender are unaffected: that name is still written once
  into the root and left off the messages themselves.
* **Two messages of one transaction asking for two different durations raise `RequestException`.**
  There is one `dur` and it belongs to the root, chapter 9.2.1, so the first was kept and every
  later, different one was dropped without a word — the very thing a schedule is refused for. The
  new code is `RequestInterface::ERROR_DURATION_AMBIGUOUS`. A message naming none still falls back
  to the length the root carries, and messages agreeing on one still travel together.
* **`ResultOneToMany::getFailed()` names the numbers.** A request the gateway refused as a whole
  reported its error with no `number` beside it, while `ResultOne` and `ResultManyToMany` both
  filled one in, so a caller reading `array_column($result->getFailed(), 'number')` lost every
  recipient of a refused request. Every number of the request is now listed with the error the
  answer named; a number the answer itself named as wrong stays denied and is not counted twice.
* **The batches of one send go out at the same time.** `sendOneToMany()` and `sendManyToMany()`
  split a send too big for one request into batches of a thousand and posted them one after
  another, each waiting out the round trip of the one before it — ten thousand numbers were ten
  calls in a row and, at the default timeout of thirty seconds, minutes of a blocked caller. They
  now travel together, at most `Config::setRequestConcurrency()` of them at once, five by default;
  fewer than one is refused with a `ConfigException`. A PSR-18 client handed over from outside
  states one call at a time and nothing besides, chapter 3 of the standard, so there the batches
  still go out one after another and are reported in exactly the same way.
* **One failed batch no longer takes the rest of the send with it.** A batch that did not go
  through raised `SendException` at once, so the batches after it were never sent while the ones
  before it were — and the caller was told nothing about which was which. The other batches are
  now sent whatever happens to one of them, and a send is a `SendException` only when not one
  batch of it was answered. The numbers of a batch that failed are in `getDenied()`, never in
  `getFailed()`: those were refused by the gateway, this one was never put to it.
* **A denied entry carries a `reason`.** Every entry of `getDenied()` gained the key next to
  `number` — the message of the failure for a batch that got no answer, `null` for a number the
  gateway itself named as wrong and for a transport that named no reason. A caller reading the
  denied list with `array_column()` or comparing whole entries sees the new key. The failures are
  also readable in one place, on the response collection the result carries:
  `ResponseCollection::hasFailures()`, `getFailures()`, `getFailure($requestId)` and
  `getReasons()`. `count()` and `jsonSerialize()` are unchanged and still hand out the answers
  alone, because a failure is not a body the gateway sent.
* **The refusal of a batch is readable where nothing was thrown.** A gateway answering 400 or worse
  states the refusal in the body of that answer, chapter 11, and both the status and the body used
  to travel out on the `SendException` a send threw. A send of several batches throws nothing while
  the others go through, so the failure is kept whole instead of being reduced to its message:
  `ResponseCollection::getFailure()` and `getFailures()` hand out the `SendException` itself, with
  `getHttpStatus()` and `getResponseBody()` on it, and `getReasons()` is the messages alone for a
  caller that only wants to write a line somewhere.
* **A log record names the batch it is about.** The context of `EuroSMS request.`,
  `EuroSMS response.` and `EuroSMS call failed.` gained a `request` key carrying the identifier the
  result lists are keyed by. The batches of one send are answered in whatever order the gateway
  answers them, so the endpoint — the same string for every batch of the send — no longer told one
  record from another, and the batch a failure belonged to could not be found in the log at all. A
  status call is no batch and carries `null`.
* **A sender written entirely in digits may be a text sender.** Chapter 9.2.1 lists digits among
  the characters the eleven-character text set is written out of, so a short code is a sender as
  good as a word is. Every all-digit sender was read as a phone number and refused with
  `ERROR_SENDER_NAME_NOT_A_NUMBER` when no country hands one out that short, so an integration
  sending under `8877` could not send at all. Digits that are a real number in full international
  form are still normalised into it; digits that are none fall back to the text rules, and only
  what those cannot take either is refused — over the eleven characters, or written with the plus
  or the double zero that leaves no doubt a number was meant. `'0905123456'` is therefore a text
  sender now where it used to be a refusal.
* **A number the gateway refused is reported once.** `ResultOneToMany` compared the numbers of
  `wrong_numbers` against the request's own strictly, and a number arrives as whatever JSON carried
  it, so a refused number quoted as a string was reported as denied and once more as failed.
* **An accepted transaction with no verdict per number fails nothing.** `ResultManyToMany` read a
  missing `result` list as a refusal, so a transaction answered with the tally of chapters 9.3.4.1
  and 9.3.10 reported every recipient as failed — with an empty error beside it, because an
  accepted answer names none.
* **A partly accepted answer is a sent one.** Chapter 9.3.6 enqueues a request and still names the
  numbers it refused, so an `err_code` that is a refusal and an `accepted` list that is not empty
  stand side by side in one answer. `isSent()` read the code alone, so every number the gateway had
  enqueued — and will charge for — was reported as failed. Both are read now, and the refusal the
  code names is still reported in `getErrors()`.
* **What the transport itself throws is a `SendException`.** Anything the request pool threw as it
  was waited on — a middleware that blew up, a decorating client that is no client — travelled out
  of the send as something no `catch` of this library names, against the one exception `sendOne()`,
  `sendOneToMany()` and `sendManyToMany()` promise, with nothing written into the log and every
  batch that had already come back thrown away with it.
* **A stream that cannot be read fails its own batch and no other.** Only `SendException` was
  caught around reading an answer, so a detached or unreadable PSR-7 body threw a `RuntimeException`
  out of the whole send: the batches after it were never filed, the failures were never written and
  the caller got that exception in the place of a partial result.
* **Two recipients of one number under two keys are two sends.** `sendOneToMany()` reduced a
  collection to its unique numbers, so an order confirmation and the verification code behind it,
  both addressed to the same number with the `f` key of chapter 9.2 telling them apart, collapsed
  into one: one of the two messages was never sent and only the surviving key ever reported.
  Uniqueness is now the number together with the key. Two recipients carrying no key are still one.
* **`getStatusAny()` takes a transaction number.** The gateway tells one bulk status call from
  another by it, chapter 9.4.3, and reports a final status once and not again, so a call whose
  answer never arrived took its delivery reports with it — the service always generated the number
  and gave the caller no way to ask the same call twice. It is now the optional first argument;
  a caller that names none is given a generated one, exactly as before.
* **`Config::setId()` and `setKey()` refuse a value that says nothing.** An empty key passed the
  "was it set at all" check the service makes, signed every request into a `WRONG_SIGNATURE` that
  named nothing, and — because the log masks a value by comparing it against the key — wrote every
  empty field of every logged body as `***`. Both setters now raise `ConfigException` the way
  `setRequestConcurrency()` does. An `EUROSMS_KEY` read out of the environment with `?: ''` is
  where this turns up.
* **A transaction refuses a duration it cannot honour.** `dur` belongs to the root of one
  transaction, chapter 9.2.1, and a collection over the thousand messages of chapter 9.3.8 is split
  into several roots that go out at the same time — so three thousand messages asked to leave over
  an hour left as three transactions of a thousand each spread over that hour, three times the rate
  the duration was set for. Such a collection now raises a `RequestException` with the new code
  `RequestInterface::ERROR_DURATION_NOT_SPLITTABLE`. A collection that fits into one transaction is
  unaffected.
* **Each transaction of a split collection carries its own defaults.** `dsndr` and the Viber
  defaults were taken from the first message of the whole collection and written into every
  transaction it was split into, so the second transaction named a Viber sender for messages that
  never asked for one and compared its messages against a name none of them carries — which put
  `sndr` back on every message the root already speaks for.
* **A send that failed entirely is thrown as its first batch.** The failures were filed in two
  passes — everything that could not be composed, then everything that did not come back — so a
  send whose first two batches timed out and whose third carried a text that would not encode was
  thrown as the encoding error, and the caller's log named a malformed text for a send that died on
  the network. They are filed in the order the batches were built, which is also the order
  `ResponseCollection::getFailures()` hands them out in.
* **`ResultOne` reports the number as its digits.** The `number` key of `getSent()`, `getFailed()`
  and `getDenied()` was the string the caller wrote — `'0901 000 000'` — while a one-to-many and a
  many-to-many send reported `421901000000` for the very same number, so one key carried two types
  and a strict comparison against a caller's own records matched for one kind of send and silently
  missed for the rest. It is an `int` in all three results now. The number as it was written stays
  readable through `Recipient::getNumberOrig()`.
* **A logged body is masked from the array it was composed as.** The JSON a batch is posted as was
  decoded a second time only to be masked for the debug log, so a send of ten thousand numbers paid
  ten full decodes and ten recursive copies on the hot path for records nothing may even read. The
  records themselves are unchanged.
* **`RequestMessageAbstract::sign()`.** A transaction needs nothing of its messages but their
  signatures, chapter 9.3.8, and used to get them by building each message's whole request and
  throwing it away: for a thousand entries, a thousand bodies composed, filtered and dropped, with
  the recipients then written out a second time for the entry that is kept. The signature is worked
  out on its own now, and a message addressed to one number signs the same string a list of one
  does, so both are signed by the same code.
* **`Flag::getFlags()` leaves the set as it was, and `Flag::all()` is static.** Reading the flags
  wrote the default back into the set, so a message nobody had set a flag on stopped reading as one
  the moment anything asked it what it goes out under, and a `Flag` cloned into a request differed
  depending on whether it had been read first. `all()` reads as the flags of the object it is
  called on and returns `FlagEnum::cases()`, so it is static now — `Flag::all()`. Calling it on an
  instance still works.
