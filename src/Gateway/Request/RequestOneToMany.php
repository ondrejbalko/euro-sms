<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use DateTime;
use EuroSms\Entities\Message\MessageInterface;
use EuroSms\Entities\Recipient\RecipientCollection;
use EuroSms\Entities\Recipient\RecipientInterface;
use EuroSms\Enums\FieldEnum;
use EuroSms\Enums\FlagEnum;
use EuroSms\Enums\ResponseFormatEnum;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Gateway\GatewayInterface;

/**
 * One message addressed to a whole list of numbers, chapter 9.3.3 of SMS API v3.1.15. It carries
 * the same message a single request does and names its recipients as the list of chapter 9.3.3
 * rather than as the one number of chapter 9.3.1, which is why it is a request of its own and not
 * a variation on the single one.
 */
class RequestOneToMany extends RequestMessageAbstract implements RequestInterface
{
    /** @var DateTime $end */
    private DateTime $end;

    /** @var RecipientCollection $recipientCollection */
    private RecipientCollection $recipientCollection;

    /**
     * The body of a group request, chapters 9.2.1 and 9.3.3 of SMS API v3.1.15. A field that
     * carries nothing is left out rather than sent empty, so every one of them but the response
     * mode is only there when the request was given it:
     *
     * - iid: the integration id the request is sent under
     * - rsp: how much of an answer is asked for, always the full one here
     * - sgn: the signature over the sender, every recipient and the text, chapter 9.2.2
     * - rcpts: the numbers the message is addressed to, at most a thousand of them; a recipient
     *   carrying a key of its own is named as the object of chapter 9.2 instead of as a number,
     *   and the two shapes may stand side by side in the one list
     * - flgs: the composed flags value, gone when it is the plain default of zero
     * - ttl: how long the message may wait for its recipient, in seconds
     * - sndr: the sender name the message goes out under
     * - start: when the sending starts
     * - dur: how long the sending is spread over, in hours and minutes
     * - end: when the sending is to be over
     * - txt: the text of the message
     * - im: the Viber variant, only when the message carries one, chapter 10.2.1
     *
     * @return array{iid?: string, rsp: string, sgn?: string, rcpts?: list<int|array{r: int, f: string}>, flgs?: int, ttl?: int, sndr?: string, start?: string, dur?: string, end?: string, txt?: string, im?: array{sndr?: string, ttl?: int, msg?: string}}
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    #[\Override]
    public function getData(): array
    {
        if (!isset($this->recipientCollection)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        if (!isset($this->senderName)) {
            throw new MessageException('Sender name not defined', MessageInterface::ERROR_SENDER_NOT_DEFINED);
        }

        /**
         * Chapter 9.2.2 composes the signature out of the numbers alone, so a key one of the
         * recipients carries leaves it exactly as it was.
         */
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
            FieldEnum::RESPONSE->value => $this->getResponseFormat()->value,
            FieldEnum::SIGN->value => $this->sign,
            FieldEnum::RECIPIENTS->value => $this->getRecipientValues(),
            FieldEnum::FLAGS->value => FlagEnum::DEFAULT->value === $flags ? null : $flags,
            FieldEnum::TTL->value => $this->ttl ?? null,
            FieldEnum::SENDER_NAME->value => $this->senderName,
            FieldEnum::START->value => isset($this->scheduleDateTime) ? $this->scheduleDateTime->format(GatewayInterface::DATE_TIME_FORMAT) : null,
            FieldEnum::DURATION->value => $this->getDuration(),
            FieldEnum::END->value => isset($this->end) ? $this->end->format(GatewayInterface::DATE_TIME_FORMAT) : null,
            FieldEnum::TEXT->value => $this->getContent(),
            FieldEnum::INSTANT_MESSAGE->value => $this->getInstantMessageData()
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * When the sending is to be over, none when the caller named no end.
     * @return DateTime|null
     */
    public function getEnd(): ?DateTime
    {
        return $this->end ?? null;
    }

    /**
     * The recipients themselves, so that the key each of them may carry outlives the collection
     * it was handed over in. Empty until they are set.
     * @return RecipientCollection
     */
    public function getRecipientCollection(): RecipientCollection
    {
        return $this->recipientCollection ?? new RecipientCollection;
    }

    /**
     * A message addressed to more than one number names them all at once, chapter 9.3.3.
     * @return array{rcpts: list<int|array{r: int, f: string}>}
     * @throws RecipientException when the request was never given its recipients
     */
    #[\Override]
    public function getRecipientData(): array
    {
        if (!isset($this->recipientCollection)) {
            throw new RecipientException('Recipient not defined.', RecipientInterface::ERROR_RECIPIENT_NOT_DEFINED);
        }

        return [FieldEnum::RECIPIENTS->value => $this->getRecipientValues()];
    }

    /**
     * The recipients as the list of chapter 9.3.3 names them, each of them written the way it
     * asks to be: a bare number, or the object pairing that number with its key.
     * @return list<int|array{r: int, f: string}>
     */
    private function getRecipientValues(): array
    {
        $values = [];

        foreach ($this->getRecipientCollection()->all() as $recipient) {
            $values[] = self::getRecipientValue($recipient);
        }

        return $values;
    }

    /**
     * The numbers the request is addressed to, none of them until they are set. The keys are left
     * out of this on purpose: the signature is composed of numbers, chapter 9.2.2, and so are the
     * verdicts the answer is read into. Whatever needs the keys asks for the recipients instead.
     * @return list<int>
     */
    #[\Override]
    public function getRecipients(): array
    {
        $numbers = [];

        foreach ($this->getRecipientCollection()->all() as $recipient) {
            $numbers[] = $recipient->getNumberClean();
        }

        return $numbers;
    }

    /**
     * The lists of chapter 9.3.4.1 the result is read number by number from only come with the
     * full answer, so a group send asks for it whatever the configuration says.
     * @return ResponseFormatEnum
     */
    #[\Override]
    public function getResponseFormat(): ResponseFormatEnum
    {
        return ResponseFormatEnum::FULL;
    }

    /**
     * @param DateTime $end
     * @return void
     */
    public function setEnd(DateTime $end): void
    {
        $this->end = $end;
    }

    /**
     * The recipients are kept as they are rather than reduced to their numbers, because the key
     * a recipient carries has to reach the gateway with it. The collection handed over by the
     * caller is cloned, so entries added to it afterwards do not reach the request already built
     * from it.
     * @param RecipientCollection $recipientCollection
     * @return void
     */
    public function setRecipientCollection(RecipientCollection $recipientCollection): void
    {
        $this->recipientCollection = clone $recipientCollection;
    }
}
