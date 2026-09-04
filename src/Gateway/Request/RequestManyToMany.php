<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use EuroSms\Entities\Message\Flag;
use EuroSms\Entities\Message\InstantMessage;
use EuroSms\Enums\FieldEnum;
use EuroSms\Enums\FlagEnum;
use EuroSms\Enums\ResponseFormatEnum;
use EuroSms\Exception\MessageException;
use EuroSms\Exception\RecipientException;
use EuroSms\Exception\RequestException;
use EuroSms\Helpers\SenderName;

/**
 * One transaction carrying several different messages. The shape is the one printed in chapter
 * 9.3.8 of SMS API v3.1.15: the root object holds the integration id, the defaults every message
 * falls back to and the response mode, while the messages themselves live in "msgs". A message of
 * a group is neither scheduled nor given a time to live of its own: chapter 9.3.1 lists neither
 * "sch" nor "ttl" among its fields, and the root of the transaction has no default for either.
 * A message given any of the three is refused while the transaction is being built, in
 * Percolator::checkGroupMessage(), rather than sent with what it asked for quietly missing.
 * The one thing the whole transaction can be spread over time by is "dur", chapter 9.2.1.
 */
class RequestManyToMany extends RequestAbstract implements RequestInterface
{
    /** @var Flag $defaultFlag */
    private Flag $defaultFlag;

    /** @var InstantMessage $defaultInstantMessage */
    private InstantMessage $defaultInstantMessage;

    /** @var string $defaultSenderName */
    private string $defaultSenderName;

    /** @var RequestMessageAbstract[] $requests */
    private array $requests = [];

    /**
     * A message of the group is built the same way a standalone one is, so both a message
     * addressed to a single recipient and one addressed to a whole list of them can be added.
     * @param RequestMessageAbstract $request
     * @return void
     */
    public function addRequest(RequestMessageAbstract $request): void
    {
        $this->requests[] = $request;
    }

    /**
     * The body of a transaction, chapters 9.2.1 and 9.3.8 of SMS API v3.1.15. The root states
     * what the whole group shares, the messages themselves live in "msgs", and a field that
     * was never given anything is left out rather than sent empty:
     *
     * - iid: the integration id the transaction is sent under
     * - dflgs: the flags every message falls back to, the plain default of zero when none
     *   were named
     * - dimsndr: the Viber sender every message falls back to, chapter 10
     * - dimttl: the Viber time to live every message falls back to, in seconds
     * - dsndr: the sender name every message falls back to
     * - dur: how long the sending is spread over, in hours and minutes
     * - rsp: how much of an answer is asked for, always the full one here
     * - msgs: the messages of the transaction, each of them carrying
     *   - sgn: the signature over its sender, its recipients and its text, chapter 9.2.2
     *   - rcpt: the one number it is addressed to, or
     *   - rcpts: the numbers it is addressed to, a recipient carrying a key of its own named as
     *     the object of chapter 9.2 rather than as a number
     *   - flgs: its flags, gone when they are the ones the root already states
     *   - sndr: its sender name, gone when it is the one the root already states
     *   - txt: its text
     *   - im: its Viber variant, only when it carries one of its own
     *
     * @return array{iid: string, dflgs: int, dimsndr?: string, dimttl?: int, dsndr?: string, dur?: string, rsp: string, msgs: list<array{sgn: string, rcpt?: int|array{r: int, f: string}, rcpts?: list<int|array{r: int, f: string}>, flgs?: int, sndr?: string, txt: string, im?: array{sndr?: string, ttl?: int, msg?: string}}>}
     * @throws MessageException
     * @throws RecipientException
     * @throws RequestException
     */
    #[\Override]
    public function getData(): array
    {
        if ([] === $this->requests) {
            throw new RequestException('Message not defined.', RequestInterface::ERROR_MESSAGE_NOT_DEFINED);
        }

        $defaultFlags = $this->getDefaultFlagValue();
        $defaultSenderName = $this->getDefaultSenderName();

        $messages = [];
        foreach ($this->requests as $request) {
            /**
             * A message that named no sender of its own is signed with the one of the whole group,
             * chapter 9.3.8, because that is the name the gateway will send it under.
             */
            if (null !== $defaultSenderName && !$request->hasSenderName()) {
                $request->setSenderName($defaultSenderName);
            }

            /**
             * The message signs itself and the entry of the transaction is then written out of
             * the message itself. Only the signature is wanted here, so only the signature is
             * worked out: building the message's whole request for it composed and dropped a body
             * per entry, and wrote the recipients out twice over.
             */
            $sign = $request->sign();
            $flags = $request->getFlag()->getValue();
            $senderName = $request->getSenderName();

            /**
             * What a message shares with the whole group is written out once, in the root: a
             * message repeating the default flags or the default sender name would say nothing
             * the defaults do not already say, and the gateway reads them back the same way.
             */
            $messages[] = array_filter([
                FieldEnum::SIGN->value => $sign,
                ...$request->getRecipientData(),
                FieldEnum::FLAGS->value => $defaultFlags === $flags ? null : $flags,
                FieldEnum::SENDER_NAME->value => $defaultSenderName === $senderName ? null : $senderName,
                FieldEnum::TEXT->value => $request->getContent(),
                FieldEnum::INSTANT_MESSAGE->value => $request->getInstantMessageData()
            ], static fn (mixed $value): bool => null !== $value);
        }

        return array_filter([
            FieldEnum::CLIENT_ID->value => $this->clientId,
            FieldEnum::DEFAULT_FLAGS->value => $defaultFlags,
            FieldEnum::DEFAULT_INSTANT_MESSAGE_SENDER_NAME->value => isset($this->defaultInstantMessage) ? $this->defaultInstantMessage->getSenderName() : null,
            FieldEnum::DEFAULT_INSTANT_MESSAGE_TTL->value => isset($this->defaultInstantMessage) ? $this->defaultInstantMessage->getTtl() : null,
            FieldEnum::DEFAULT_SENDER_NAME->value => $defaultSenderName,
            FieldEnum::DURATION->value => $this->getDuration(),
            FieldEnum::RESPONSE->value => $this->getResponseFormat()->value,
            FieldEnum::MESSAGES->value => $messages
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * The flags every message of the group falls back to, the plain default ones when the caller
     * named none.
     * @return int
     */
    public function getDefaultFlagValue(): int
    {
        return isset($this->defaultFlag) ? $this->defaultFlag->getValue() : FlagEnum::DEFAULT->value;
    }

    /**
     * The sender name every message of the group falls back to, none when the caller named none.
     * @return string|null
     */
    public function getDefaultSenderName(): ?string
    {
        return $this->defaultSenderName ?? null;
    }

    /**
     * @return int
     */
    public function getMessageCount(): int
    {
        return count($this->requests);
    }

    /**
     * Every number the whole group is addressed to, in the order the messages were added.
     * @return list<int>
     */
    #[\Override]
    public function getRecipients(): array
    {
        $recipients = [];

        foreach ($this->requests as $request) {
            foreach ($request->getRecipients() as $number) {
                $recipients[] = $number;
            }
        }

        return $recipients;
    }

    /**
     * @return RequestMessageAbstract[]
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    /**
     * The verdict per number of chapter 9.3.11 the result is read from only comes with the full
     * answer, so a group transaction asks for it whatever the configuration says.
     * @return ResponseFormatEnum
     */
    #[\Override]
    public function getResponseFormat(): ResponseFormatEnum
    {
        return ResponseFormatEnum::FULL;
    }

    /**
     * What is worth telling the caller about the transaction, gathered from the messages it
     * carries: the transaction itself is a wrapper and has nothing of its own to say, while the
     * messages inside it are the ones whose texts fall into segments. The same thing said about
     * two messages is said once — what the caller is being told is that the transaction carries
     * a text of that shape, not how many of them there are.
     * @return list<string>
     */
    #[\Override]
    public function getWarnings(): array
    {
        $warnings = $this->warnings;

        foreach ($this->requests as $request) {
            foreach ($request->getWarnings() as $warning) {
                if (!in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
        }

        return $warnings;
    }

    /**
     * Whether there is anything to tell the caller about the transaction. What the transaction
     * has to say is what the messages it carries have to say, so the question is answered off the
     * same list getWarnings() builds: reading the wrapper's own list alone would answer no for a
     * transaction whose every warning belongs to a message inside it.
     * @return bool
     */
    #[\Override]
    public function hasWarnings(): bool
    {
        return [] !== $this->getWarnings();
    }

    /**
     * @param Flag $defaultFlag
     * @return void
     */
    public function setDefaultFlag(Flag $defaultFlag): void
    {
        $this->defaultFlag = clone $defaultFlag;
    }

    /**
     * The Viber sender and the time to live every message of the group falls back to, chapter 10.
     * The root carries no default text, so only the sender and the time to live of the variant
     * handed over here are written out; its own text, if it has one, belongs to a message.
     * @param InstantMessage $defaultInstantMessage
     * @return void
     */
    public function setDefaultInstantMessage(InstantMessage $defaultInstantMessage): void
    {
        $this->defaultInstantMessage = clone $defaultInstantMessage;
    }

    /**
     * The name the whole transaction falls back to, "dsndr" of chapter 9.3.8. It is the same field
     * one message names under "sndr" and the chapter gives them one set of rules, so it is checked
     * by the same code and refused rather than cut. Cutting it here while the message that falls
     * back to it is refused would be the same mistake wearing the other name.
     * @param string $defaultSenderName
     * @return void
     * @throws MessageException
     */
    public function setDefaultSenderName(string $defaultSenderName): void
    {
        $this->defaultSenderName = SenderName::validate($defaultSenderName);
    }
}
