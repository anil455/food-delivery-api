<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $spiceRoute;

    private Restaurant $noodleBar;

    private Product $paneerTikka;

    private Product $shoyuRamen;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spiceRoute = Restaurant::factory()->at(28.6315, 77.2167)->create([
            'name' => 'Spice Route', 'slug' => 'spice-route',
            'min_order_amount' => 19900, 'delivery_fee_base' => 2900,
            'delivery_fee_per_km' => 800, 'packaging_fee' => 1500, 'tax_percentage' => 5,
        ]);
        $this->noodleBar = Restaurant::factory()->at(28.5700, 77.3210)->create([
            'name' => 'Noodle Bar', 'slug' => 'noodle-bar',
        ]);

        $this->paneerTikka = $this->makeProduct($this->spiceRoute, 'Paneer Tikka', 24900);
        $this->shoyuRamen = $this->makeProduct($this->noodleBar, 'Shoyu Ramen', 34900);

        $this->customer = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);
        Sanctum::actingAs($this->customer, ['customer']);
    }

    private function makeProduct(Restaurant $restaurant, string $name, int $priceMinor): Product
    {
        $category = Category::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->first()
            ?? $restaurant->categories()->create(['name' => 'Menu', 'slug' => 'menu-'.$restaurant->id]);

        return $restaurant->products()->create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'base_price' => $priceMinor,
        ]);
    }

    private function addItem(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->paneerTikka->id,
            'quantity' => 1,
            ...$overrides,
        ]);
    }

    // ── The single-restaurant rule ──────────────────────────────────────────

    #[Test]
    public function adding_a_product_from_another_restaurant_is_refused_with_a_conflict(): void
    {
        $this->addItem()->assertCreated();

        // Switch store, then try to add from the new one while the old cart lives.
        $this->postJson('/api/v1/store-context', ['restaurant_id' => $this->noodleBar->id])->assertOk();

        $response = $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->shoyuRamen->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'CART_RESTAURANT_MISMATCH')
            ->assertJsonPath('data.current_restaurant.name', 'Spice Route')
            ->assertJsonPath('data.requested_restaurant.name', 'Noodle Bar')
            ->assertJsonPath('data.cart_item_count', 1);

        // Nothing was written: the cart is untouched.
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('carts', [
            'user_id' => $this->customer->id,
            'restaurant_id' => $this->spiceRoute->id,
        ]);
    }

    #[Test]
    public function replace_cart_clears_the_old_restaurant_and_moves_the_cart(): void
    {
        $this->addItem()->assertCreated();
        $this->postJson('/api/v1/store-context', ['restaurant_id' => $this->noodleBar->id])->assertOk();

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->shoyuRamen->id,
            'quantity' => 1,
            'replace_cart' => true,
        ])->assertCreated();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('carts', [
            'user_id' => $this->customer->id,
            'restaurant_id' => $this->noodleBar->id,
        ]);

        $items = $this->getJson('/api/v1/cart')->assertOk()->json('data.items');
        $this->assertSame('Shoyu Ramen', $items[0]['product']['name']);
    }

    #[Test]
    public function a_product_id_from_another_restaurant_is_rejected_by_validation(): void
    {
        // The IDOR probe: valid product id, wrong store, no store switch first.
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->shoyuRamen->id,
            'quantity' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id');

        $this->assertDatabaseCount('cart_items', 0);
    }

    #[Test]
    public function a_user_only_ever_has_one_cart(): void
    {
        $this->addItem()->assertCreated();
        $this->addItem()->assertCreated();

        $this->assertSame(1, \App\Models\Cart::query()->where('user_id', $this->customer->id)->count());
        $this->assertDatabaseCount('cart_items', 2);
    }

    // ── Server-side pricing ─────────────────────────────────────────────────

    #[Test]
    public function totals_are_computed_from_the_database_not_from_the_request(): void
    {
        $this->addItem(['quantity' => 2])->assertCreated();

        $totals = $this->getJson('/api/v1/cart')->assertOk()->json('data.totals');

        // 249.00 x 2 = 498.00
        $this->assertSame(49800, $totals['subtotal']['minor']);
        // 5% of 498.00 = 24.90
        $this->assertSame(2490, $totals['tax']['minor']);
        $this->assertSame('498.00', $totals['subtotal']['amount']);
    }

    #[Test]
    public function a_client_supplied_price_is_ignored_entirely(): void
    {
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->paneerTikka->id,
            'quantity' => 1,
            // A tampered client trying to set its own price.
            'base_price' => 1,
            'unit_price' => 1,
            'line_total' => 1,
        ])->assertCreated();

        $totals = $this->getJson('/api/v1/cart')->assertOk()->json('data.totals');

        $this->assertSame(24900, $totals['subtotal']['minor']);
    }

    #[Test]
    public function the_live_product_price_wins_over_the_price_at_add(): void
    {
        $this->addItem()->assertCreated();

        // The restaurant raises the price after the item is in the cart.
        $this->paneerTikka->forceFill(['base_price' => 29900])->save();

        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');

        $this->assertSame(29900, $cart['totals']['subtotal']['minor']);
        // And the customer is told, rather than silently charged more.
        $this->assertTrue($cart['items'][0]['price_changed']);
    }

    #[Test]
    public function delivery_fee_uses_the_distance_to_the_delivery_address(): void
    {
        $this->customer->addresses()->create([
            'label' => 'Home',
            'address_line1' => 'Nearby',
            'city' => 'New Delhi', 'state' => 'Delhi', 'postal_code' => '110001',
            // Roughly 2.5 km from the restaurant.
            'latitude' => 28.6500, 'longitude' => 77.2280,
            'is_default' => true,
        ]);

        $this->addItem()->assertCreated();

        $totals = $this->getJson('/api/v1/cart')->assertOk()->json('data.totals');

        // Base 29.00 plus 8.00 per started km.
        $this->assertNotNull($totals['distance_km']);
        $this->assertGreaterThan(2900, $totals['delivery_fee']['minor']);
        $this->assertNotNull($totals['estimated_minutes']);
    }

    #[Test]
    public function it_reports_whether_the_minimum_order_is_met(): void
    {
        $cheap = $this->makeProduct($this->spiceRoute, 'Masala Chai', 4000);

        $this->postJson('/api/v1/cart/items', ['product_id' => $cheap->id, 'quantity' => 1])
            ->assertCreated();

        $totals = $this->getJson('/api/v1/cart')->assertOk()->json('data.totals');

        // 40.00 against a 199.00 minimum.
        $this->assertFalse($totals['meets_minimum_order']);
        $this->assertSame(19900, $totals['minimum_order_amount']['minor']);
    }

    // ── Item management ─────────────────────────────────────────────────────

    #[Test]
    public function a_quantity_of_zero_removes_the_line(): void
    {
        $itemId = $this->addItem()->assertCreated()->json('data.items.0.id');

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 0])->assertOk();

        $this->assertDatabaseCount('cart_items', 0);
    }

    #[Test]
    public function an_item_can_be_removed_and_the_cart_cleared(): void
    {
        $itemId = $this->addItem()->assertCreated()->json('data.items.0.id');

        $this->deleteJson("/api/v1/cart/items/{$itemId}")->assertOk();
        $this->assertDatabaseCount('cart_items', 0);

        $this->addItem()->assertCreated();
        $this->deleteJson('/api/v1/cart')->assertOk();
        $this->assertDatabaseCount('cart_items', 0);
    }

    #[Test]
    public function a_customer_cannot_touch_another_customers_cart_item(): void
    {
        $stranger = User::factory()->create(['selected_restaurant_id' => $this->spiceRoute->id]);

        // user_id is guarded on Cart, so even a test has to be explicit about
        // pointing a cart at a user. That guard is the point.
        $cart = new \App\Models\Cart;
        $cart->forceFill([
            'user_id' => $stranger->id,
            'restaurant_id' => $this->spiceRoute->id,
        ])->save();
        $foreignItem = $cart->items()->create([
            'restaurant_id' => $this->spiceRoute->id,
            'product_id' => $this->paneerTikka->id,
            'quantity' => 1,
            'price_at_add' => 24900,
        ]);

        $this->addItem()->assertCreated();

        // Item lookups start from the caller cart, so a real id from another
        // customer simply does not resolve.
        $this->patchJson("/api/v1/cart/items/{$foreignItem->id}", ['quantity' => 99])->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$foreignItem->id}")->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $foreignItem->id, 'quantity' => 1]);
    }

    #[Test]
    public function an_unavailable_product_cannot_be_added(): void
    {
        $this->paneerTikka->forceFill(['is_available' => false])->save();

        $this->addItem()
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
    }

    #[Test]
    public function the_cart_is_empty_but_well_formed_for_a_new_customer(): void
    {
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.totals', null);
    }
}
