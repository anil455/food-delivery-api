<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layer 1 of the isolation strategy: composite foreign keys.
 *
 * Every insert here goes through the query builder rather than Eloquent, on
 * purpose. Models, scopes, policies and form requests are all bypassed, so a
 * pass proves the database itself is refusing the write — the guarantee that
 * survives any application bug.
 */
class TenantIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    private int $categoryA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A']);
        $this->restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B']);

        $this->categoryA = DB::table('categories')->insertGetId([
            'restaurant_id' => $this->restaurantA->getKey(),
            'name' => 'Starters',
            'slug' => 'starters',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_product_cannot_reference_a_category_from_another_restaurant(): void
    {
        $this->expectException(QueryException::class);

        DB::table('products')->insert([
            'restaurant_id' => $this->restaurantB->getKey(),   // B
            'category_id' => $this->categoryA,                 // …but A's category
            'name' => 'Stolen Samosa',
            'slug' => 'stolen-samosa',
            'base_price' => 9900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_product_in_its_own_restaurant_inserts_normally(): void
    {
        DB::table('products')->insert([
            'restaurant_id' => $this->restaurantA->getKey(),
            'category_id' => $this->categoryA,
            'name' => 'Paneer Tikka',
            'slug' => 'paneer-tikka',
            'base_price' => 24900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('products', [
            'slug' => 'paneer-tikka',
            'restaurant_id' => $this->restaurantA->getKey(),
        ]);
    }

    #[Test]
    public function a_variant_cannot_reference_a_product_from_another_restaurant(): void
    {
        $productA = DB::table('products')->insertGetId([
            'restaurant_id' => $this->restaurantA->getKey(),
            'category_id' => $this->categoryA,
            'name' => 'Paneer Tikka',
            'slug' => 'paneer-tikka',
            'base_price' => 24900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('product_variants')->insert([
            'restaurant_id' => $this->restaurantB->getKey(),
            'product_id' => $productA,
            'name' => 'Large',
            'price' => 29900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_addon_cannot_reference_a_group_from_another_restaurant(): void
    {
        $groupA = DB::table('addon_groups')->insertGetId([
            'restaurant_id' => $this->restaurantA->getKey(),
            'name' => 'Choice of drink',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('addons')->insert([
            'restaurant_id' => $this->restaurantB->getKey(),
            'addon_group_id' => $groupA,
            'name' => 'Masala Chai',
            'price' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_cart_item_cannot_reference_a_product_from_another_restaurant(): void
    {
        $productA = DB::table('products')->insertGetId([
            'restaurant_id' => $this->restaurantA->getKey(),
            'category_id' => $this->categoryA,
            'name' => 'Paneer Tikka',
            'slug' => 'paneer-tikka',
            'base_price' => 24900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create();

        $cartId = DB::table('carts')->insertGetId([
            'user_id' => $user->getKey(),
            'restaurant_id' => $this->restaurantB->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // This is the exact shape of the mixed-cart bug the schema must block.
        $this->expectException(QueryException::class);

        DB::table('cart_items')->insert([
            'cart_id' => $cartId,
            'restaurant_id' => $this->restaurantB->getKey(),
            'product_id' => $productA,
            'quantity' => 1,
            'price_at_add' => 24900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function a_user_can_hold_only_one_cart(): void
    {
        $user = \App\Models\User::factory()->create();

        DB::table('carts')->insert([
            'user_id' => $user->getKey(),
            'restaurant_id' => $this->restaurantA->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One cart per user is what makes a mixed-restaurant cart structurally
        // impossible rather than merely discouraged. See deviation D4.
        $this->expectException(QueryException::class);

        DB::table('carts')->insert([
            'user_id' => $user->getKey(),
            'restaurant_id' => $this->restaurantB->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
