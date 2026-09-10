<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount held as an integer number of minor units (paise).
 *
 * Every price, fee, tax and total in this application flows through this class.
 * Floats are accepted only at the boundary (parsing user input) and are never
 * used for arithmetic — see percentage() and allocate().
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function fromMinor(int $minor, ?string $currency = null): self
    {
        return new self($minor, $currency ?? self::defaultCurrency());
    }

    public static function zero(?string $currency = null): self
    {
        return new self(0, $currency ?? self::defaultCurrency());
    }

    /**
     * Parse a major-unit value ("149.50", 149.5, "1,499") into minor units.
     * Rounds half-up at the minor unit — the only rounding boundary in the app.
     */
    public static function fromMajor(int|float|string $amount, ?string $currency = null): self
    {
        if (is_string($amount)) {
            $amount = str_replace([',', ' '], '', trim($amount));

            if ($amount === '' || ! is_numeric($amount)) {
                throw new InvalidArgumentException("Cannot parse [{$amount}] as a monetary amount.");
            }
        }

        $factor = self::minorUnitFactor();

        return new self(
            (int) round(((float) $amount) * $factor, 0, PHP_ROUND_HALF_UP),
            $currency ?? self::defaultCurrency(),
        );
    }

    public function add(self ...$others): self
    {
        $sum = $this->minor;

        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $sum += $other->minor;
        }

        return new self($sum, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /**
     * A percentage of this amount, e.g. 5% GST on a line total.
     * Rounds half-up so tax never silently under-collects.
     */
    public function percentage(float $percent): self
    {
        return new self(
            (int) round($this->minor * $percent / 100, 0, PHP_ROUND_HALF_UP),
            $this->currency,
        );
    }

    /** Never let a computed amount fall below zero (e.g. discount > subtotal). */
    public function clampAtZero(): self
    {
        return $this->minor < 0 ? self::zero($this->currency) : $this;
    }

    /** The smaller of two amounts — used to cap discounts at max_discount. */
    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->minor <= $other->minor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /** Major-unit string for API output: "149.50". Never a float. */
    public function toMajorString(): string
    {
        $factor = self::minorUnitFactor();
        $decimals = (int) round(log10($factor));
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return $sign.intdiv($abs, $factor).'.'.str_pad((string) ($abs % $factor), $decimals, '0', STR_PAD_LEFT);
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toMajorString(),
            'minor' => $this->minor,
            'currency' => $this->currency,
        ];
    }

    public function __toString(): string
    {
        return $this->toMajorString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }

    private static function defaultCurrency(): string
    {
        return (string) config('delivery.currency', 'INR');
    }

    private static function minorUnitFactor(): int
    {
        return (int) config('delivery.currency_minor_units', 100);
    }
}
