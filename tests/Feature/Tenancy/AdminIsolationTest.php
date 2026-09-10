<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\StaffRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 7 exit criterion: for every tenant-owned resource, staff of
 * restaurant A must be refused every verb against restaurant B.
 *
 * This is the suite that keeps the other six isolation layers honest as the
 * codebase grows. If any of it starts passing for the wrong reason, the tenant
 * model has quietly broken.
 */
class AdminIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    private User $ownerA;

    private User $staffA;

    private User $ownerB;

    private User $superAdmin;

    private Category $categoryB;

    private Product $productB;

    private Order $orderB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A', 'slug' => 'restaurant-a']);
        $this->restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B', 'slug' => 'restaurant-b']);

        $this->ownerA = $this->makeStaff($this->restaurantA, StaffRole::Owner, 'owner-a@test.local');
        $this->staffA = $this->makeStaff($this->restaurantA, StaffRole::Staff, 'staff-a@test.local');
        $this->ownerB = $this->makeStaff($this->restaurantB, StaffRole::Owner, 'owner-b@test.local');

        $this->superAdmin = User::factory()->superAdmin()->create(['email' => 'platform@test.local']);

        // Restaurant B data that Restaurant A must never be able to touch.
        $this->categoryB = $this->restaurantB->categories()->create(['name' => 'B Menu', 'slug' => 'b-menu']);
        $this->productB = $this->restaurantB->products()->create([
            'category_id' => $this->categoryB->id,
            'name' => 'B Dish', 'slug' => 'b-dish', 'base_price' => 19900,
        ]);
        $this->orderB = $this->makeOrder($this->restaurantB);
    }

    private function makeStaff(Restaurant $restaurant, StaffRole $role, string $email): User
    {
        $user = User::factory()->staff()->create(['email' => $email]);

        $restaurant->staff()->attach($user->getKey(), ['role' => $role->value, 'status' => 'active']);

        return $user;
    }

    private function makeOrder(Restaurant $restaurant): Order
    {
        $customer = User::factory()->create();

        $order = new Order;
        $order->forceFill([
            'order_number' => 'ORD-20260908-'.strtoupper(substr(md5((string) $restaurant->id), 0, 6)),
            'user_id' => $customer->getKey(),
            'restaurant_id' => $restaurant->getKey(),
            'delivery_address' => ['address_line1' => 'Somewhere', 'city' => 'Delhi'],
            'customer_phone' => '+919999000099',
            'subtotal' => 19900, 'grand_total' => 19900,
            'placed_at' => now(),
        ])->save();

        return $order;
    }

    /** Act as a staff member, naming the restaurant they are working on. */
    private function actingAsStaff(User $user, Restaurant $restaurant): static
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($user, $user->isSuperAdmin() ? ['admin'] : ['restaurant']);

        return $this->withHeader('X-Restaurant-Id', (string) $restaurant->getKey());
    }

    // ── The tenant header is a hint, never an authorisation ─────────────────

    #[Test]
    public function claiming_another_restaurant_in_the_header_is_forbidden(): void
    {
        // The whole cross-tenant attack in one request: a real staff token, a
        // real restaurant id, no membership between them.
        $this->actingAsStaff($this->ownerA, $this->restaurantB)
            ->getJson('/api/v1/admin/restaurant')
            ->assertStatus(403)
            ->assertJsonPath('code', 'RESTAURANT_NOT_ACCESSIBLE');
    }

    #[Test]
    public function a_nonexistent_restaurant_id_in_the_header_is_forbidden_not_found(): void
    {
        // 403 rather than 404, so the header cannot be used to enumerate which
        // restaurant ids exist.
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->withHeader('X-Restaurant-Id', '999999')
            ->getJson('/api/v1/admin/restaurant')
            ->assertStatus(403);
    }

    #[Test]
    public function a_garbage_restaurant_header_is_rejected(): void
    {
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->withHeader('X-Restaurant-Id', 'not-a-number')
            ->getJson('/api/v1/admin/restaurant')
            ->assertStatus(403);
    }

    #[Test]
    public function staff_see_only_their_own_restaurant_profile(): void
    {
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/restaurant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Restaurant A');
    }

    // ── Categories ──────────────────────────────────────────────────────────

    #[Test]
    public function categories_of_another_restaurant_are_invisible(): void
    {
        $response = $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/categories')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    #[Test]
    public function every_verb_on_another_restaurants_category_is_refused(): void
    {
        $id = $this->categoryB->id;

        foreach ([
            ['getJson', "/api/v1/admin/categories/{$id}", []],
            ['patchJson', "/api/v1/admin/categories/{$id}", ['name' => 'Hijacked']],
            ['deleteJson', "/api/v1/admin/categories/{$id}", []],
        ] as [$method, $url, $payload]) {
            $this->actingAsStaff($this->ownerA, $this->restaurantA)
                ->{$method}($url, $payload)
                ->assertNotFound();
        }

        $this->assertDatabaseHas('categories', ['id' => $id, 'name' => 'B Menu', 'deleted_at' => null]);
    }

    #[Test]
    public function reordering_cannot_reach_another_restaurants_category(): void
    {
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->postJson('/api/v1/admin/categories/reorder', [
                'order' => [['id' => $this->categoryB->id, 'sort_order' => 99]],
            ])
            ->assertOk();

        // The request succeeds but the scoped update matches nothing.
        $this->assertDatabaseHas('categories', ['id' => $this->categoryB->id, 'sort_order' => 0]);
    }

    // ── Products ────────────────────────────────────────────────────────────

    #[Test]
    public function every_verb_on_another_restaurants_product_is_refused(): void
    {
        $id = $this->productB->id;

        foreach ([
            ['getJson', "/api/v1/admin/products/{$id}", []],
            ['patchJson', "/api/v1/admin/products/{$id}", ['name' => 'Hijacked']],
            ['patchJson', "/api/v1/admin/products/{$id}/availability", ['is_available' => false]],
            ['deleteJson', "/api/v1/admin/products/{$id}", []],
        ] as [$method, $url, $payload]) {
            $this->actingAsStaff($this->ownerA, $this->restaurantA)
                ->{$method}($url, $payload)
                ->assertNotFound();
        }

        $this->assertDatabaseHas('products', [
            'id' => $id, 'name' => 'B Dish', 'is_available' => true, 'deleted_at' => null,
        ]);
    }

    #[Test]
    public function a_product_cannot_be_created_against_another_restaurants_category(): void
    {
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->postJson('/api/v1/admin/products', [
                'name' => 'Smuggled Dish',
                'category_id' => $this->categoryB->id,
                'base_price' => 10000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertDatabaseMissing('products', ['name' => 'Smuggled Dish']);
    }

    // ── Orders: the highest-value leak ──────────────────────────────────────

    #[Test]
    public function orders_of_another_restaurant_are_invisible(): void
    {
        $response = $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/orders')
            ->assertOk();

        // An order carries the customer name, phone and delivery address.
        $this->assertSame([], $response->json('data'));
    }

    #[Test]
    public function another_restaurants_order_cannot_be_read_or_advanced(): void
    {
        $id = $this->orderB->id;

        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson("/api/v1/admin/orders/{$id}")
            ->assertNotFound();

        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->patchJson("/api/v1/admin/orders/{$id}/status", ['status' => 'confirmed'])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending']);
    }

    #[Test]
    public function dashboard_stats_count_only_the_callers_own_restaurant(): void
    {
        $this->makeOrder($this->restaurantA);

        $stats = $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/dashboard/stats')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $stats['orders_today']);
        $this->assertSame($this->restaurantA->id, $stats['restaurant_id']);
    }

    // ── Role hierarchy ──────────────────────────────────────────────────────

    #[Test]
    public function a_shift_worker_cannot_delete_menu_items(): void
    {
        $category = $this->restaurantA->categories()->create(['name' => 'A Menu', 'slug' => 'a-menu']);

        // Deleting from the menu is a manager decision.
        $this->actingAsStaff($this->staffA, $this->restaurantA)
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    #[Test]
    public function a_shift_worker_can_still_mark_an_item_sold_out(): void
    {
        $category = $this->restaurantA->categories()->create(['name' => 'A Menu', 'slug' => 'a-menu']);
        $product = $this->restaurantA->products()->create([
            'category_id' => $category->id,
            'name' => 'A Dish', 'slug' => 'a-dish', 'base_price' => 10000,
        ]);

        $this->actingAsStaff($this->staffA, $this->restaurantA)
            ->patchJson("/api/v1/admin/products/{$product->id}/availability", ['is_available' => false])
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_available' => false]);
    }

    #[Test]
    public function a_shift_worker_cannot_manage_staff(): void
    {
        $this->actingAsStaff($this->staffA, $this->restaurantA)
            ->getJson('/api/v1/admin/staff')
            ->assertStatus(403);
    }

    #[Test]
    public function staff_of_another_restaurant_cannot_be_listed(): void
    {
        $emails = $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/staff')
            ->assertOk()
            ->json('data.*.email');

        sort($emails);
        $this->assertSame(['owner-a@test.local', 'staff-a@test.local'], $emails);
    }

    // ── Audience gates ──────────────────────────────────────────────────────

    #[Test]
    public function a_customer_token_cannot_reach_admin_routes(): void
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->withHeader('X-Restaurant-Id', (string) $this->restaurantA->id)
            ->getJson('/api/v1/admin/restaurant')
            ->assertStatus(403);
    }

    #[Test]
    public function a_restaurant_token_cannot_reach_platform_routes(): void
    {
        // The token ability gate catches this before any policy runs.
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/super-admin/restaurants')
            ->assertStatus(403);

        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->postJson('/api/v1/super-admin/restaurants', ['name' => 'Mine Now'])
            ->assertStatus(403);
    }

    #[Test]
    public function staff_cannot_change_their_own_restaurant_status_or_commission(): void
    {
        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->patchJson('/api/v1/admin/restaurant', [
                'name' => 'Renamed',
                'status' => 'active',
                'commission_rate' => 0,
            ])
            ->assertOk();

        $this->restaurantA->refresh();

        // The name changed; the guarded platform columns did not.
        $this->assertSame('Renamed', $this->restaurantA->name);
        $this->assertSame(18.0, (float) $this->restaurantA->commission_rate);
    }

    // ── Super admin ─────────────────────────────────────────────────────────

    #[Test]
    public function a_super_admin_reaches_every_restaurant(): void
    {
        $names = $this->actingAsStaff($this->superAdmin, $this->restaurantA)
            ->getJson('/api/v1/super-admin/restaurants')
            ->assertOk()
            ->json('data.*.name');

        sort($names);
        $this->assertSame(['Restaurant A', 'Restaurant B'], $names);
    }

    #[Test]
    public function a_super_admin_can_act_on_any_restaurant_via_the_header(): void
    {
        // No membership row anywhere, yet Gate::before lets the platform through.
        $this->actingAsStaff($this->superAdmin, $this->restaurantB)
            ->getJson('/api/v1/admin/restaurant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Restaurant B');
    }

    #[Test]
    public function a_super_admin_can_suspend_a_restaurant(): void
    {
        $this->actingAsStaff($this->superAdmin, $this->restaurantA)
            ->patchJson("/api/v1/super-admin/restaurants/{$this->restaurantB->id}/status", [
                'status' => 'suspended',
            ])
            ->assertOk();

        $this->assertDatabaseHas('restaurants', ['id' => $this->restaurantB->id, 'status' => 'suspended']);
    }

    #[Test]
    public function a_suspended_restaurant_blocks_staff_writes_but_allows_reads(): void
    {
        $this->restaurantA->forceFill(['status' => 'suspended'])->save();

        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->getJson('/api/v1/admin/restaurant')
            ->assertOk();

        $this->actingAsStaff($this->ownerA, $this->restaurantA)
            ->patchJson('/api/v1/admin/restaurant', ['name' => 'Still Trading'])
            ->assertStatus(403);
    }
}
