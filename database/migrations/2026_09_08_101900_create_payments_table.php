<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            $table->string('gateway', 40);                       // razorpay, stripe, cod
            $table->string('gateway_payment_id', 191)->nullable();
            $table->string('gateway_order_id', 191)->nullable();

            $table->unsignedBigInteger('amount');                // minor units
            $table->string('currency', 3)->default('INR');
            $table->string('status', 30)->default('pending');

            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('failure_reason', 255)->nullable();

            // Raw gateway response, kept for reconciliation and dispute handling.
            $table->json('payload')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['gateway', 'gateway_payment_id']);
            $table->index(['restaurant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
