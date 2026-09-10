<?php

declare(strict_types=1);

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantHoliday;
use App\Models\RestaurantHour;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Answers "is this restaurant open right now?" and "when does it next open?".
 *
 * Three things make this harder than comparing two times, and all three are real
 * in the seed data:
 *   - every restaurant keeps its own timezone, so "now" differs per restaurant
 *   - a day can hold several slots (lunch, then dinner)
 *   - a slot whose closing time is not after its opening time runs past midnight,
 *     which means yesterday can still be keeping today open
 */
final class OpeningHoursService
{
    /** @var array<int, \Illuminate\Support\Collection> */
    private array $holidayCache = [];

    public function isOpen(Restaurant $restaurant, ?CarbonInterface $at = null): bool
    {
        // A manual switch beats the schedule: a kitchen that has stopped taking
        // orders is closed regardless of what the timetable says.
        if (! $restaurant->is_accepting_orders) {
            return false;
        }

        $now = $this->inRestaurantTime($restaurant, $at);
        $hours = $this->hoursFor($restaurant);

        if ($this->isHoliday($restaurant, $now)) {
            return false;
        }

        $time = $now->format('H:i:s');

        // Slots that began today.
        foreach ($this->slotsForDay($hours, $now->dayOfWeek) as $slot) {
            if ($this->crossesMidnight($slot)) {
                if ($time >= $slot->opens_at) {
                    return true;
                }

                continue;
            }

            if ($time >= $slot->opens_at && $time < $slot->closes_at) {
                return true;
            }
        }

        // Slots that began yesterday and have not closed yet.
        $yesterday = $now->subDay();

        if ($this->isHoliday($restaurant, $yesterday)) {
            return false;
        }

        foreach ($this->slotsForDay($hours, $yesterday->dayOfWeek) as $slot) {
            if ($this->crossesMidnight($slot) && $time < $slot->closes_at) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next moment this restaurant opens, or null if it has no schedule at
     * all within a week. Scans forward day by day, which is cheap because the
     * hours are already in memory.
     */
    public function nextOpensAt(Restaurant $restaurant, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        $now = $this->inRestaurantTime($restaurant, $at);
        $hours = $this->hoursFor($restaurant);

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->addDays($offset);

            if ($this->isHoliday($restaurant, $day)) {
                continue;
            }

            $candidates = $this->slotsForDay($hours, $day->dayOfWeek)
                ->sortBy('opens_at');

            foreach ($candidates as $slot) {
                $opensAt = $day->setTimeFromTimeString($slot->opens_at);

                if ($opensAt->greaterThan($now)) {
                    return $opensAt;
                }
            }
        }

        return null;
    }

    /** Today's slots, as plain strings, for API output. */
    public function todaysSlots(Restaurant $restaurant, ?CarbonInterface $at = null): array
    {
        $now = $this->inRestaurantTime($restaurant, $at);

        if ($this->isHoliday($restaurant, $now)) {
            return [];
        }

        return $this->slotsForDay($this->hoursFor($restaurant), $now->dayOfWeek)
            ->sortBy('opens_at')
            ->map(fn (RestaurantHour $slot): array => [
                'opens_at' => substr((string) $slot->opens_at, 0, 5),
                'closes_at' => substr((string) $slot->closes_at, 0, 5),
                'crosses_midnight' => $this->crossesMidnight($slot),
            ])
            ->values()
            ->all();
    }

    private function inRestaurantTime(Restaurant $restaurant, ?CarbonInterface $at): CarbonImmutable
    {
        $moment = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        return $moment->setTimezone($restaurant->timezone ?: config('app.timezone'));
    }

    /**
     * @return Collection<int, RestaurantHour>
     */
    private function hoursFor(Restaurant $restaurant): Collection
    {
        if ($restaurant->relationLoaded('hours')) {
            return $restaurant->getRelation('hours');
        }

        return RestaurantHour::query()
            ->forRestaurant($restaurant)
            ->get();
    }

    /**
     * @param  Collection<int, RestaurantHour>  $hours
     * @return Collection<int, RestaurantHour>
     */
    private function slotsForDay(Collection $hours, int $dayOfWeek): Collection
    {
        return $hours->filter(
            fn (RestaurantHour $hour): bool => $hour->day_of_week === $dayOfWeek
                && ! $hour->is_closed
                && $hour->opens_at !== null
                && $hour->closes_at !== null
        );
    }

    private function crossesMidnight(RestaurantHour $slot): bool
    {
        return $slot->closes_at <= $slot->opens_at;
    }

    private function isHoliday(Restaurant $restaurant, CarbonInterface $day): bool
    {
        return $this->holidaysFor($restaurant)->contains(
            fn (RestaurantHoliday $holiday): bool => $holiday->date->isSameDay($day)
        );
    }

    /**
     * Memoised per restaurant: nextOpensAt asks about up to eight consecutive
     * days, and each of those must not become its own query.
     *
     * @return Collection<int, RestaurantHoliday>
     */
    private function holidaysFor(Restaurant $restaurant): Collection
    {
        if ($restaurant->relationLoaded('holidays')) {
            return $restaurant->getRelation('holidays');
        }

        $key = (int) $restaurant->getKey();

        return $this->holidayCache[$key] ??= RestaurantHoliday::query()
            ->forRestaurant($key)
            ->get();
    }
}
