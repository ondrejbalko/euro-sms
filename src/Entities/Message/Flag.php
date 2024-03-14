<?php

namespace EuroSms\Entities\Message;

class Flag
{
    /**
     * @var int[]
     */
    private array $flags = [];

    /**
     * @return void
     */
    public function addDefault(): void
    {
        $this->flags[] = MessageInterface::FLAG_DEFAULT;
    }

    /**
     * @return void
     */
    public function addLong(): void
    {
        $this->flags[] = MessageInterface::FLAG_LONG;
    }

    /**
     * @return void
     */
    public function addPriorityHigh(): void
    {
        $this->flags[] = MessageInterface::FLAG_HIGH_PRIORITY;
    }

    /**
     * @return void
     */
    public function addPriorityLow(): void
    {
        $this->flags[] = MessageInterface::FLAG_LOW_PRIORITY;
    }

    /**
     * @return void
     */
    public function addReceipt(): void
    {
        $this->flags[] = MessageInterface::FLAG_RECEIPT;
    }

    /**
     * @return void
     */
    public function addUnicodeLong(): void
    {
        $this->flags[] = MessageInterface::FLAG_UNICODE_LONG;
    }

    /**
     * @return void
     */
    public function addUnicodeShort(): void
    {
        $this->flags[] = MessageInterface::FLAG_UNICODE_SHORT;
    }

    /**
     * @return void
     */
    public function addViber(): void
    {
        $this->flags[] = MessageInterface::FLAG_VIBER;
    }

    /**
     * @return void
     */
    public function addViberOnly(): void
    {
        $this->flags[] = MessageInterface::FLAG_VIBER;
        $this->flags[] = MessageInterface::FLAG_VIBER_ONLY;
    }

    /**
     * @return void
     */
    public function addViberPromo(): void
    {
        $this->flags[] = MessageInterface::FLAG_VIBER_PROMO;
    }

    /**
     * @return int[]
     */
    public function all(): array
    {
        return [
            MessageInterface::FLAG_DEFAULT,
            MessageInterface::FLAG_RECEIPT,
            MessageInterface::FLAG_LONG,
            MessageInterface::FLAG_UNICODE_SHORT,
            MessageInterface::FLAG_UNICODE_LONG,
            MessageInterface::FLAG_HIGH_PRIORITY,
            MessageInterface::FLAG_LOW_PRIORITY,
            MessageInterface::FLAG_VIBER_ONLY,
            MessageInterface::FLAG_VIBER_PROMO,
            MessageInterface::FLAG_VIBER
        ];
    }

    /**
     * @return int
     */
    public function getValue(): int
    {
        return array_sum($this->getFlags());
    }

    /**
     * @return int[]
     */
    public function getFlags(): array
    {
        if ([] === $this->flags) {
            $this->addDefault();
        }

        $this->flags = array_unique($this->flags);

        return $this->flags;
    }

    /**
     * @param int[] $flags
     * @return void
     */
    public function setFlags(array $flags): void
    {
        $this->flags = array_intersect($flags, $this->all());
    }
}
