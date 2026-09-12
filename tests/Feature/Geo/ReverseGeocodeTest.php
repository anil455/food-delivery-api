<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Services\Geo\GeoPoint;
use App\Services\Geo\ReverseGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReverseGeocodeTest extends TestCase
{
    private function fakeLocationIqResponse(): array
    {
        return [
            'display_name' => 'Durga Colony, Jharsa Village, Sector 39, Gurugram, Haryana, 122001, India',
            'address' => [
                'suburb' => 'Durga Colony',
                'village' => 'Jharsa Village',
                'city' => 'Gurugram',
                'state' => 'Haryana',
                'postcode' => '122001',
                'country' => 'India',
            ],
        ];
    }

    #[Test]
    public function it_resolves_an_address_for_the_given_coordinates(): void
    {
        Http::fake([
            'us1.locationiq.com/*' => Http::response($this->fakeLocationIqResponse()),
        ]);

        $response = $this->getJson('/api/v1/geocode/reverse?latitude=28.4650&longitude=77.0298')
            ->assertOk();

        $response->assertJson([
            'success' => true,
            'data' => [
                'area' => 'Durga Colony',
                'city' => 'Gurugram',
                'state' => 'Haryana',
                'postal_code' => '122001',
                'country' => 'India',
            ],
        ]);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/reverse')
            && $request['lat'] === 28.4650
            && $request['lon'] === 77.0298);
    }

    #[Test]
    public function it_caches_results_per_coordinate_so_locationiq_is_not_hammered(): void
    {
        Http::fake([
            'us1.locationiq.com/*' => Http::response($this->fakeLocationIqResponse()),
        ]);

        $point = new GeoPoint(28.4650, 77.0298);
        $geocoder = app(ReverseGeocoder::class);

        $geocoder->locate($point);
        $geocoder->locate($point);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reports_failure_without_crashing_when_locationiq_is_unreachable(): void
    {
        Http::fake([
            'us1.locationiq.com/*' => Http::response(null, 503),
        ]);

        $this->getJson('/api/v1/geocode/reverse?latitude=28.4650&longitude=77.0298')
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'code' => 'GEOCODE_FAILED',
            ]);
    }

    #[Test]
    public function it_requires_coordinates(): void
    {
        $this->getJson('/api/v1/geocode/reverse')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }
}
