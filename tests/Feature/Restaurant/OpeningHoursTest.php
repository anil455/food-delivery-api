<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant;
use App\Services\Restaurant\OpeningHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three cases that make opening hours harder than comparing two times:
 * split service, midnight crossings, and per-restaurant timezones.
 */
class OpeningHoursTest extends TestCase
{
    use RefreshDatabase;

    private OpeningHoursService $hours;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hours = app(OpeningHoursService::class);
    }

    private function restaurantOpenDaily(string $opens, string $closes, string $timezone = 'Asia/Kolkata'): Restaurant
    {
        $restaurant = Restaurant::factory()->create(['timezone' => $timezone]);

        foreach (range(0, 6) as $day) {
            $restaurant->hours()->create([
                'day_of_week' => $day,
                'opens_at' => $opens,
                'closes_at' => $closes,
                'is_closed' => false,
            ]);
        }

        return $restaurant->load(['hours', 'holidays']);
    }

    private function at(string $time, string $timezone = 'Asia/Kolkata'): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $timezone);
    }

    #[Test]
    public function it_is_open_inside_a_normal_shift(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');

        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:30')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 22:59')));
    }

    #[Test]
    public function it_is_closed_outside_a_normal_shift(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');

        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 09:00')));
        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 23:30')));
    }

    #[Test]
    public function closing_time_is_exclusive(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');

        // At exactly closing time the kitchen is shut, not open for one more second.
        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 23:00')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 11:00')));
    }

    #[Test]
    public function a_shift_that_crosses_midnight_stays_open_after_midnight(): void
    {
        // 18:00 to 00:30 the following day.
        $restaurant = $this->restaurantOpenDaily('18:00:00', '00:30:00');

        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 19:00')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 23:59')));

        // 00:15 belongs to the slot that opened yesterday evening.
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-09 00:15')));

        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-09 00:45')));
        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-09 12:00')));
    }

    #[Test]
    public function split_lunch_and_dinner_service_closes_in_between(): void
    {
        $restaurant = Restaurant::factory()->create();

        foreach (range(0, 6) as $day) {
            $restaurant->hours()->create(['day_of_week' => $day, 'opens_at' => '11:00:00', 'closes_at' => '15:00:00']);
            $restaurant->hours()->create(['day_of_week' => $day, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00']);
        }

        $restaurant->load(['hours', 'holidays']);

        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:00')));
        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 16:30')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-08 20:00')));
    }

    #[Test]
    public function a_day_marked_closed_is_closed(): void
    {
        $restaurant = Restaurant::factory()->create();

        // 2026-09-08 is a Tuesday, dayOfWeek 2.
        $restaurant->hours()->create(['day_of_week' => 2, 'is_closed' => true, 'opens_at' => null, 'closes_at' => null]);
        $restaurant->hours()->create(['day_of_week' => 3, 'opens_at' => '11:00:00', 'closes_at' => '23:00:00']);

        $restaurant->load(['hours', 'holidays']);

        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:00')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-09 12:00')));
    }

    #[Test]
    public function a_holiday_overrides_the_weekly_schedule(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');
        $restaurant->holidays()->create(['date' => '2026-09-08', 'reason' => 'Maintenance']);
        $restaurant->load(['hours', 'holidays']);

        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:00')));
        $this->assertTrue($this->hours->isOpen($restaurant, $this->at('2026-09-09 12:00')));
    }

    #[Test]
    public function the_manual_switch_beats_the_schedule(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');
        $restaurant->forceFill(['is_accepting_orders' => false])->save();

        // A kitchen that has stopped taking orders is closed whatever the clock says.
        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:00')));
    }

    #[Test]
    public function openness_is_evaluated_in_the_restaurants_own_timezone(): void
    {
        $delhi = $this->restaurantOpenDaily('11:00:00', '23:00:00', 'Asia/Kolkata');
        $london = $this->restaurantOpenDaily('11:00:00', '23:00:00', 'Europe/London');

        // 07:00 UTC is 12:30 in Delhi but only 08:00 in London.
        $moment = CarbonImmutable::parse('2026-09-08 07:00', 'UTC');

        $this->assertTrue($this->hours->isOpen($delhi, $moment));
        $this->assertFalse($this->hours->isOpen($london, $moment));
    }

    #[Test]
    public function it_reports_when_the_restaurant_next_opens(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');

        $next = $this->hours->nextOpensAt($restaurant, $this->at('2026-09-08 09:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-09-08 11:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function next_opening_rolls_over_to_tomorrow_after_closing(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');

        $next = $this->hours->nextOpensAt($restaurant, $this->at('2026-09-08 23:30'));

        $this->assertSame('2026-09-09 11:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function next_opening_skips_a_holiday(): void
    {
        $restaurant = $this->restaurantOpenDaily('11:00:00', '23:00:00');
        $restaurant->holidays()->create(['date' => '2026-09-09', 'reason' => 'Closed']);
        $restaurant->load(['hours', 'holidays']);

        $next = $this->hours->nextOpensAt($restaurant, $this->at('2026-09-08 23:30'));

        $this->assertSame('2026-09-10 11:00', $next->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_restaurant_with_no_schedule_is_never_open(): void
    {
        $restaurant = Restaurant::factory()->create()->load(['hours', 'holidays']);

        $this->assertFalse($this->hours->isOpen($restaurant, $this->at('2026-09-08 12:00')));
        $this->assertNull($this->hours->nextOpensAt($restaurant, $this->at('2026-09-08 12:00')));
    }
}
