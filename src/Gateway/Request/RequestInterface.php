<?php

declare(strict_types=1);

namespace EuroSms\Gateway\Request;

use JsonSerializable;

/**
 * What every request the gateway is asked with has in common, whichever of the three endpoints of
 * chapter 9.3 of SMS API v3.1.15 it is posted to. How a request names who it is addressed to is
 * deliberately not part of this: one message to one number, one message to a list of them and a
 * whole transaction of messages each name their recipients differently, and the request that
 * carries exactly one of them says so through RequestRecipientInterface instead.
 */
interface RequestInterface extends JsonSerializable
{
    /**
     * The request was never given the message it was to carry.
     * @var int
     */
    const int ERROR_MESSAGE_NOT_DEFINED = 0;

    /**
     * The length the sending was to be spread over is not the hh:mm of chapter 9.2.1.
     * @var int
     */
    const int ERROR_DURATION_INVALID = 1;

    /**
     * A message was given something the transaction it is to travel in cannot carry.
     * @var int
     */
    const int ERROR_MESSAGE_NOT_SCHEDULABLE = 2;

    /**
     * Two messages of one transaction asked to be spread over two different lengths of time,
     * and there is one "dur" for the whole of it.
     * @var int
     */
    const int ERROR_DURATION_AMBIGUOUS = 3;

    /**
     * The collection asked to be spread over a length of time and is too big for one transaction,
     * so the length cannot be honoured: the transactions it is split into carry "dur" each and go
     * out at the same time, which spreads as many messages over that length as there are
     * transactions.
     * @var int
     */
    const int ERROR_DURATION_NOT_SPLITTABLE = 4;

    /**
     * @return array<string, mixed>
     */
    public function __toArray(): array;

    /**
     * The body of the request as it goes on the wire.
     * @return array<string, mixed>
     */
    public function getData(): array;

    /**
     * @return string
     */
    public function getId(): string;

    /**
     * The message this request was built for, none when it was built for no single one. Only a
     * request carrying one message has an identifier to hand out; a transaction, chapter 9.3.8,
     * carries a whole collection and answers with nothing.
     * @return string|null
     */
    public function getMessageId(): ?string;

    /**
     * The numbers the request is addressed to, in the order they are listed. What a recipient may
     * be named by beyond its number — a key of its own, chapter 9.2 — is not part of this: the
     * signature of chapter 9.2.2 is composed of numbers and so are the verdicts the answer is
     * read into. A number the gateway hands back in a shape of its own is passed on as it stands,
     * so nothing about it is lost on the way.
     * @return list<int|string>
     */
    public function getRecipients(): array;

    /**
     * What is worth telling the caller about this request without refusing to send it. Chapter
     * 14.1 puts no limit on the number of segments a message may fall into — it is the operators
     * that recommend against long ones, chapter 9.2.2 — so a text over the recommended four is
     * something to say and not something to stop. The gateway answering TOO_MANY_MESSAGES after
     * the fact is what this replaces, and unlike that answer it is known before anything is sent.
     * @return list<string>
     */
    public function getWarnings(): array;

    /**
     * Whether there is anything to tell the caller about this request.
     * @return bool
     */
    public function hasWarnings(): bool;

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;

    /**
     * @param string $messageId
     * @return void
     */
    public function setMessageId(string $messageId): void;

    /**
     * @param string $senderName
     * @return void
     */
    public function setSenderName(string $senderName): void;
}
