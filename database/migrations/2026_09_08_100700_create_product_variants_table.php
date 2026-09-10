<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');

            $table->string('name', 100);          // "Regular", "Large", "Family"
            $table->string('sku', 64)->nullable();

            // Absolute price, not a delta — a variant fully replaces base_price,
            // which keeps pricing arithmetic free of sign errors.
            $table->unsignedBigInteger('price');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->unique(['id', 'restaurant_id'], 'product_variants_tenant_unique');

            $table->foreign(['product_id', 'restaurant_id'], 'product_variants_product_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
