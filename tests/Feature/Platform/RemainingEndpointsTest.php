<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The endpoints added last: public config, account deletion, reorder, and the
 * platform reporting surface.
 */
class RemainingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Product $product;

    private User $customer;

    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->at(28.6315, 77.2167)->create([
            'name' => 'Spice Route', 'slug' => 'spice-route',
            'min_order_amount' => 0, 'delivery_radius_km' => 10,
        ]);

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

        $this->customer = User::factory()->create(['selected_restaurant_id' => $this->restaurant->id]);
        $this->addressId = $this->customer->addresses()->create([
            'label' => 'Home', 'address_line1' => 'Flat 9',
            'city' => 'New Delhi', 'state' => 'Delhi', 'postal_code' => '110001',
            'latitude' => 28.6350, 'longitude' => 77.2200, 'is_default' => true,
        ])->id;
    }

    private function asCustomer(): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->customer, ['customer']);
    }

    private function placeOrder(): int
    {
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'quantity' => 2])
            ->assertCreated();

        return $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
    }

    // ── Public config ───────────────────────────────────────────────────────

    #[Test]
    public function the_config_endpoint_needs_no_authentication(): void
    {
        $data = $this->getJson('/api/v1/config')->assertOk()->json('data');

        $this->assertSame('INR', $data['currency']['code']);
        $this->assertSame(100, $data['currency']['minor_units']);
        $this->assertSame(6, $data['otp']['length']);
        // The client renders a status timeline from this rather than hardcoding
        // the state machine that lives in the enum.
        $this->assertCount(8, $data['order_statuses']);
        $this->assertEqualsWithDelta(25.0, (float) $data['search']['max_radius_km'], 0.0001);
    }

    // ── Reorder ─────────────────────────────────────────────────────────────

    #[Test]
    public function a_past_order_can_be_reordered_into_the_cart(): void
    {
        $this->asCustomer();
        $orderId = $this->placeOrder();

        $this->assertDatabaseCount('cart_items', 0);

        $this->postJson("/api/v1/orders/{$orderId}/reorder")
            ->assertOk()
            ->assertJsonPath('data.items_added', 1)
            ->assertJsonPath('data.items_skipped', []);

        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $this->assertSame(2, $cart['item_count']);
    }

    #[Test]
    public function reorder_prices_from_the_menu_not_from_the_old_order(): void
    {
        $this->asCustomer();
        $orderId = $this->placeOrder();

        // The restaurant reprices after the original order.
        $this->product->forceFill(['base_price' => 29900])->save();

        $this->postJson("/api/v1/orders/{$orderId}/reorder")->assertOk();

        // 299.00 x 2, not the 249.00 the old order recorded.
        $this->assertSame(
            59800,
            $this->getJson('/api/v1/cart')->assertOk()->json('data.totals.subtotal.minor'),
        );
    }

    #[Test]
    public function reorder_skips_items_that_are_no_longer_available(): void
    {
        $this->asCustomer();
        $orderId = $this->placeOrder();

        $this->product->forceFill(['is_available' => false])->save();

        // Best-effort by design: nothing orderable means a clear 409, not a
        // half-filled cart.
        $this->postJson("/api/v1/orders/{$orderId}/reorder")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE')
            ->assertJsonPath('data.skipped', ['Paneer Tikka']);
    }

    #[Test]
    public function another_customers_order_cannot_be_reordered(): void
    {
        $this->asCustomer();
        $orderId = $this->placeOrder();

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->postJson("/api/v1/orders/{$orderId}/reorder")->assertNotFound();
    }

    // ── Account deletion ────────────────────────────────────────────────────

    #[Test]
    public function a_customer_can_delete_their_account(): void
    {
        $this->asCustomer();
        $this->placeOrder();

        $userId = $this->customer->id;
        $phone = $this->customer->phone;

        $this->deleteJson('/api/v1/auth/account')->assertOk();

        $this->assertSoftDeleted('users', ['id' => $userId]);

        $scrubbed = User::withTrashed()->find($userId);
        $this->assertNull($scrubbed->phone);
        $this->assertNull($scrubbed->name);
        $this->assertSame('deleted', $scrubbed->status);

        // Sessions, addresses and the cart are gone.
        $this->assertSame(0, $scrubbed->tokens()->count());
        $this->assertDatabaseCount('carts', 0);

        // The order survives: it is a financial record the restaurant must keep,
        // and it carries its own address snapshot rather than a live join.
        $this->assertSame(1, Order::withoutTenantScope()->where('user_id', $userId)->count());

        // The phone is freed, so the person can sign up again.
        $this->assertDatabaseMissing('users', ['phone' => $phone, 'deleted_at' => null]);
    }

    #[Test]
    public function deletion_revokes_the_token_immediately(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device', ['customer'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/auth/account')
            ->assertOk();

        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    // ── Platform reporting ──────────────────────────────────────────────────

    #[Test]
    public function a_super_admin_sees_platform_wide_stats(): void
    {
        $this->asCustomer();
        $this->placeOrder();

        Restaurant::factory()->create(['name' => 'Second', 'slug' => 'second']);

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);

        $stats = $this->getJson('/api/v1/super-admin/dashboard/stats')->assertOk()->json('data');

        $this->assertSame(2, $stats['restaurants']['total']);
        $this->assertSame(1, $stats['orders']['total']);
        $this->assertSame(1, $stats['orders']['today']);
        $this->assertArrayHasKey('minor', $stats['revenue']['today']);
        $this->assertCount(2, $stats['top_restaurants']);
    }

    #[Test]
    public function a_super_admin_sees_orders_across_every_restaurant(): void
    {
        $this->asCustomer();
        $this->placeOrder();

        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);

        $this->getJson('/api/v1/super-admin/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_super_admin_can_list_and_block_users(): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->superAdmin()->create(), ['admin']);

        $this->getJson('/api/v1/super-admin/users?type=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->customer->createToken('device', ['customer']);

        $this->patchJson("/api/v1/super-admin/users/{$this->customer->id}/status", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        // Blocking ends every live session at once.
        $this->assertSame(0, $this->customer->fresh()->tokens()->count());
    }

    #[Test]
    public function restaurant_staff_cannot_reach_platform_reporting(): void
    {
        $staff = User::factory()->staff()->create();
        $this->restaurant->staff()->attach($staff->getKey(), [
            'role' => StaffRole::Owner->value, 'status' => 'active',
        ]);

        app('auth')->forgetGuards();
        Sanctum::actingAs($staff, ['restaurant']);

        foreach ([
            '/api/v1/super-admin/users',
            '/api/v1/super-admin/orders',
            '/api/v1/super-admin/dashboard/stats',
        ] as $url) {
            $this->getJson($url)->assertStatus(403);
        }
    }

    #[Test]
    public function a_customer_cannot_reach_platform_reporting(): void
    {
        $this->asCustomer();

        $this->getJson('/api/v1/super-admin/dashboard/stats')->assertStatus(403);
    }
}
