<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money is the arithmetic floor of the whole application: if these are wrong,
 * every order total is wrong. Extends the Laravel TestCase because Money reads
 * its currency and minor-unit factor from config.
 */
class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_major_units_into_minor_units(): void
    {
        $this->assertSame(14950, Money::fromMajor('149.50')->minor);
        $this->assertSame(14950, Money::fromMajor(149.50)->minor);
        $this->assertSame(15000, Money::fromMajor(150)->minor);
        $this->assertSame(149900, Money::fromMajor('1,499.00')->minor);
    }

    #[Test]
    public function it_rejects_unparseable_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('not a number');
    }

    #[Test]
    public function it_renders_major_units_without_float_error(): void
    {
        $this->assertSame('149.50', Money::fromMinor(14950)->toMajorString());
        $this->assertSame('0.05', Money::fromMinor(5)->toMajorString());
        $this->assertSame('0.00', Money::zero()->toMajorString());
        $this->assertSame('1000.00', Money::fromMinor(100000)->toMajorString());
        $this->assertSame('-25.00', Money::fromMinor(-2500)->toMajorString());
    }

    #[Test]
    public function it_adds_and_subtracts_exactly(): void
    {
        $subtotal = Money::fromMajor('149.50');
        $delivery = Money::fromMajor('29.00');
        $packaging = Money::fromMajor('15.00');

        $this->assertSame(19350, $subtotal->add($delivery, $packaging)->minor);
        $this->assertSame(12050, $subtotal->subtract($delivery)->minor);
    }

    #[Test]
    public function repeated_addition_does_not_drift(): void
    {
        // The classic float failure: 0.1 + 0.2 !== 0.3. With integers it cannot happen.
        $total = Money::zero();

        foreach (range(1, 10) as $ignored) {
            $total = $total->add(Money::fromMajor('0.10'));
        }

        $this->assertSame(100, $total->minor);
        $this->assertSame('1.00', $total->toMajorString());
    }

    #[Test]
    public function it_multiplies_by_quantity(): void
    {
        $this->assertSame(44850, Money::fromMajor('149.50')->multiply(3)->minor);
    }

    #[Test]
    public function it_computes_percentages_rounding_half_up(): void
    {
        // 5% GST on 149.50 = 7.475 -> rounds up to 7.48, so tax never under-collects.
        $this->assertSame(748, Money::fromMajor('149.50')->percentage(5)->minor);

        $this->assertSame(0, Money::zero()->percentage(18)->minor);
        $this->assertSame(1800, Money::fromMajor('100.00')->percentage(18)->minor);
    }

    #[Test]
    public function it_clamps_negative_amounts_at_zero(): void
    {
        // A discount larger than the subtotal must not produce a negative total.
        $result = Money::fromMajor('100.00')
            ->subtract(Money::fromMajor('150.00'))
            ->clampAtZero();

        $this->assertTrue($result->isZero());
    }

    #[Test]
    public function it_caps_an_amount_with_min(): void
    {
        $percentDiscount = Money::fromMajor('120.00');
        $maxDiscount = Money::fromMajor('75.00');

        $this->assertSame(7500, $percentDiscount->min($maxDiscount)->minor);
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(100, 'INR')->add(Money::fromMinor(100, 'USD'));
    }

    #[Test]
    public function it_serialises_for_api_output(): void
    {
        $this->assertSame(
            ['amount' => '149.50', 'minor' => 14950, 'currency' => 'INR'],
            Money::fromMajor('149.50')->jsonSerialize(),
        );
    }
}
