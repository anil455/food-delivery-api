<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\Tenancy\RestaurantContext;
use Illuminate\Database\Seeder;

/**
 * Development seed data.
 *
 * Two restaurants, always: almost every interesting bug in a multi-tenant system
 * only becomes visible when a second tenant exists, so the baseline data set
 * makes cross-tenant assertions possible from day one.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding legitimately spans tenants. Going through the sanctioned
        // escape hatch keeps the fail-closed scope honest everywhere else.
        app(RestaurantContext::class)->runCrossTenant(function (): void {
            $this->seedSuperAdmin();

            $spiceRoute = $this->seedSpiceRoute();
            $noodleBar = $this->seedNoodleBar();

            $this->seedCustomer($spiceRoute);

            $this->call(MenuSeeder::class);

            $this->command->newLine();
            $this->command->info('Seeded 2 restaurants, 1 super admin, 4 staff, 1 customer.');
            $this->command->line("  Restaurant A: {$spiceRoute->name} (id {$spiceRoute->getKey()})");
            $this->command->line("  Restaurant B: {$noodleBar->name} (id {$noodleBar->getKey()})");
            $this->command->line('  Staff password: password');
            $this->command->line('  Customer phone: +919999000011');
        });
    }

    private function seedSuperAdmin(): void
    {
        User::factory()->superAdmin()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@fooddelivery.test',
        ]);
    }

    /**
     * Connaught Place, central Delhi. Open every day, single continuous shift.
     */
    private function seedSpiceRoute(): Restaurant
    {
        $restaurant = Restaurant::factory()
            ->at(28.6315, 77.2167)
            ->create([
                'name' => 'Spice Route',
                'slug' => 'spice-route',
                'description' => 'North Indian classics, tandoor and biryani.',
                'phone' => '+919810011001',
                'email' => 'hello@spiceroute.test',
                'address_line' => 'N-12, Connaught Place',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'postal_code' => '110001',
                'delivery_radius_km' => 7.00,
                'min_order_amount' => 19900,     // 199.00
                'delivery_fee_base' => 2900,     //  29.00
                'delivery_fee_per_km' => 800,    //   8.00 per km
                'packaging_fee' => 1500,         //  15.00
                'tax_percentage' => 5.00,
                'avg_prep_time_minutes' => 25,
            ]);

        $this->attachStaff($restaurant, 'Ritu Malhotra', 'owner@spiceroute.test', 'owner');
        $this->attachStaff($restaurant, 'Arjun Sethi', 'staff@spiceroute.test', 'staff');

        // Open 11:00 to 23:00, seven days.
        foreach (range(0, 6) as $day) {
            $restaurant->hours()->create([
                'day_of_week' => $day,
                'opens_at' => '11:00:00',
                'closes_at' => '23:00:00',
                'is_closed' => false,
            ]);
        }

        return $restaurant;
    }

    /**
     * Noida Sector 18, roughly 13 km east of Spice Route. Split lunch and dinner
     * service, closed Tuesdays, and dinner runs past midnight — the three cases
     * OpeningHoursService has to get right in Phase 3.
     */
    private function seedNoodleBar(): Restaurant
    {
        $restaurant = Restaurant::factory()
            ->at(28.5700, 77.3210)
            ->create([
                'name' => 'Noodle Bar',
                'slug' => 'noodle-bar',
                'description' => 'Pan-Asian bowls, dumplings and late-night ramen.',
                'phone' => '+919810022002',
                'email' => 'hello@noodlebar.test',
                'address_line' => 'B-24, Sector 18',
                'city' => 'Noida',
                'state' => 'Uttar Pradesh',
                'postal_code' => '201301',
                'delivery_radius_km' => 5.00,
                'min_order_amount' => 24900,     // 249.00
                'delivery_fee_base' => 3500,     //  35.00
                'delivery_fee_per_km' => 1000,   //  10.00 per km
                'packaging_fee' => 2000,         //  20.00
                'tax_percentage' => 5.00,
                'avg_prep_time_minutes' => 30,
            ]);

        $this->attachStaff($restaurant, 'Kabir Menon', 'owner@noodlebar.test', 'owner');
        $this->attachStaff($restaurant, 'Sana Qureshi', 'manager@noodlebar.test', 'manager');

        foreach (range(0, 6) as $day) {
            if ($day === 2) {                     // Tuesday: closed
                $restaurant->hours()->create([
                    'day_of_week' => $day,
                    'opens_at' => null,
                    'closes_at' => null,
                    'is_closed' => true,
                ]);

                continue;
            }

            $restaurant->hours()->create([
                'day_of_week' => $day,
                'opens_at' => '11:00:00',
                'closes_at' => '15:00:00',
                'is_closed' => false,
            ]);

            // closes_at < opens_at, so this slot crosses midnight.
            $restaurant->hours()->create([
                'day_of_week' => $day,
                'opens_at' => '18:00:00',
                'closes_at' => '00:30:00',
                'is_closed' => false,
            ]);
        }

        $restaurant->holidays()->create([
            'date' => now()->addDays(30)->toDateString(),
            'reason' => 'Annual maintenance',
        ]);

        return $restaurant;
    }

    private function attachStaff(Restaurant $restaurant, string $name, string $email, string $role): void
    {
        $user = User::factory()->staff()->create([
            'name' => $name,
            'email' => $email,
        ]);

        $restaurant->staff()->attach($user->getKey(), [
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function seedCustomer(Restaurant $defaultStore): void
    {
        $customer = User::factory()->create([
            'name' => 'Aarav Kapoor',
            'phone' => '+919999000011',
            'selected_restaurant_id' => $defaultStore->getKey(),
        ]);

        $customer->addresses()->create([
            'label' => 'Home',
            'address_line1' => 'Flat 402, Rohit Apartments',
            'address_line2' => 'Barakhamba Road',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'postal_code' => '110001',
            'country' => 'IN',
            'latitude' => 28.6290,
            'longitude' => 77.2250,
            'is_default' => true,
        ]);
    }
}
