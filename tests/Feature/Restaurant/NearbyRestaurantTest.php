<?php

declare(strict_types=1);

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant;
use App\Services\Geo\GeoPoint;
use App\Services\Geo\HaversineCalculator;
use App\Services\Geo\NearbyRestaurantFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NearbyRestaurantTest extends TestCase
{
    use RefreshDatabase;

    // Connaught Place, central Delhi.
    private const ORIGIN_LAT = 28.6315;

    private const ORIGIN_LNG = 77.2167;

    private function nearby(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/restaurants/nearby?'.http_build_query([
            'latitude' => self::ORIGIN_LAT,
            'longitude' => self::ORIGIN_LNG,
            ...$params,
        ]));
    }

    #[Test]
    public function the_sql_distance_agrees_with_the_php_calculation(): void
    {
        /*
         * The regression guard that matters most in this file. The SQL Haversine
         * expression and the PHP one are written independently; if either is
         * edited into something subtly wrong, the API keeps returning
         * plausible-looking distances and only this test notices.
         */
        $origin = new GeoPoint(self::ORIGIN_LAT, self::ORIGIN_LNG);

        $points = [
            [28.5700, 77.3210],   // Noida Sector 18
            [28.4595, 77.0266],   // Gurugram Cyber City
            [28.6315, 77.2167],   // the origin itself
            [28.7041, 77.1025],   // north-west Delhi
        ];

        foreach ($points as [$latitude, $longitude]) {
            Restaurant::factory()->at($latitude, $longitude)->create(['delivery_radius_km' => 50]);
        }

        $found = app(NearbyRestaurantFinder::class)->find($origin, radiusKm: 25.0);
        $calculator = app(HaversineCalculator::class);

        $this->assertGreaterThan(0, $found->count());

        foreach ($found as $restaurant) {
            $expected = $calculator->kilometresBetween(
                $origin,
                new GeoPoint((float) $restaurant->latitude, (float) $restaurant->longitude),
            );

            $this->assertEqualsWithDelta(
                $expected,
                (float) $restaurant->distance_km,
                0.001,
                "SQL and PHP disagree for restaurant {$restaurant->id}",
            );
        }
    }

    #[Test]
    public function a_restaurant_at_the_exact_origin_reports_zero_and_is_not_dropped(): void
    {
        /*
         * Without the LEAST(1.0, ...) clamp, floating-point error pushes the
         * cosine fractionally above 1, ACOS returns NULL, and the single closest
         * restaurant silently vanishes from the results.
         */
        Restaurant::factory()->at(self::ORIGIN_LAT, self::ORIGIN_LNG)->create(['name' => 'Right Here']);

        $response = $this->nearby()->assertOk();

        $this->assertSame('Right Here', $response->json('data.0.name'));
        $this->assertEqualsWithDelta(0.0, (float) $response->json('data.0.distance'), 0.0001);
    }

    #[Test]
    public function it_measures_a_known_real_world_distance(): void
    {
        // Connaught Place to Noida Sector 18 is roughly 12 km as the crow flies.
        Restaurant::factory()->at(28.5700, 77.3210)->create(['name' => 'Noodle Bar']);

        $distance = $this->nearby(['radius' => 25])->assertOk()->json('data.0.distance');

        $this->assertGreaterThan(11.5, $distance);
        $this->assertLessThan(13.0, $distance);
    }

    #[Test]
    public function results_are_ordered_by_distance(): void
    {
        Restaurant::factory()->at(28.7041, 77.1025)->create(['name' => 'Far']);      // ~14 km
        Restaurant::factory()->at(28.6350, 77.2200)->create(['name' => 'Nearest']);  // ~0.5 km
        Restaurant::factory()->at(28.6600, 77.2300)->create(['name' => 'Middle']);   // ~3 km

        $names = $this->nearby(['radius' => 25])->assertOk()->json('data.*.name');

        $this->assertSame(['Nearest', 'Middle', 'Far'], $names);
    }

    #[Test]
    public function restaurants_beyond_the_search_radius_are_excluded(): void
    {
        Restaurant::factory()->at(28.6350, 77.2200)->create(['name' => 'Inside']);
        Restaurant::factory()->at(28.4595, 77.0266)->create(['name' => 'Outside']);  // ~28 km

        $names = $this->nearby(['radius' => 5])->assertOk()->json('data.*.name');

        $this->assertSame(['Inside'], $names);
    }

    #[Test]
    public function only_active_restaurants_are_discoverable(): void
    {
        Restaurant::factory()->at(28.6320, 77.2170)->create(['name' => 'Open For Business']);
        Restaurant::factory()->inactive()->at(28.6321, 77.2171)->create(['name' => 'Inactive']);
        Restaurant::factory()->suspended()->at(28.6322, 77.2172)->create(['name' => 'Suspended']);
        Restaurant::factory()->pendingApproval()->at(28.6323, 77.2173)->create(['name' => 'Pending']);

        $names = $this->nearby()->assertOk()->json('data.*.name');

        $this->assertSame(['Open For Business'], $names);
    }

    #[Test]
    public function a_soft_deleted_restaurant_disappears(): void
    {
        $restaurant = Restaurant::factory()->at(28.6320, 77.2170)->create();
        $restaurant->delete();

        $this->nearby()->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function it_flags_whether_each_restaurant_delivers_to_the_caller(): void
    {
        // Roughly 3 km away, but only willing to travel 1 km.
        Restaurant::factory()->at(28.6580, 77.2260)->create([
            'name' => 'Too Far To Deliver',
            'delivery_radius_km' => 1,
        ]);

        Restaurant::factory()->at(28.6350, 77.2200)->create([
            'name' => 'Will Deliver',
            'delivery_radius_km' => 10,
        ]);

        $data = collect($this->nearby(['radius' => 25])->assertOk()->json('data'))
            ->keyBy('name');

        // Being inside the search radius and being deliverable are different
        // questions, and the response answers both rather than hiding one.
        $this->assertFalse($data['Too Far To Deliver']['delivers_to_you']);
        $this->assertTrue($data['Will Deliver']['delivers_to_you']);
    }

    #[Test]
    public function only_deliverable_filters_out_restaurants_that_will_not_come(): void
    {
        Restaurant::factory()->at(28.6580, 77.2260)->create([
            'name' => 'Too Far To Deliver',
            'delivery_radius_km' => 1,
        ]);
        Restaurant::factory()->at(28.6350, 77.2200)->create([
            'name' => 'Will Deliver',
            'delivery_radius_km' => 10,
        ]);

        $names = $this->nearby(['radius' => 25, 'only_deliverable' => 'true'])
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Will Deliver'], $names);
    }

    #[Test]
    public function the_radius_is_capped_at_the_configured_maximum(): void
    {
        // 28 km away, beyond the 25 km cap, so an absurd radius must not reach it.
        Restaurant::factory()->at(28.4595, 77.0266)->create(['name' => 'Gurugram']);

        $this->nearby(['radius' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('radius');
    }

    #[Test]
    public function it_requires_coordinates(): void
    {
        $this->getJson('/api/v1/restaurants/nearby')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    #[Test]
    public function it_rejects_impossible_coordinates(): void
    {
        $this->getJson('/api/v1/restaurants/nearby?latitude=200&longitude=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    #[Test]
    public function the_response_carries_the_documented_shape(): void
    {
        Restaurant::factory()->at(28.6350, 77.2200)->create();

        $this->nearby()->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'name', 'slug', 'distance', 'distance_unit', 'is_open', 'delivers_to_you']],
            'meta' => ['origin', 'radius_km', 'count'],
        ]);
    }
}
