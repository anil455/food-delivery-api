<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Product $product;

    private User $customer;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->at(28.6315, 77.2167)->create([
            'name' => 'Spice Route', 'slug' => 'spice-route',
            'min_order_amount' => 19900, 'delivery_fee_base' => 2900,
            'delivery_fee_per_km' => 800, 'packaging_fee' => 1500,
            'tax_percentage' => 5, 'delivery_radius_km' => 10,
            'avg_prep_time_minutes' => 25,
        ]);

        // Open around the clock so the schedule never interferes with the
        // assertions in this file; opening hours have their own test.
        foreach (range(0, 6) as $day) {
            $this->restaurant->hours()->create([
                'day_of_week' => $day, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
            ]);
        }

        $category = $this->restaurant->categories()->create(['name' => 'Menu', 'slug' => 'menu']);
        $this->product = $this->restaurant->products()->create([
            'category_id' => $category->id,
            'name' => 'Paneer Tikka', 'slug' => 'paneer-tikka', 'base_price' => 24900,
        ]);

        $this->customer = User::factory()->create([
            'name' => 'Aarav Kapoor',
            'selected_restaurant_id' => $this->restaurant->id,
        ]);

        $this->address = $this->customer->addresses()->create([
            'label' => 'Home',
            'address_line1' => 'Flat 402',
            'city' => 'New Delhi', 'state' => 'Delhi', 'postal_code' => '110001',
            'latitude' => 28.6500, 'longitude' => 77.2280,
            'is_default' => true,
        ]);

        Sanctum::actingAs($this->customer, ['customer']);
    }

    private function fillCart(int $quantity = 1): void
    {
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ])->assertCreated();
    }

    private function placeOrder(array $overrides = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'address_id' => $this->address->id,
            'payment_method' => 'cod',
            ...$overrides,
        ]);
    }

    #[Test]
    public function a_customer_can_place_an_order_from_their_cart(): void
    {
        $this->fillCart();

        $response = $this->placeOrder()->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.customer_name', 'Aarav Kapoor');

        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z0-9]{6}$/', $response->json('data.order_number'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    #[Test]
    public function order_totals_are_recomputed_server_side(): void
    {
        $this->fillCart(2);

        $totals = $this->placeOrder()->assertCreated()->json('data.totals');

        // 249.00 x 2 = 498.00 subtotal, 5% tax = 24.90.
        $this->assertSame(49800, $totals['subtotal']['minor']);
        $this->assertSame(2490, $totals['tax']['minor']);
        $this->assertSame(1500, $totals['packaging_fee']['minor']);

        // subtotal + tax + delivery + packaging, all integer arithmetic.
        $expected = 49800 + 2490 + $totals['delivery_fee']['minor'] + 1500;
        $this->assertSame($expected, $totals['grand_total']['minor']);
    }

    #[Test]
    public function a_client_supplied_total_is_ignored(): void
    {
        $this->fillCart();

        $totals = $this->placeOrder([
            'subtotal' => 1,
            'grand_total' => 1,
            'total' => 1,
        ])->assertCreated()->json('data.totals');

        $this->assertSame(24900, $totals['subtotal']['minor']);
    }

    #[Test]
    public function order_items_snapshot_the_product_at_purchase_time(): void
    {
        $this->fillCart();
        $orderId = $this->placeOrder()->assertCreated()->json('data.id');

        // The restaurant renames and reprices the product afterwards.
        $this->product->forceFill(['name' => 'Renamed Dish', 'base_price' => 99900])->save();

        $item = $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->json('data.items.0');

        // History must not move when the menu does.
        $this->assertSame('Paneer Tikka', $item['product_name']);
        $this->assertSame(24900, $item['unit_price']['minor']);
    }

    #[Test]
    public function the_delivery_address_is_snapshotted_and_survives_deletion(): void
    {
        $this->fillCart();
        $orderId = $this->placeOrder()->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/addresses/{$this->address->id}")->assertOk();

        $order = $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->json('data');

        $this->assertSame('Flat 402', $order['delivery_address']['address_line1']);
        $this->assertSame('110001', $order['delivery_address']['postal_code']);
    }

    #[Test]
    public function placing_an_order_empties_the_cart_but_keeps_it(): void
    {
        $this->fillCart();
        $this->placeOrder()->assertCreated();

        $this->assertDatabaseCount('cart_items', 0);
        // One cart per user is a schema invariant, so the row stays.
        $this->assertDatabaseCount('carts', 1);
    }

    #[Test]
    public function an_empty_cart_cannot_become_an_order(): void
    {
        $this->placeOrder()
            ->assertStatus(422)
            ->assertJsonPath('code', 'CART_EMPTY');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function the_same_idempotency_key_returns_the_original_order(): void
    {
        $this->fillCart();

        $first = $this->placeOrder([], ['Idempotency-Key' => 'checkout-abc-123'])->assertCreated();

        // The client retried because the first response never arrived.
        $second = $this->placeOrder([], ['Idempotency-Key' => 'checkout-abc-123'])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.order_number'), $second->json('data.order_number'));
        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function an_order_below_the_minimum_is_refused(): void
    {
        $cheap = $this->restaurant->products()->create([
            'category_id' => $this->product->category_id,
            'name' => 'Masala Chai', 'slug' => 'masala-chai', 'base_price' => 4000,
        ]);

        $this->postJson('/api/v1/cart/items', ['product_id' => $cheap->id, 'quantity' => 1])
            ->assertCreated();

        $this->placeOrder()
            ->assertStatus(422)
            ->assertJsonPath('code', 'MIN_ORDER_NOT_MET');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function an_order_cannot_be_placed_at_a_closed_restaurant(): void
    {
        $this->fillCart();
        $this->restaurant->forceFill(['is_accepting_orders' => false])->save();

        $this->placeOrder()
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESTAURANT_NOT_ACCEPTING_ORDERS');
    }

    #[Test]
    public function an_address_outside_the_delivery_radius_is_refused(): void
    {
        $this->fillCart();

        $far = $this->customer->addresses()->create([
            'label' => 'Far away',
            'address_line1' => 'Gurugram',
            'city' => 'Gurugram', 'state' => 'Haryana', 'postal_code' => '122001',
            'latitude' => 28.4595, 'longitude' => 77.0266,   // roughly 28 km
            'is_default' => false,
        ]);

        $this->placeOrder(['address_id' => $far->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OUTSIDE_DELIVERY_RADIUS');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function another_customers_address_cannot_be_used(): void
    {
        $this->fillCart();

        $stranger = User::factory()->create();
        $foreignAddress = $stranger->addresses()->create([
            'label' => 'Someone else',
            'address_line1' => 'Elsewhere',
            'city' => 'New Delhi', 'state' => 'Delhi', 'postal_code' => '110001',
            'latitude' => 28.6400, 'longitude' => 77.2200,
            'is_default' => true,
        ]);

        $this->placeOrder(['address_id' => $foreignAddress->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    #[Test]
    public function placing_an_order_writes_the_first_status_history_row(): void
    {
        $this->fillCart();
        $orderId = $this->placeOrder()->assertCreated()->json('data.id');

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'changed_by_user_id' => $this->customer->id,
        ]);
    }

    #[Test]
    public function a_customer_cannot_read_another_customers_order(): void
    {
        $this->fillCart();
        $orderId = $this->placeOrder()->assertCreated()->json('data.id');

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->getJson("/api/v1/orders/{$orderId}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$orderId}/cancel")->assertNotFound();
    }

    #[Test]
    public function order_history_spans_every_store_the_customer_has_used(): void
    {
        $this->fillCart();
        $this->placeOrder()->assertCreated();

        // A second restaurant, ordered from after switching stores.
        $other = Restaurant::factory()->at(28.6320, 77.2170)->create([
            'name' => 'Noodle Bar', 'slug' => 'noodle-bar',
            'min_order_amount' => 0, 'delivery_radius_km' => 10,
        ]);
        foreach (range(0, 6) as $day) {
            $other->hours()->create(['day_of_week' => $day, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        }
        $otherCategory = $other->categories()->create(['name' => 'Menu', 'slug' => 'menu']);
        $ramen = $other->products()->create([
            'category_id' => $otherCategory->id,
            'name' => 'Shoyu Ramen', 'slug' => 'shoyu-ramen', 'base_price' => 34900,
        ]);

        $this->postJson('/api/v1/store-context', ['restaurant_id' => $other->id])->assertOk();
        $this->postJson('/api/v1/cart/items', [
            'product_id' => $ramen->id, 'quantity' => 1, 'replace_cart' => true,
        ])->assertCreated();
        $this->placeOrder()->assertCreated();

        // Two stores, one history: the tenant scope must not hide the first.
        $orders = $this->getJson('/api/v1/orders')->assertOk()->json('data');

        $this->assertCount(2, $orders);
        $this->assertSame(2, Order::withoutTenantScope()->count());
    }
}
