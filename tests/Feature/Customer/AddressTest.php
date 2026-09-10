<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer, ['customer']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'label' => 'Home',
            'address_line1' => 'Flat 402, Rohit Apartments',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'postal_code' => '110001',
            'latitude' => 28.6290,
            'longitude' => 77.2250,
            ...$overrides,
        ];
    }

    #[Test]
    public function a_customer_can_save_an_address(): void
    {
        $this->postJson('/api/v1/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.label', 'Home')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $this->customer->id,
            'address_line1' => 'Flat 402, Rohit Apartments',
        ]);
    }

    #[Test]
    public function the_first_address_becomes_the_default_automatically(): void
    {
        // Otherwise checkout has nothing to preselect for a brand new customer.
        $this->postJson('/api/v1/addresses', $this->payload(['is_default' => false]))
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);
    }

    #[Test]
    public function exactly_one_address_is_default_at_a_time(): void
    {
        $first = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Home']))->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload([
            'label' => 'Work',
            'is_default' => true,
        ]))->json('data.id');

        $this->assertDatabaseHas('addresses', ['id' => $second, 'is_default' => true]);
        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => false]);
        $this->assertSame(1, $this->customer->addresses()->where('is_default', true)->count());
    }

    #[Test]
    public function promoting_an_address_demotes_the_previous_default(): void
    {
        $first = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Home']))->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Work']))->json('data.id');

        $this->postJson("/api/v1/addresses/{$second}/default")->assertOk();

        $this->assertDatabaseHas('addresses', ['id' => $second, 'is_default' => true]);
        $this->assertDatabaseHas('addresses', ['id' => $first, 'is_default' => false]);
    }

    #[Test]
    public function deleting_the_default_promotes_another_address(): void
    {
        $first = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Home']))->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Work']))->json('data.id');

        $this->deleteJson("/api/v1/addresses/{$first}")->assertOk();

        // Never leave a customer holding addresses but no default.
        $this->assertDatabaseHas('addresses', ['id' => $second, 'is_default' => true]);
    }

    #[Test]
    public function a_customer_cannot_read_another_customers_address(): void
    {
        $stranger = User::factory()->create();
        $foreign = $stranger->addresses()->create($this->payload() + ['is_default' => true]);

        // Lookups start from the authenticated user relation, so a valid id
        // belonging to someone else simply does not exist.
        $this->getJson("/api/v1/addresses/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function a_customer_cannot_update_another_customers_address(): void
    {
        $stranger = User::factory()->create();
        $foreign = $stranger->addresses()->create($this->payload() + ['is_default' => true]);

        $this->patchJson("/api/v1/addresses/{$foreign->id}", ['label' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $foreign->id, 'label' => 'Home']);
    }

    #[Test]
    public function a_customer_cannot_delete_another_customers_address(): void
    {
        $stranger = User::factory()->create();
        $foreign = $stranger->addresses()->create($this->payload() + ['is_default' => true]);

        $this->deleteJson("/api/v1/addresses/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_list_only_contains_the_callers_own_addresses(): void
    {
        $this->postJson('/api/v1/addresses', $this->payload())->assertCreated();

        $stranger = User::factory()->create();
        $stranger->addresses()->create($this->payload(['label' => 'Someone else']) + ['is_default' => true]);

        $response = $this->getJson('/api/v1/addresses')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Home', $response->json('data.0.label'));
    }

    #[Test]
    public function it_validates_coordinates_and_required_fields(): void
    {
        $this->postJson('/api/v1/addresses', ['latitude' => 500, 'longitude' => -900])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address_line1', 'city', 'state', 'postal_code', 'latitude', 'longitude']);
    }

    #[Test]
    public function addresses_require_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/v1/addresses')
            ->assertStatus(401);
    }

    #[Test]
    public function a_staff_token_cannot_reach_customer_address_routes(): void
    {
        app('auth')->forgetGuards();

        Sanctum::actingAs(User::factory()->staff()->create(), ['restaurant']);

        // Two independent gates: the token ability and the user type.
        $this->getJson('/api/v1/addresses')->assertStatus(403);
    }
}
