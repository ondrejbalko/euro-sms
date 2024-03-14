<?php

namespace EuroSms\Entities\Recipient;

use EuroSms\Exception\RecipientException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class Recipient
{
    /** @var string $number */
    private string $number;

    /** @var string $numberOrig */
    private string $numberOrig;

    /**
     * Recipients' phone number will be converted to international format E.164
     * @param string $number
     * @param string $country
     * @throws RecipientException
     */
    public function __construct(string $number, private readonly string $country = RecipientInterface::RECIPIENT_DEFAULT_COUNTRY)
    {
        $this->numberOrig = $number;
        $this->setNumber($number);
    }

    /**
     * @param string $number
     * @return void
     * @throws RecipientException
     */
    private function setNumber(string $number): void
    {
        try {
            $parsed = PhoneNumberUtil::getInstance()->parse($number, $this->country);
            $formatted = str_replace(' ', '', PhoneNumberUtil::getInstance()->format($parsed, PhoneNumberFormat::INTERNATIONAL));
        } catch (NumberParseException $e) {
            throw new RecipientException('Wrong recipient number.', RecipientInterface::ERROR_WRONG_NUMBER, $e);
        }

        $this->number = $formatted;
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
}
