<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menu data must never cross restaurants, whether the caller is browsing
 * publicly by slug or signed in with a selected store.
 */
class MenuIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $spiceRoute;

    private Restaurant $noodleBar;

    private Category $spiceCategory;

    private Category $noodleCategory;

    private Product $spiceProduct;

    private Product $noodleProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spiceRoute = Restaurant::factory()->create(['name' => 'Spice Route', 'slug' => 'spice-route']);
        $this->noodleBar = Restaurant::factory()->create(['name' => 'Noodle Bar', 'slug' => 'noodle-bar']);

        $this->spiceCategory = $this->spiceRoute->categories()->create([
            'name' => 'Tandoor', 'slug' => 'tandoor',
        ]);
        $this->noodleCategory = $this->noodleBar->categories()->create([
            'name' => 'Ramen', 'slug' => 'ramen',
        ]);

        $this->spiceProduct = $this->spiceRoute->products()->create([
            'category_id' => $this->spiceCategory->id,
            'name' => 'Paneer Tikka', 'slug' => 'paneer-tikka', 'base_price' => 24900,
        ]);
        $this->noodleProduct = $this->noodleBar->products()->create([
            'category_id' => $this->noodleCategory->id,
            'name' => 'Shoyu Ramen', 'slug' => 'shoyu-ramen', 'base_price' => 34900,
        ]);
    }

    // ── Public browsing by slug ─────────────────────────────────────────────

    #[Test]
    public function public_categories_only_show_that_restaurants_categories(): void
    {
        $names = $this->getJson('/api/v1/restaurants/spice-route/categories')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Tandoor'], $names);
    }

    #[Test]
    public function public_products_only_show_that_restaurants_products(): void
    {
        $names = $this->getJson('/api/v1/restaurants/spice-route/products')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Paneer Tikka'], $names);
    }

    #[Test]
    public function a_product_slug_from_another_restaurant_is_not_found(): void
    {
        // The slug exists, but not under this restaurant.
        $this->getJson('/api/v1/restaurants/spice-route/products/shoyu-ramen')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    #[Test]
    public function filtering_by_another_restaurants_category_id_returns_nothing(): void
    {
        // The classic cross-tenant probe: a real id, wrong tenant. The scope has
        // already constrained the table, so it matches nothing rather than leaking.
        $response = $this->getJson(
            '/api/v1/restaurants/spice-route/products?category_id='.$this->noodleCategory->id
        )->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    // ── Signed-in browsing via selected store ───────────────────────────────

    #[Test]
    public function the_menu_follows_the_selected_store(): void
    {
        $customer = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);
        Sanctum::actingAs($customer, ['customer']);

        $this->assertSame(
            ['Paneer Tikka'],
            $this->getJson('/api/v1/menu/products')->assertOk()->json('data.*.name'),
        );
    }

    #[Test]
    public function changing_the_selected_store_changes_the_menu(): void
    {
        $customer = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);
        Sanctum::actingAs($customer, ['customer']);

        $this->assertSame(
            ['Paneer Tikka'],
            $this->getJson('/api/v1/menu/products')->assertOk()->json('data.*.name'),
        );

        $this->postJson('/api/v1/store-context', ['restaurant_id' => $this->noodleBar->id])->assertOk();

        $this->assertSame(
            ['Shoyu Ramen'],
            $this->getJson('/api/v1/menu/products')->assertOk()->json('data.*.name'),
        );
    }

    #[Test]
    public function a_product_id_from_another_store_is_not_found(): void
    {
        $customer = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);
        Sanctum::actingAs($customer, ['customer']);

        $this->getJson('/api/v1/menu/products/'.$this->noodleProduct->id)
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_with_no_selected_store_is_told_to_choose_one(): void
    {
        Sanctum::actingAs(User::factory()->create(['selected_restaurant_id' => null]), ['customer']);

        $this->getJson('/api/v1/menu/products')
            ->assertStatus(400)
            ->assertJsonPath('code', 'NO_STORE_SELECTED');
    }

    #[Test]
    public function a_deactivated_selected_store_stops_serving_its_menu(): void
    {
        $customer = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);
        Sanctum::actingAs($customer, ['customer']);

        $this->spiceRoute->forceFill(['status' => 'suspended'])->save();

        $this->getJson('/api/v1/menu/products')
            ->assertStatus(403)
            ->assertJsonPath('code', 'RESTAURANT_NOT_ACCESSIBLE');
    }

    // ── Availability ────────────────────────────────────────────────────────

    #[Test]
    public function unavailable_and_inactive_products_are_hidden_from_customers(): void
    {
        $this->spiceRoute->products()->create([
            'category_id' => $this->spiceCategory->id,
            'name' => 'Sold Out Dish', 'slug' => 'sold-out', 'base_price' => 19900,
            'is_available' => false,
        ]);
        $this->spiceRoute->products()->create([
            'category_id' => $this->spiceCategory->id,
            'name' => 'Retired Dish', 'slug' => 'retired', 'base_price' => 19900,
            'is_active' => false,
        ]);

        $names = $this->getJson('/api/v1/restaurants/spice-route/products')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Paneer Tikka'], $names);
    }

    #[Test]
    public function prices_are_returned_as_exact_strings_with_minor_units(): void
    {
        $product = $this->getJson('/api/v1/restaurants/spice-route/products/paneer-tikka')
            ->assertOk()
            ->json('data');

        // Never a float: the client can render this without any parsing risk.
        $this->assertSame('249.00', $product['base_price']['amount']);
        $this->assertSame(24900, $product['base_price']['minor']);
        $this->assertSame('INR', $product['base_price']['currency']);
    }
}
