<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AddonGroup;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A real menu for each seeded restaurant, so the API is explorable and the
 * Next.js team has something to build against on day one.
 *
 * The two menus are deliberately different in shape: one has variants and a
 * required choice group, the other does not.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $spiceRoute = Restaurant::query()->where('slug', 'spice-route')->first();
        $noodleBar = Restaurant::query()->where('slug', 'noodle-bar')->first();

        if ($spiceRoute !== null) {
            $this->seedSpiceRoute($spiceRoute);
        }

        if ($noodleBar !== null) {
            $this->seedNoodleBar($noodleBar);
        }
    }

    private function seedSpiceRoute(Restaurant $restaurant): void
    {
        $starters = $this->category($restaurant, 'Starters', 1);
        $mains = $this->category($restaurant, 'Main Course', 2);
        $breads = $this->category($restaurant, 'Breads', 3);
        $desserts = $this->category($restaurant, 'Desserts', 4);

        $extras = $restaurant->addonGroups()->create([
            'name' => 'Extras',
            'min_select' => 0,
            'max_select' => 3,
            'is_required' => false,
            'sort_order' => 1,
        ]);

        foreach ([['Extra gravy', 4000], ['Extra cheese', 5000], ['Green chutney', 2000]] as [$name, $price]) {
            $extras->addons()->create([
                'restaurant_id' => $restaurant->getKey(),
                'name' => $name,
                'price' => $price,
            ]);
        }

        // A required single choice, so the cart add-on rules get exercised.
        $spice = $restaurant->addonGroups()->create([
            'name' => 'Spice level',
            'min_select' => 1,
            'max_select' => 1,
            'is_required' => true,
            'sort_order' => 2,
        ]);

        foreach ([['Mild', 0], ['Medium', 0], ['Hot', 0]] as [$name, $price]) {
            $spice->addons()->create([
                'restaurant_id' => $restaurant->getKey(),
                'name' => $name,
                'price' => $price,
            ]);
        }

        $paneer = $this->product($restaurant, $starters, 'Paneer Tikka', 24900, true, [
            'description' => 'Charred cottage cheese, yoghurt and ajwain marinade.',
            'spice_level' => 2,
        ]);
        $this->attachGroups($paneer, [$extras, $spice]);

        $butterChicken = $this->product($restaurant, $mains, 'Butter Chicken', 38900, false, [
            'description' => 'Tandoori chicken in a slow-cooked tomato and cream gravy.',
            'spice_level' => 1,
        ]);
        $this->attachGroups($butterChicken, [$extras, $spice]);

        // Portion sizes, priced absolutely rather than as deltas.
        $dal = $this->product($restaurant, $mains, 'Dal Makhani', 27900, true, [
            'description' => 'Black lentils simmered overnight.',
        ]);
        $dal->variants()->create([
            'restaurant_id' => $restaurant->getKey(),
            'name' => 'Half', 'price' => 27900, 'is_default' => true, 'sort_order' => 1,
        ]);
        $dal->variants()->create([
            'restaurant_id' => $restaurant->getKey(),
            'name' => 'Full', 'price' => 44900, 'sort_order' => 2,
        ]);

        $this->product($restaurant, $breads, 'Garlic Naan', 8900, true);
        $this->product($restaurant, $breads, 'Laccha Paratha', 7900, true);
        $this->product($restaurant, $desserts, 'Gulab Jamun', 12900, true);

        // A sold-out item, so the availability path has data behind it.
        $this->product($restaurant, $starters, 'Tandoori Mushroom', 26900, true, [
            'is_available' => false,
        ]);

        $restaurant->coupons()->create([
            'code' => 'WELCOME50',
            'description' => '50 off your first order over 299',
            'type' => 'fixed',
            'value' => 5000,
            'min_order_amount' => 29900,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
    }

    private function seedNoodleBar(Restaurant $restaurant): void
    {
        $bowls = $this->category($restaurant, 'Ramen Bowls', 1);
        $dumplings = $this->category($restaurant, 'Dumplings', 2);
        $drinks = $this->category($restaurant, 'Drinks', 3);

        $toppings = $restaurant->addonGroups()->create([
            'name' => 'Toppings',
            'min_select' => 0,
            'max_select' => 4,
            'is_required' => false,
        ]);

        foreach ([['Soft-boiled egg', 6000], ['Extra noodles', 8000], ['Nori', 3000], ['Bamboo shoots', 4000]] as [$name, $price]) {
            $toppings->addons()->create([
                'restaurant_id' => $restaurant->getKey(),
                'name' => $name,
                'price' => $price,
            ]);
        }

        $shoyu = $this->product($restaurant, $bowls, 'Shoyu Ramen', 34900, false, [
            'description' => 'Soy-based broth, chashu pork, spring onion.',
        ]);
        $this->attachGroups($shoyu, [$toppings]);

        $miso = $this->product($restaurant, $bowls, 'Miso Ramen', 36900, true, [
            'description' => 'Fermented soybean broth with sweetcorn and butter.',
        ]);
        $this->attachGroups($miso, [$toppings]);

        $this->product($restaurant, $dumplings, 'Chicken Gyoza', 24900, false);
        $this->product($restaurant, $dumplings, 'Veg Momos', 19900, true);
        $this->product($restaurant, $drinks, 'Iced Green Tea', 9900, true);

        // Platform-wide coupons carry a null restaurant_id, which is why the
        // Coupon model is not tenant-scoped.
        \App\Models\Coupon::query()->create([
            'restaurant_id' => null,
            'code' => 'PLATFORM10',
            'description' => '10 percent off, any restaurant, up to 100',
            'type' => 'percent',
            'value' => 10,
            'max_discount' => 10000,
            'min_order_amount' => 19900,
            'is_active' => true,
        ]);
    }

    private function category(Restaurant $restaurant, string $name, int $order): \App\Models\Category
    {
        return $restaurant->categories()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => $order,
            'is_active' => true,
        ]);
    }

    private function product(
        Restaurant $restaurant,
        \App\Models\Category $category,
        string $name,
        int $priceMinorUnits,
        bool $isVeg,
        array $extra = [],
    ): \App\Models\Product {
        return $restaurant->products()->create([
            'category_id' => $category->getKey(),
            'name' => $name,
            'slug' => Str::slug($name),
            'base_price' => $priceMinorUnits,
            'is_veg' => $isVeg,
            'prep_time_minutes' => 15,
            ...$extra,
        ]);
    }

    /** @param  array<int, AddonGroup>  $groups */
    private function attachGroups(\App\Models\Product $product, array $groups): void
    {
        foreach ($groups as $index => $group) {
            $product->addonGroups()->attach($group->getKey(), [
                'restaurant_id' => $product->restaurant_id,
                'sort_order' => $index,
            ]);
        }
    }
}
