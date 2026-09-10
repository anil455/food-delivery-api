<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_group_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('addon_group_id');
            $table->unsignedBigInteger('product_id');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['addon_group_id', 'product_id']);
            $table->index('product_id');

            // Both sides tenant-checked: a group and a product can only be
            // linked when they belong to the same restaurant.
            $table->foreign(['addon_group_id', 'restaurant_id'], 'agp_group_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('addon_groups')
                ->cascadeOnDelete();

            $table->foreign(['product_id', 'restaurant_id'], 'agp_product_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_group_product');
    }
};
