<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            /*
             * Exactly one cart per user, and the cart itself carries the
             * restaurant. That single unique index is what makes "a cart can
             * never mix restaurants" a structural property of the schema rather
             * than a rule the application has to remember. See deviation D4.
             */
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('restaurant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
