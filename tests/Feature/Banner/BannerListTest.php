<?php

declare(strict_types=1);

namespace Tests\Feature\Banner;

use App\Models\Banner;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BannerListTest extends TestCase
{
    use RefreshDatabase;

    // Connaught Place, central Delhi — same origin NearbyRestaurantTest uses.
    private const ORIGIN_LAT = 28.6315;

    private const ORIGIN_LNG = 77.2167;

    private function banners(array $params = []): TestResponse
    {
        return $this->getJson('/api/v1/banners?'.http_build_query($params));
    }

    #[Test]
    public function a_platform_banner_shows_even_with_no_location_given(): void
    {
        Banner::factory()->create(['title' => 'Platform Wide Offer']);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertSame(['Platform Wide Offer'], $names);
    }

    #[Test]
    public function a_platform_banner_shows_regardless_of_location(): void
    {
        Banner::factory()->create(['title' => 'Everywhere Offer']);

        $names = $this->banners([
            'latitude' => self::ORIGIN_LAT,
            'longitude' => self::ORIGIN_LNG,
        ])->assertOk()->json('data.*.title');

        $this->assertContains('Everywhere Offer', $names);
    }

    #[Test]
    public function a_restaurant_banner_shows_when_within_its_delivery_radius(): void
    {
        $restaurant = Restaurant::factory()
            ->at(28.6350, 77.2200) // ~0.5 km from origin
            ->create(['delivery_radius_km' => 5]);

        Banner::factory()->forRestaurant($restaurant->id)->create(['title' => 'Nearby Deal']);

        $names = $this->banners([
            'latitude' => self::ORIGIN_LAT,
            'longitude' => self::ORIGIN_LNG,
        ])->assertOk()->json('data.*.title');

        $this->assertContains('Nearby Deal', $names);
    }

    #[Test]
    public function a_restaurant_banner_is_hidden_outside_its_delivery_radius(): void
    {
        $restaurant = Restaurant::factory()
            ->at(28.4595, 77.0266) // ~28 km from origin
            ->create(['delivery_radius_km' => 5]);

        Banner::factory()->forRestaurant($restaurant->id)->create(['title' => 'Too Far Away']);

        $names = $this->banners([
            'latitude' => self::ORIGIN_LAT,
            'longitude' => self::ORIGIN_LNG,
        ])->assertOk()->json('data.*.title');

        $this->assertNotContains('Too Far Away', $names);
    }

    #[Test]
    public function a_restaurant_banner_is_hidden_when_no_location_is_given(): void
    {
        $restaurant = Restaurant::factory()->at(self::ORIGIN_LAT, self::ORIGIN_LNG)->create();

        Banner::factory()->forRestaurant($restaurant->id)->create(['title' => 'Restaurant Deal']);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertNotContains('Restaurant Deal', $names);
    }

    #[Test]
    public function an_inactive_banner_never_shows(): void
    {
        Banner::factory()->inactive()->create(['title' => 'Turned Off']);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertNotContains('Turned Off', $names);
    }

    #[Test]
    public function an_expired_banner_is_hidden(): void
    {
        Banner::factory()->expired()->create(['title' => 'Old Offer']);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertNotContains('Old Offer', $names);
    }

    #[Test]
    public function a_banner_that_has_not_started_yet_is_hidden(): void
    {
        Banner::factory()->notYetStarted()->create(['title' => 'Coming Soon']);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertNotContains('Coming Soon', $names);
    }

    #[Test]
    public function a_restaurant_banner_is_hidden_when_its_restaurant_is_inactive(): void
    {
        $restaurant = Restaurant::factory()->inactive()->at(self::ORIGIN_LAT, self::ORIGIN_LNG)->create();

        Banner::factory()->forRestaurant($restaurant->id)->create(['title' => 'Closed Kitchen Deal']);

        $names = $this->banners([
            'latitude' => self::ORIGIN_LAT,
            'longitude' => self::ORIGIN_LNG,
        ])->assertOk()->json('data.*.title');

        $this->assertNotContains('Closed Kitchen Deal', $names);
    }

    #[Test]
    public function banners_are_ordered_by_sort_order(): void
    {
        Banner::factory()->create(['title' => 'Second', 'sort_order' => 2]);
        Banner::factory()->create(['title' => 'First', 'sort_order' => 1]);
        Banner::factory()->create(['title' => 'Third', 'sort_order' => 3]);

        $names = $this->banners()->assertOk()->json('data.*.title');

        $this->assertSame(['First', 'Second', 'Third'], $names);
    }

    #[Test]
    public function it_rejects_impossible_coordinates(): void
    {
        $this->getJson('/api/v1/banners?latitude=200&longitude=500')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    #[Test]
    public function the_response_carries_the_documented_shape(): void
    {
        Banner::factory()->create();

        $this->banners()->assertOk()->assertJsonStructure([
            'success',
            'message',
            'data' => [['id', 'title', 'subtitle', 'image_path', 'link_type', 'link_value', 'sort_order', 'is_active']],
        ]);
    }
}
