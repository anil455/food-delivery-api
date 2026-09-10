<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            // NULL means a platform-wide coupon owned by the super admin.
            // Coupons are therefore NOT tenant-scoped by the global scope.
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('description')->nullable();

            $table->string('type', 20);                              // fixed | percent
            $table->unsignedBigInteger('value');                     // minor units, or basis points for percent
            $table->unsignedBigInteger('max_discount')->nullable();  // cap for percent coupons
            $table->unsignedBigInteger('min_order_amount')->default(0);

            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['restaurant_id', 'code']);
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
