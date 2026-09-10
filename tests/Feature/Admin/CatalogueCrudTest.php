<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Models\Addon;
use App\Models\AddonGroup;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The secondary catalogue: holidays, variants, add-on groups, add-ons, coupons
 * and product images.
 *
 * Each resource gets both halves of the same question — does it work, and is it
 * confined to one restaurant.
 */
class CatalogueCrudTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    private User $ownerA;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A', 'slug' => 'restaurant-a']);
        $this->restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B', 'slug' => 'restaurant-b']);

        $this->ownerA = User::factory()->staff()->create(['email' => 'owner-a@test.local']);
        $this->restaurantA->staff()->attach($this->ownerA->getKey(), [
            'role' => StaffRole::Owner->value, 'status' => 'active',
        ]);

        $this->productA = $this->makeProduct($this->restaurantA, 'A Dish', 'a-dish');
        $this->productB = $this->makeProduct($this->restaurantB, 'B Dish', 'b-dish');
    }

    private function makeProduct(Restaurant $restaurant, string $name, string $slug): Product
    {
        $category = $restaurant->categories()->create([
            'name' => $name.' Menu', 'slug' => $slug.'-menu',
        ]);

        return $restaurant->products()->create([
            'category_id' => $category->id,
            'name' => $name, 'slug' => $slug, 'base_price' => 19900,
        ]);
    }

    private function asOwnerA(): static
    {
        app('auth')->forgetGuards();
        Sanctum::actingAs($this->ownerA, ['restaurant']);

        return $this->withHeader('X-Restaurant-Id', (string) $this->restaurantA->getKey());
    }

    // ── Holidays ────────────────────────────────────────────────────────────

    #[Test]
    public function holidays_can_be_added_and_removed(): void
    {
        $date = now()->addDays(10)->toDateString();

        $id = $this->asOwnerA()
            ->postJson('/api/v1/admin/holidays', ['date' => $date, 'reason' => 'Renovation'])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'Renovation')
            ->json('data.id');

        $this->asOwnerA()->getJson('/api/v1/admin/holidays')->assertOk()->assertJsonCount(1, 'data');

        $this->asOwnerA()->deleteJson("/api/v1/admin/holidays/{$id}")->assertOk();
        $this->assertDatabaseMissing('restaurant_holidays', ['id' => $id]);
    }

    #[Test]
    public function a_duplicate_holiday_date_is_a_readable_error_not_a_crash(): void
    {
        $date = now()->addDays(5)->toDateString();

        $this->asOwnerA()->postJson('/api/v1/admin/holidays', ['date' => $date])->assertCreated();

        // The database has a unique constraint; validation turns it into 422.
        $this->asOwnerA()->postJson('/api/v1/admin/holidays', ['date' => $date])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');
    }

    #[Test]
    public function another_restaurants_holiday_cannot_be_touched(): void
    {
        $foreign = $this->restaurantB->holidays()->create([
            'date' => now()->addDays(7)->toDateString(), 'reason' => 'B closure',
        ]);

        $this->asOwnerA()->patchJson("/api/v1/admin/holidays/{$foreign->id}", ['reason' => 'Hijacked'])
            ->assertNotFound();
        $this->asOwnerA()->deleteJson("/api/v1/admin/holidays/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('restaurant_holidays', ['id' => $foreign->id, 'reason' => 'B closure']);
    }

    // ── Variants ────────────────────────────────────────────────────────────

    #[Test]
    public function variants_can_be_managed_and_only_one_is_default(): void
    {
        $half = $this->asOwnerA()->postJson("/api/v1/admin/products/{$this->productA->id}/variants", [
            'name' => 'Half', 'price' => 19900, 'is_default' => true,
        ])->assertCreated()->json('data.id');

        $full = $this->asOwnerA()->postJson("/api/v1/admin/products/{$this->productA->id}/variants", [
            'name' => 'Full', 'price' => 34900, 'is_default' => true,
        ])->assertCreated()->json('data.id');

        // Promoting the second demotes the first, so the client always has
        // exactly one to preselect.
        $this->assertDatabaseHas('product_variants', ['id' => $full, 'is_default' => true]);
        $this->assertDatabaseHas('product_variants', ['id' => $half, 'is_default' => false]);
    }

    #[Test]
    public function a_duplicate_variant_name_on_one_product_is_rejected(): void
    {
        $this->asOwnerA()->postJson("/api/v1/admin/products/{$this->productA->id}/variants", [
            'name' => 'Large', 'price' => 29900,
        ])->assertCreated();

        $this->asOwnerA()->postJson("/api/v1/admin/products/{$this->productA->id}/variants", [
            'name' => 'Large', 'price' => 31900,
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    #[Test]
    public function variants_cannot_be_added_to_another_restaurants_product(): void
    {
        $this->asOwnerA()->postJson("/api/v1/admin/products/{$this->productB->id}/variants", [
            'name' => 'Smuggled', 'price' => 10000,
        ])->assertNotFound();

        $this->assertDatabaseMissing('product_variants', ['name' => 'Smuggled']);
    }

    // ── Add-on groups and add-ons ───────────────────────────────────────────

    #[Test]
    public function addon_groups_and_addons_can_be_created_and_attached(): void
    {
        $group = $this->asOwnerA()->postJson('/api/v1/admin/addon-groups', [
            'name' => 'Spice level', 'min_select' => 1, 'max_select' => 1, 'is_required' => true,
        ])->assertCreated()->json('data.id');

        $this->asOwnerA()->postJson('/api/v1/admin/addons', [
            'addon_group_id' => $group, 'name' => 'Hot', 'price' => 0,
        ])->assertCreated();

        $this->asOwnerA()->putJson("/api/v1/admin/addon-groups/{$group}/products", [
            'product_ids' => [$this->productA->id],
        ])->assertOk()->assertJsonPath('data.product_count', 1);

        $this->assertDatabaseHas('addon_group_product', [
            'addon_group_id' => $group,
            'product_id' => $this->productA->id,
            'restaurant_id' => $this->restaurantA->id,
        ]);
    }

    #[Test]
    public function max_select_below_min_select_is_rejected(): void
    {
        $this->asOwnerA()->postJson('/api/v1/admin/addon-groups', [
            'name' => 'Impossible', 'min_select' => 3, 'max_select' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('max_select');
    }

    #[Test]
    public function a_group_cannot_be_attached_to_another_restaurants_product(): void
    {
        $group = $this->asOwnerA()->postJson('/api/v1/admin/addon-groups', ['name' => 'Extras'])
            ->assertCreated()->json('data.id');

        $this->asOwnerA()->putJson("/api/v1/admin/addon-groups/{$group}/products", [
            'product_ids' => [$this->productB->id],
        ])->assertStatus(422)->assertJsonValidationErrors('product_ids.0');

        $this->assertDatabaseCount('addon_group_product', 0);
    }

    #[Test]
    public function an_addon_cannot_be_created_against_another_restaurants_group(): void
    {
        $foreignGroup = $this->restaurantB->addonGroups()->create(['name' => 'B Extras']);

        $this->asOwnerA()->postJson('/api/v1/admin/addons', [
            'addon_group_id' => $foreignGroup->id, 'name' => 'Smuggled', 'price' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors('addon_group_id');
    }

    #[Test]
    public function another_restaurants_addon_group_is_invisible(): void
    {
        $this->restaurantB->addonGroups()->create(['name' => 'B Extras']);

        $this->assertSame(
            [],
            $this->asOwnerA()->getJson('/api/v1/admin/addon-groups')->assertOk()->json('data'),
        );
    }

    // ── Coupons ─────────────────────────────────────────────────────────────

    #[Test]
    public function coupons_can_be_managed(): void
    {
        $id = $this->asOwnerA()->postJson('/api/v1/admin/coupons', [
            'code' => 'SAVE50', 'type' => 'fixed', 'value' => 5000, 'min_order_amount' => 20000,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'SAVE50')
            ->assertJsonPath('data.is_live', true)
            ->json('data.id');

        $this->asOwnerA()->patchJson("/api/v1/admin/coupons/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_live', false);
    }

    #[Test]
    public function a_percentage_coupon_over_one_hundred_is_rejected(): void
    {
        $this->asOwnerA()->postJson('/api/v1/admin/coupons', [
            'code' => 'FREE', 'type' => 'percent', 'value' => 150,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_restaurant_cannot_see_or_edit_a_platform_wide_coupon(): void
    {
        // restaurant_id null means the platform owns it.
        $platform = Coupon::query()->create([
            'restaurant_id' => null, 'code' => 'PLATFORM10',
            'type' => 'percent', 'value' => 10, 'is_active' => true,
        ]);

        $this->assertSame(
            [],
            $this->asOwnerA()->getJson('/api/v1/admin/coupons')->assertOk()->json('data'),
        );

        $this->asOwnerA()->patchJson("/api/v1/admin/coupons/{$platform->id}", ['value' => 90])
            ->assertNotFound();

        $this->assertDatabaseHas('coupons', ['id' => $platform->id, 'value' => 10]);
    }

    #[Test]
    public function another_restaurants_coupon_cannot_be_read_or_edited(): void
    {
        $foreign = $this->restaurantB->coupons()->create([
            'code' => 'BONLY', 'type' => 'fixed', 'value' => 1000, 'is_active' => true,
        ]);

        $this->asOwnerA()->getJson("/api/v1/admin/coupons/{$foreign->id}")->assertNotFound();
        $this->asOwnerA()->deleteJson("/api/v1/admin/coupons/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('coupons', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_same_coupon_code_may_exist_at_two_restaurants(): void
    {
        $this->restaurantB->coupons()->create([
            'code' => 'WELCOME', 'type' => 'fixed', 'value' => 1000, 'is_active' => true,
        ]);

        // Codes are unique per restaurant, not globally.
        $this->asOwnerA()->postJson('/api/v1/admin/coupons', [
            'code' => 'WELCOME', 'type' => 'fixed', 'value' => 2000,
        ])->assertCreated();
    }

    // ── Product image ───────────────────────────────────────────────────────

    #[Test]
    public function a_product_image_can_be_uploaded(): void
    {
        Storage::fake('public');

        $response = $this->asOwnerA()->post(
            "/api/v1/admin/products/{$this->productA->id}/image",
            ['image' => UploadedFile::fake()->image('dish.jpg', 800, 600)],
            ['Accept' => 'application/json'],
        )->assertOk();

        $path = $response->json('data.image_path');

        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString('restaurants/'.$this->restaurantA->id, $path);
        $this->assertDatabaseHas('products', ['id' => $this->productA->id, 'image_path' => $path]);
    }

    #[Test]
    public function a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        // A renamed script must not pass: the image rule inspects the contents.
        $this->asOwnerA()->post(
            "/api/v1/admin/products/{$this->productA->id}/image",
            ['image' => UploadedFile::fake()->create('payload.php', 40, 'image/jpeg')],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors('image');
    }

    #[Test]
    public function an_image_cannot_be_uploaded_to_another_restaurants_product(): void
    {
        Storage::fake('public');

        $this->asOwnerA()->post(
            "/api/v1/admin/products/{$this->productB->id}/image",
            ['image' => UploadedFile::fake()->image('dish.jpg', 800, 600)],
            ['Accept' => 'application/json'],
        )->assertNotFound();
    }
}
