<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\StaffRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Filament panels.
 *
 * The panels and the API share one isolation mechanism: the tenant context and
 * the fail-closed global scope. These tests exist to prove the panel actually
 * goes through it rather than quietly bypassing it with its own scoping.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $spiceRoute;

    private Restaurant $noodleBar;

    private User $ownerA;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spiceRoute = Restaurant::factory()->create(['name' => 'Spice Route', 'slug' => 'spice-route']);
        $this->noodleBar = Restaurant::factory()->create(['name' => 'Noodle Bar', 'slug' => 'noodle-bar']);

        $this->ownerA = User::factory()->staff()->create(['email' => 'owner-a@test.local']);
        $this->spiceRoute->staff()->attach($this->ownerA->getKey(), [
            'role' => StaffRole::Owner->value, 'status' => 'active',
        ]);

        $this->superAdmin = User::factory()->superAdmin()->create(['email' => 'platform@test.local']);
    }

    // ── Who may open which panel ────────────────────────────────────────────

    #[Test]
    public function a_customer_cannot_open_the_admin_panel(): void
    {
        // Customers have no password and the panel is not theirs.
        $this->assertFalse(
            User::factory()->create()->canAccessPanel(Filament::getPanel('admin'))
        );
    }

    #[Test]
    public function a_blocked_staff_account_loses_panel_access_immediately(): void
    {
        $blocked = User::factory()->staff()->blocked()->create();

        $this->assertFalse($blocked->canAccessPanel(Filament::getPanel('admin')));
    }

    #[Test]
    public function restaurant_staff_cannot_open_the_platform_panel(): void
    {
        // Creating and approving restaurants is a platform decision.
        $this->assertTrue($this->ownerA->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($this->ownerA->canAccessPanel(Filament::getPanel('platform')));
    }

    #[Test]
    public function a_super_admin_can_open_both_panels(): void
    {
        $this->assertTrue($this->superAdmin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($this->superAdmin->canAccessPanel(Filament::getPanel('platform')));
    }

    #[Test]
    public function the_platform_panel_returns_403_to_restaurant_staff_over_http(): void
    {
        // The platform panel has its own guard (see PlatformPanelProvider),
        // so a restaurant owner attempting to sign in there is checked
        // against it directly — credentials succeed, canAccessPanel() does
        // not, same as a real login attempt through /platform/login would.
        $this->actingAs($this->ownerA, 'platform')
            ->get('/platform')
            ->assertForbidden();
    }

    // ── Tenant switcher ─────────────────────────────────────────────────────

    #[Test]
    public function staff_see_only_the_restaurants_they_work_at(): void
    {
        $tenants = $this->ownerA->getTenants(Filament::getPanel('admin'));

        $this->assertSame(['Spice Route'], $tenants->pluck('name')->all());
    }

    #[Test]
    public function a_super_admin_sees_every_restaurant_in_the_switcher(): void
    {
        $names = $this->superAdmin->getTenants(Filament::getPanel('admin'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Noodle Bar', 'Spice Route'], $names);
    }

    #[Test]
    public function staff_cannot_enter_another_restaurants_tenant(): void
    {
        // The whole cross-tenant attack, at the panel level.
        $this->assertTrue($this->ownerA->canAccessTenant($this->spiceRoute));
        $this->assertFalse($this->ownerA->canAccessTenant($this->noodleBar));
    }

    #[Test]
    public function entering_another_restaurants_url_is_refused(): void
    {
        /*
         * 404, not 403. Filament treats a tenant the caller cannot access as
         * one that does not exist, which discloses less than "this exists but
         * is not yours". Either way the request never reaches the resource.
         */
        $this->actingAs($this->ownerA)
            ->get('/admin/noodle-bar/products')
            ->assertNotFound();
    }

    #[Test]
    public function staff_can_open_their_own_restaurants_pages(): void
    {
        $this->actingAs($this->ownerA)
            ->get('/admin/spice-route/products')
            ->assertSuccessful();
    }

    // ── The panel goes through the same tenant scope as the API ─────────────

    #[Test]
    public function the_panel_middleware_hands_the_tenant_to_the_api_context(): void
    {
        $categoryA = $this->spiceRoute->categories()->create(['name' => 'A Menu', 'slug' => 'a-menu']);
        $this->spiceRoute->products()->create([
            'category_id' => $categoryA->id,
            'name' => 'A Dish', 'slug' => 'a-dish', 'base_price' => 19900,
        ]);

        $categoryB = $this->noodleBar->categories()->create(['name' => 'B Menu', 'slug' => 'b-menu']);
        $this->noodleBar->products()->create([
            'category_id' => $categoryB->id,
            'name' => 'B Dish', 'slug' => 'b-dish', 'base_price' => 29900,
        ]);

        $response = $this->actingAs($this->ownerA)->get('/admin/spice-route/products');

        $response->assertSuccessful();

        // If the bridge middleware were missing, the fail-closed scope would
        // have thrown rather than rendering. If it were wrong, B Dish would
        // be on the page.
        $response->assertSee('A Dish');
        $response->assertDontSee('B Dish');
    }

    #[Test]
    public function the_create_pages_render_for_staff(): void
    {
        // The forms build their dropdowns from tenant-scoped queries, so they
        // are the pages most likely to break if the tenant bridge regresses.
        $this->spiceRoute->categories()->create(['name' => 'Starters', 'slug' => 'starters']);

        $this->actingAs($this->ownerA)
            ->get('/admin/spice-route/categories/create')
            ->assertSuccessful();

        $this->actingAs($this->ownerA)
            ->get('/admin/spice-route/products/create')
            ->assertSuccessful()
            // The category dropdown is populated from this restaurant only.
            ->assertSee('Starters');
    }

    #[Test]
    public function the_product_form_never_offers_another_restaurants_category(): void
    {
        $this->spiceRoute->categories()->create(['name' => 'Mine', 'slug' => 'mine']);
        $this->noodleBar->categories()->create(['name' => 'Theirs', 'slug' => 'theirs']);

        $this->actingAs($this->ownerA)
            ->get('/admin/spice-route/products/create')
            ->assertSuccessful()
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    #[Test]
    public function the_panel_would_fail_closed_without_a_tenant(): void
    {
        Category::query()->withoutGlobalScopes()->delete();

        // Proving the underlying guarantee: outside a resolved tenant, a
        // tenant-owned query throws rather than returning everything.
        $this->expectException(\App\Exceptions\Api\TenantContextMissingException::class);

        Product::query()->count();
    }
}
