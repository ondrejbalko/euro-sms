<?php

declare(strict_types=1);

namespace EuroSms\Entities\Message;

use EuroSms\Enums\FlagEnum;

class Flag
{
    /**
     * @var FlagEnum[]
     */
    private array $flags = [];

    /**
     * @return void
     */
    public function addDefault(): void
    {
        $this->flags[] = FlagEnum::DEFAULT;
    }

    /**
     * @return void
     */
    public function addLong(): void
    {
        $this->flags[] = FlagEnum::LONG;
    }

    /**
     * @return void
     */
    public function addPriorityHigh(): void
    {
        $this->flags[] = FlagEnum::HIGH_PRIORITY;
    }

    /**
     * @return void
     */
    public function addPriorityLow(): void
    {
        $this->flags[] = FlagEnum::LOW_PRIORITY;
    }

    /**
     * @return void
     */
    public function addReceipt(): void
    {
        $this->flags[] = FlagEnum::RECEIPT;
    }

    /**
     * A long message with diacritics carries both the long flag and the diacritics flag,
     * there is no separate flag for the two of them together.
     * @return void
     */
    public function addUnicodeLong(): void
    {
        $this->addLong();
        $this->addUnicodeShort();
    }

    /**
     * @return void
     */
    public function addUnicodeShort(): void
    {
        $this->flags[] = FlagEnum::UNICODE_SHORT;
    }

    /**
     * @return void
     */
    public function addViber(): void
    {
        $this->flags[] = FlagEnum::VIBER;
    }

    /**
     * @return void
     */
    public function addViberOnly(): void
    {
        $this->flags[] = FlagEnum::VIBER;
        $this->flags[] = FlagEnum::VIBER_ONLY;
    }

    /**
     * The promo flag is only valid together with Viber itself.
     * @return void
     */
    public function addViberPromo(): void
    {
        $this->flags[] = FlagEnum::VIBER;
        $this->flags[] = FlagEnum::VIBER_PROMO;
    }

    /**
     * Every flag the gateway knows, chapter 14.1 — not the flags of this one message, which is
     * what getFlags() answers. It asks nothing of the object and is static for that reason: read
     * as an instance method it named the object it was called on and handed back something else
     * entirely, so `in_array($case, $flag->all(), true)` was a question about this message that
     * came back true for every case there is.
     * @return FlagEnum[]
     */
    #[\NoDiscard('the list of known flags is all this call produces')]
    public static function all(): array
    {
        return FlagEnum::cases();
    }

    /**
     * Flags are single bits of one value, so they are combined bitwise. Adding them up would
     * turn on bits the caller never asked for.
     * @return int
     */
    #[\NoDiscard('the composed flgs value is all this call produces')]
    public function getValue(): int
    {
        $value = FlagEnum::DEFAULT->value;

        foreach ($this->getFlags() as $flag) {
            $value |= $flag->value;
        }

        return $value;
    }

    /**
     * The flags this message goes out under, each of them once, and the plain default when none
     * were set at all.
     *
     * Reading them leaves the message alone. Answering the question used to write the answer back:
     * a flag set that had been asked what it holds no longer held nothing, so "was anything set?"
     * could not be asked afterwards, and a Flag cloned into a request differed depending on
     * whether anybody had read it first.
     * @return FlagEnum[]
     */
    #[\NoDiscard('the flags of this message are all this call produces')]
    public function getFlags(): array
    {
        if ([] === $this->flags) {
            return [FlagEnum::DEFAULT];
        }

        return array_unique($this->flags, SORT_REGULAR) |> array_values(...);
    }

    /**
     * The flags a composed value stands for, chapter 14.1. The value is not looked up whole: the
     * chapter lists single bits and every other number it prints is those bits combined, so six
     * is the long flag together with the diacritics flag and not a flag of its own. A bit the
     * gateway knows no flag for is dropped, the same way anything else that is not a flag is.
     * @param int $value
     * @return FlagEnum[]
     */
    private static function getCases(int $value): array
    {
        if (0 >= $value) {
            return [];
        }

        $cases = [];

        foreach (FlagEnum::cases() as $case) {
            if (FlagEnum::DEFAULT !== $case && $case->value === ($value & $case->value)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * Only what the gateway knows as a flag is kept, anything else is dropped rather than left
     * to blow up when the value is composed. A plain integer is accepted as well, so that the
     * numbers of chapter 14.1 can be handed over as they are read there — one that is several
     * flags at once is taken apart into the flags it is composed of. Nothing enforces that
     * promise, so whatever else arrives is dropped as well.
     * @param mixed[] $flags
     * @return void
     */
    public function setFlags(array $flags): void
    {
        $known = [];

        foreach ($flags as $flag) {
            if ($flag instanceof FlagEnum) {
                $known[] = $flag;

                continue;
            }

            if (is_int($flag)) {
                foreach (self::getCases($flag) as $case) {
                    $known[] = $case;
                }
            }
        }

        $this->flags = $known;
    }
}
