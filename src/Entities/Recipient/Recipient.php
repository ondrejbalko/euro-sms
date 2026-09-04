<?php

declare(strict_types=1);

namespace EuroSms\Entities\Recipient;

use EuroSms\Exception\RecipientException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class Recipient
{
    /** @var string|null $identifier */
    private ?string $identifier;

    /** @var string $number */
    private string $number;

    /** @var string $numberOrig */
    private string $numberOrig;

    /**
     * Recipients' phone number will be converted to international format E.164
     * @param string $number
     * @param string $country
     * @param string|null $identifier the key the calling system knows this recipient by, chapter 9.2
     * @throws RecipientException
     */
    public function __construct(string $number, private readonly string $country = RecipientInterface::RECIPIENT_DEFAULT_COUNTRY, ?string $identifier = null)
    {
        $this->numberOrig = $number;
        $this->setNumber($number);
        $this->setIdentifier($identifier);
    }

    /**
     * E.164 is the plus and the digits, and nothing between them. The international format is
     * not that format: it is the one a country writes its numbers in for people to read, and a
     * good half of the world separates the groups with hyphens rather than with spaces — the
     * United States, Canada and Russia among them. Taking the spaces back out of it left those
     * hyphens standing, and getNumberClean() casts to an int, so such a number stopped at the
     * first hyphen and was both addressed and signed as something else entirely.
     * @param string $number
     * @return void
     * @throws RecipientException
     */
    private function setNumber(string $number): void
    {
        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($number, $this->country);
            $formatted = PhoneNumberUtil::getInstance()->format($parsed, PhoneNumberFormat::E164);
        } catch (NumberParseException $e) {
            throw new RecipientException('Wrong recipient number.', RecipientInterface::ERROR_WRONG_NUMBER, $e);
        }

        $this->number = $formatted;
    }

    /**
     * The key the calling system knows this recipient by, none when it named none. It is what a
     * request sends under "f" and what the gateway repeats on the delivery report, chapters 9.2
     * and 9.4.2, so a receipt can be matched back to the record it belongs to rather than to a
     * phone number that may have been written to more than once.
     * @return string|null
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getNumber(): string
    {
        return $this->number;
    }

    /**
     * @return string
     */
    public function getNumberOrig(): string
    {
        return $this->numberOrig;
    }

    /**
     * @return int
     */
    public function getNumberClean(): int
    {
        $number = $this->getNumber();
        return (int)trim($number, '+');
    }

    /**
     * Whether the recipient carries a key of its own, and so travels as an object rather than as
     * a bare number.
     * @return bool
     */
    public function hasIdentifier(): bool
    {
        return null !== $this->identifier;
    }

    /**
     * A key that says nothing is no key at all: an empty one is kept as none, so that a record
     * with an unfilled identifier is still written out the way it always was.
     * @param string|null $identifier
     * @return void
     */
    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = '' === $identifier ? null : $identifier;
    }
}
