<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('discount_amount');       // minor units

            $table->timestamps();

            /*
             * One redemption row per order. This unique index is the actual
             * guard against a coupon being applied twice by two concurrent
             * checkout requests: the loser hits a duplicate-key error inside its
             * transaction and rolls back, rather than both passing a
             * read-then-write usage check.
             */
            $table->unique('order_id');

            // Drives the per_user_limit check.
            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
