<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\Recipient;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Enums\FieldEnum;
use EuroSms\Enums\FlagEnum;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;

/**
 * One message addressed to one number, chapter 9.3.1 of SMS API v3.1.15. It is the only request
 * that has a single recipient to speak of, and so the only one implementing the interface that
 * hands one out.
 */
class RequestOne extends RequestMessageAbstract implements RequestRecipientInterface
{
    /** @var Recipient $recipient */
    protected Recipient $recipient;

    /**
     * The body of a single message, chapters 9.2.1 and 9.3.1 of SMS API v3.1.15. A field that
     * carries nothing is left out rather than sent empty, so every one of them is only there when
     * the request was given it:
     *
     * - iid: the integration id the request is sent under
     * - sgn: the signature over the sender, the recipient and the text, chapter 9.2.2
     * - rcpt: the number the message is addressed to, always the bare number
     * - flgs: the composed flags value, gone when it is the plain default of zero
     * - ttl: how long the message may wait for its recipient, in seconds
     * - sndr: the sender name the message goes out under
     * - sch: when the message is to be sent
     * - txt: the text of the message
     * - im: the Viber variant, only when the message carries one, chapter 10.2.1
     *
     * The endpoint knows neither "rsp" nor a recipient key: it answers the first with WRONG_KEY and
     * "rcpt" given as the object of chapter 9.2 with WRONG_NUMBER, so the one is never written and
     * a recipient carrying the other is refused before anything is sent.
     *
     * @return array{iid?: string, sgn?: string, rcpt?: int, flgs?: int, ttl?: int, sndr?: string, sch?: string, txt?: string, im?: array{sndr?: string, ttl?: int, msg?: string}}
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    #[\Override]
    public function getData(): array
    {
        if (!isset($this->recipient)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        if (!isset($this->senderName)) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        if ($this->recipient->hasIdentifier()) {
            throw new RequestException(
                'A single message cannot carry a key of its recipient, send it one to many instead.',
                RequestInterface::ERROR_RECIPIENT_KEY_NOT_SUPPORTED
            );
        }

        $this->sign();

        $flags = $this->flag->getValue();

        /**
         * A field that carries nothing is left out, and carrying nothing means never having been
         * given anything — not merely reading as false. A message whose whole text is "0" is a
         * message with a text, and the signature above was composed over it, so dropping the text
         * would send the gateway a request its own signature does not match.
         */
        return array_filter([
            FieldEnum::CLIENT_ID->value => $this->clientId,
            FieldEnum::SIGN->value => $this->sign,
            FieldEnum::RECIPIENT->value => $this->recipient->getNumberClean(),
            FieldEnum::FLAGS->value => FlagEnum::DEFAULT->value === $flags ? null : $flags,
            FieldEnum::TTL->value => $this->ttl ?? null,
            FieldEnum::SENDER_NAME->value => $this->senderName,
            FieldEnum::SCHEDULE->value => isset($this->scheduleDateTime) ? $this->scheduleDateTime->format(GatewayInterface::DATE_TIME_FORMAT) : null,
            FieldEnum::TEXT->value => $this->getContent(),
            FieldEnum::INSTANT_MESSAGE->value => $this->getInstantMessageData()
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * A request that was never given a recipient says so the way every other path of it does,
     * rather than letting the uninitialized property blow up with an Error nobody catches.
     * @return Recipient
     * @throws RecipientException
     */
    #[\Override]
    public function getRecipient(): Recipient
    {
        if (!isset($this->recipient)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        return $this->recipient;
    }

    /**
     * The one number the message is addressed to, chapter 9.3.1, written under "rcpt". A message
     * of a transaction names the key of its recipient beside it, under "f"; the transaction writes
     * that out itself, because the endpoint of a single message refuses it.
     * @return array{rcpt: int}
     * @throws RecipientException
     */
    #[\Override]
    public function getRecipientData(): array
    {
        return [FieldEnum::RECIPIENT->value => $this->getRecipient()->getNumberClean()];
    }

    /**
     * @return list<Recipient>
     * @throws RecipientException when the request was never given its recipient
     */
    #[\Override]
    public function getRecipientList(): array
    {
        return [$this->getRecipient()];
    }

    /**
     * The one recipient, listed the same way a group request lists its own. Only the number is
     * listed: the signature is composed of numbers, chapter 9.2.2, and so are the verdicts the
     * answer is read into.
     * @return list<int>
     */
    #[\Override]
    public function getRecipients(): array
    {
        return isset($this->recipient) ? [$this->recipient->getNumberClean()] : [];
    }

    /**
     * @param Recipient $recipient
     * @return void
     */
    #[\Override]
    public function setRecipient(Recipient $recipient): void
    {
        $this->recipient = $recipient;
    }
}
