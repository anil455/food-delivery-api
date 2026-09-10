<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Enums\OrderStatus;
use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderStatusChanged;
use App\Services\Order\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderNotificationTest extends TestCase
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
            'phone' => '+919810011001',
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

    private function placeOrder(): Order
    {
        Sanctum::actingAs($this->customer, ['customer']);

        $this->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'quantity' => 1])
            ->assertCreated();

        $id = $this->postJson('/api/v1/orders', [
            'address_id' => $this->addressId, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        return Order::withoutTenantScope()->findOrFail($id);
    }

    #[Test]
    public function the_restaurant_is_texted_when_a_new_order_arrives(): void
    {
        Notification::fake();

        $this->placeOrder();

        // Sent to the restaurant phone rather than a user: a kitchen is a place,
        // not an account, and whoever holds the handset should see it.
        Notification::assertSentOnDemand(
            NewOrderReceived::class,
            fn (NewOrderReceived $n, array $channels, AnonymousNotifiable $notifiable): bool =>
                $notifiable->routes['sms'] === '+919810011001',
        );
    }

    #[Test]
    public function the_customer_is_texted_when_the_order_is_confirmed(): void
    {
        $order = $this->placeOrder();

        Notification::fake();

        app(OrderStatusService::class)->transition($order, OrderStatus::Confirmed);

        Notification::assertSentTo(
            $this->customer,
            fn (OrderStatusChanged $n): bool => str_contains($n->toSms($this->customer), 'accepted your order'),
        );
    }

    #[Test]
    public function intermediate_kitchen_steps_do_not_text_the_customer(): void
    {
        $order = $this->placeOrder();
        $statuses = app(OrderStatusService::class);
        $statuses->transition($order, OrderStatus::Confirmed);

        Notification::fake();

        // Texting on every internal step trains people to ignore the ones that
        // matter, and each message costs money.
        $statuses->transition($order->fresh(), OrderStatus::Preparing);
        $statuses->transition($order->fresh(), OrderStatus::ReadyForPickup);

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_customer_is_texted_when_the_order_is_out_for_delivery_and_delivered(): void
    {
        $order = $this->placeOrder();
        $statuses = app(OrderStatusService::class);

        foreach ([OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::ReadyForPickup] as $step) {
            $statuses->transition($order->fresh(), $step);
        }

        Notification::fake();

        $statuses->transition($order->fresh(), OrderStatus::OutForDelivery);
        $statuses->transition($order->fresh(), OrderStatus::Delivered);

        Notification::assertSentToTimes($this->customer, OrderStatusChanged::class, 2);
    }

    #[Test]
    public function a_cancellation_message_carries_the_reason(): void
    {
        $order = $this->placeOrder();

        Notification::fake();

        app(OrderStatusService::class)->transition(
            $order, OrderStatus::Cancelled, $this->customer, 'Changed my mind',
        );

        Notification::assertSentTo(
            $this->customer,
            function (OrderStatusChanged $n): bool {
                $body = $n->toSms($this->customer);

                return str_contains($body, 'cancelled')
                    && str_contains($body, 'Changed my mind')
                    && str_contains($body, 'refunded');
            },
        );
    }

    #[Test]
    public function an_unverified_phone_receives_nothing(): void
    {
        $order = $this->placeOrder();

        // Never text a number nobody has proven they hold.
        $this->customer->forceFill(['phone_verified_at' => null])->save();

        $this->assertNull($this->customer->fresh()->routeNotificationForSms());
    }

    #[Test]
    public function a_failed_text_does_not_roll_back_the_status_change(): void
    {
        $order = $this->placeOrder();

        // The real sender is swapped for one that always fails.
        $this->app->bind(\App\Services\Sms\SmsSender::class, fn () => new class implements \App\Services\Sms\SmsSender
        {
            public function send(string $phone, string $message): void
            {
                throw new \App\Services\Sms\SmsDeliveryException('provider down');
            }
        });

        app(OrderStatusService::class)->transition($order, OrderStatus::Confirmed);

        // The order still moved: delivery is best-effort, the state machine is not.
        $this->assertSame(
            OrderStatus::Confirmed,
            Order::withoutTenantScope()->findOrFail($order->id)->status,
        );
    }
}
