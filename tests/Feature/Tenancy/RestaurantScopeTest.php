<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\Api\TenantContextMissingException;
use App\Models\Category;
use App\Models\Restaurant;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Layer 3 of the isolation strategy: the fail-closed global scope.
 *
 * The point of these tests is the first one. A scope that silently returns every
 * tenant's rows when context is missing is worse than no scope at all, because
 * it looks like protection.
 *
 * Categories are used as the subject because they are genuine tenant-owned
 * business data. RestaurantHour deliberately opts out of the scope, for reasons
 * documented on that model.
 */
class RestaurantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurantA;

    private Restaurant $restaurantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A']);
        $this->restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B']);

        // Created through the relation, so restaurant_id comes from the parent
        // rather than from ambient context.
        $this->restaurantA->categories()->create(['name' => 'A Starters', 'slug' => 'a-starters']);
        $this->restaurantB->categories()->create(['name' => 'B Starters', 'slug' => 'b-starters']);
    }

    #[Test]
    public function querying_a_tenant_model_without_context_throws_rather_than_leaking(): void
    {
        $this->expectException(TenantContextMissingException::class);

        Category::query()->get();
    }

    #[Test]
    public function counting_without_context_also_throws(): void
    {
        // Aggregates must fail closed too, or a dashboard could disclose the
        // size of another tenant's catalogue.
        $this->expectException(TenantContextMissingException::class);

        Category::query()->count();
    }

    #[Test]
    public function a_query_is_confined_to_the_restaurant_in_context(): void
    {
        app(RestaurantContext::class)->set($this->restaurantA);

        $categories = Category::query()->get();

        $this->assertCount(1, $categories);
        $this->assertSame('A Starters', $categories->first()->name);
    }

    #[Test]
    public function switching_context_switches_the_visible_rows(): void
    {
        $context = app(RestaurantContext::class);

        $context->set($this->restaurantA);
        $this->assertSame('A Starters', Category::query()->sole()->name);

        $context->set($this->restaurantB);
        $this->assertSame('B Starters', Category::query()->sole()->name);
    }

    #[Test]
    public function another_restaurants_row_is_invisible_even_when_addressed_by_id(): void
    {
        $foreignId = Category::withoutTenantScope()
            ->where('restaurant_id', $this->restaurantB->getKey())
            ->sole()
            ->getKey();

        app(RestaurantContext::class)->set($this->restaurantA);

        // The IDOR case: a real id from the wrong tenant must simply not exist.
        $this->assertNull(Category::query()->find($foreignId));
    }

    #[Test]
    public function another_restaurants_row_cannot_be_updated_through_the_scope(): void
    {
        $foreignId = Category::withoutTenantScope()
            ->where('restaurant_id', $this->restaurantB->getKey())
            ->sole()
            ->getKey();

        app(RestaurantContext::class)->set($this->restaurantA);

        $affected = Category::query()->where('id', $foreignId)->update(['name' => 'Hijacked']);

        $this->assertSame(0, $affected);
        $this->assertDatabaseHas('categories', ['id' => $foreignId, 'name' => 'B Starters']);
    }

    #[Test]
    public function another_restaurants_row_cannot_be_deleted_through_the_scope(): void
    {
        $foreignId = Category::withoutTenantScope()
            ->where('restaurant_id', $this->restaurantB->getKey())
            ->sole()
            ->getKey();

        app(RestaurantContext::class)->set($this->restaurantA);

        Category::query()->where('id', $foreignId)->delete();

        $this->assertNull(
            Category::withoutTenantScope()->find($foreignId)?->deleted_at
        );
    }

    #[Test]
    public function the_escape_hatch_reads_across_tenants(): void
    {
        app(RestaurantContext::class)->set($this->restaurantA);

        $this->assertCount(2, Category::withoutTenantScope()->get());
    }

    #[Test]
    public function run_cross_tenant_suspends_and_then_restores_scoping(): void
    {
        $context = app(RestaurantContext::class);
        $context->set($this->restaurantA);

        $this->assertCount(2, $context->runCrossTenant(fn () => Category::query()->get()));

        // Scoping must come back afterwards, or one cross-tenant read would
        // quietly disable isolation for the rest of the request.
        $this->assertCount(1, Category::query()->get());
    }

    #[Test]
    public function cross_tenant_scoping_is_restored_even_when_the_callback_throws(): void
    {
        $context = app(RestaurantContext::class);
        $context->set($this->restaurantA);

        try {
            $context->runCrossTenant(function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($context->isCrossTenant());
        $this->assertCount(1, Category::query()->get());
    }

    #[Test]
    public function creating_a_record_stamps_the_restaurant_from_context(): void
    {
        app(RestaurantContext::class)->set($this->restaurantB);

        $category = Category::query()->create(['name' => 'Desserts', 'slug' => 'desserts']);

        $this->assertSame($this->restaurantB->getKey(), $category->restaurant_id);
    }

    #[Test]
    public function for_restaurant_scope_targets_a_specific_tenant(): void
    {
        app(RestaurantContext::class)->set($this->restaurantA);

        $categories = Category::query()->forRestaurant($this->restaurantB)->get();

        $this->assertCount(1, $categories);
        $this->assertSame('B Starters', $categories->first()->name);
    }

    #[Test]
    public function opening_hours_are_readable_without_a_tenant_context(): void
    {
        /*
         * The deliberate exception to the rule. Hours are public reference data
         * printed on the listing page for restaurants the customer has not
         * chosen yet, so requiring a context here would force the escape hatch
         * onto every public read path.
         */
        $this->restaurantA->hours()->create([
            'day_of_week' => 1,
            'opens_at' => '09:00:00',
            'closes_at' => '22:00:00',
        ]);

        $hours = \App\Models\RestaurantHour::query()->forRestaurant($this->restaurantA)->get();

        $this->assertCount(1, $hours);
    }
}
