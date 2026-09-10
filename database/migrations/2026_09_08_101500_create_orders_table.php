<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number', 32)->unique();

            /*
             * Set from the Idempotency-Key header. The unique index is what
             * actually prevents a double order on a retried request: a second
             * insert with the same key fails, and the original order is returned.
             */
            $table->string('idempotency_key', 64)->nullable()->unique();

            // Orders outlive people and restaurants: never cascade them away.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The delivery address as it stood at checkout. This, not address_id,
             * is the record of truth, so deleting a saved address can never
             * corrupt order history. See architecture section 04.
             */
            $table->json('delivery_address');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20);

            $table->string('status', 30)->default('pending');
            $table->string('payment_status', 30)->default('pending');
            $table->string('payment_method', 30)->nullable();

            // Every figure in minor units, all recomputed server-side at checkout.
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('delivery_fee')->default(0);
            $table->unsignedBigInteger('packaging_fee')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('tip')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->string('currency', 3)->default('INR');

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();

            $table->decimal('distance_km', 6, 2)->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->string('special_instructions', 500)->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Primary query for the restaurant dashboard: live orders, newest first.
            $table->index(['restaurant_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['restaurant_id', 'created_at']);
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
