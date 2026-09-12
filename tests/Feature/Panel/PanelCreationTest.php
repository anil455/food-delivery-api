<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Enums\RestaurantStatus;
use App\Enums\StaffRole;
use App\Enums\UserType;
use App\Filament\Platform\Resources\Restaurants\Pages\CreateRestaurant;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Creating things through the panels, driving the real Filament forms.
 *
 * This is the walkthrough a human follows, executed as a test: create a
 * restaurant and its owner, then a category, then a product.
 */
class PanelCreationTest extends TestCase
{
    use RefreshDatabase;

    // ── Platform panel: a new restaurant ────────────────────────────────────

    #[Test]
    public function the_platform_panel_creates_a_restaurant_and_its_first_owner(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        // The platform panel has its own guard (see PlatformPanelProvider).
        $this->actingAs($superAdmin, 'platform');
        Filament::setCurrentPanel('platform');

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'name' => 'Biryani House',
                'phone' => '+919810033003',
                'email' => 'hello@biryanihouse.test',
                'timezone' => 'Asia/Kolkata',
                'address_line' => 'Shop 7, Lajpat Nagar',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'postal_code' => '110024',
                'latitude' => 28.5677,
                'longitude' => 77.2433,
                'delivery_radius_km' => 8,
                'status' => RestaurantStatus::PendingApproval->value,
                'commission_rate' => 18,
                'owner_name' => 'Farhan Ali',
                'owner_email' => 'owner@biryanihouse.test',
                'owner_password' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $restaurant = Restaurant::query()->where('name', 'Biryani House')->sole();

        // Slug is derived, not asked for.
        $this->assertSame('biryani-house', $restaurant->slug);

        // A new restaurant is invisible to customers until someone approves it.
        $this->assertSame(RestaurantStatus::PendingApproval, $restaurant->status);

        $owner = User::query()->where('email', 'owner@biryanihouse.test')->sole();

        $this->assertSame(UserType::Staff, $owner->type);
        $this->assertFalse($owner->is_super_admin);

        // The restaurant and its owner arrive together: one without the other
        // is an unmanageable restaurant or an orphaned login.
        $this->assertSame(StaffRole::Owner, $owner->roleInRestaurant($restaurant));
    }

    #[Test]
    public function the_new_owner_can_immediately_sign_in_to_the_admin_panel(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        // The platform panel has its own guard (see PlatformPanelProvider).
        $this->actingAs($superAdmin, 'platform');
        Filament::setCurrentPanel('platform');

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'name' => 'Biryani House', 'phone' => '+919810033003', 'timezone' => 'Asia/Kolkata',
                'address_line' => 'Shop 7', 'city' => 'New Delhi', 'state' => 'Delhi', 'postal_code' => '110024',
                'latitude' => 28.5677, 'longitude' => 77.2433, 'delivery_radius_km' => 8,
                'status' => RestaurantStatus::Active->value, 'commission_rate' => 18,
                'owner_name' => 'Farhan Ali', 'owner_email' => 'owner@biryanihouse.test',
                'owner_password' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $owner = User::query()->where('email', 'owner@biryanihouse.test')->sole();
        $restaurant = Restaurant::query()->where('slug', 'biryani-house')->sole();

        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($owner->canAccessTenant($restaurant));

        // actingAs()'s guard argument also becomes the test's default guard
        // (Auth::shouldUse()) for any later call that omits one — since the
        // platform sign-in above passed 'platform' explicitly, this one must
        // name 'web' explicitly too, or it would inherit 'platform' instead.
        $this->actingAs($owner, 'web')
            ->get('/admin/biryani-house/products')
            ->assertSuccessful();
    }

    // ── Admin panel: category, then product ─────────────────────────────────

    #[Test]
    public function the_admin_panel_creates_a_category_then_a_product_inside_it(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Biryani House', 'slug' => 'biryani-house']);
        $owner = User::factory()->staff()->create();
        $restaurant->staff()->attach($owner->getKey(), ['role' => StaffRole::Owner->value, 'status' => 'active']);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($restaurant);

        /*
         * Livewire component tests bypass the HTTP middleware stack, so the
         * bridge that normally hands Filament's tenant to RestaurantContext
         * never runs. Doing it by hand here is exactly what
         * SetTenantContextFromFilament does on a real request; that middleware
         * has its own coverage in AdminPanelTest.
         */
        app(RestaurantContext::class)->set($restaurant);

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'name' => 'Biryani',
                'slug' => 'biryani',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::query()->where('slug', 'biryani')->sole();

        // restaurant_id was never in the form: the tenant stamped it.
        $this->assertSame($restaurant->getKey(), $category->restaurant_id);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Hyderabadi Chicken Biryani',
                'slug' => 'hyderabadi-chicken-biryani',
                'category_id' => $category->getKey(),
                // The form takes rupees; the model stores paise.
                'base_price' => '349.00',
                'is_veg' => false,
                'is_available' => true,
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('slug', 'hyderabadi-chicken-biryani')->sole();

        $this->assertSame($restaurant->getKey(), $product->restaurant_id);
        $this->assertSame($category->getKey(), $product->category_id);

        // 349.00 entered, 34900 paise stored. No float anywhere in between.
        $this->assertSame(34900, $product->base_price->minor);
        $this->assertSame('349.00', $product->base_price->toMajorString());
    }

    #[Test]
    public function the_product_appears_in_the_public_api_once_the_store_is_active(): void
    {
        $restaurant = Restaurant::factory()->at(28.5677, 77.2433)->create([
            'name' => 'Biryani House', 'slug' => 'biryani-house',
        ]);
        $owner = User::factory()->staff()->create();
        $restaurant->staff()->attach($owner->getKey(), ['role' => StaffRole::Owner->value, 'status' => 'active']);

        $this->actingAs($owner);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($restaurant);

        /*
         * Livewire component tests bypass the HTTP middleware stack, so the
         * bridge that normally hands Filament's tenant to RestaurantContext
         * never runs. Doing it by hand here is exactly what
         * SetTenantContextFromFilament does on a real request; that middleware
         * has its own coverage in AdminPanelTest.
         */
        app(RestaurantContext::class)->set($restaurant);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Biryani', 'slug' => 'biryani', 'sort_order' => 1, 'is_active' => true])
            ->call('create');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Hyderabadi Chicken Biryani',
                'slug' => 'hyderabadi-chicken-biryani',
                'category_id' => Category::query()->sole()->getKey(),
                'base_price' => '349.00',
                'is_veg' => false, 'is_available' => true, 'is_active' => true, 'sort_order' => 0,
            ])
            ->call('create');

        // Whatever the panel writes, the API serves. One database, one set of rules.
        $this->getJson('/api/v1/restaurants/biryani-house/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Hyderabadi Chicken Biryani')
            ->assertJsonPath('data.0.base_price.amount', '349.00')
            ->assertJsonPath('data.0.base_price.minor', 34900);
    }
}
