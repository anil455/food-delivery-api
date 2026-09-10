<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();

            $table->unsignedSmallInteger('quantity')->default(1);

            /*
             * The price when the item was added, kept only so the cart can warn
             * "this price changed". It is never used for checkout — the order
             * service recomputes every figure from the products table.
             */
            $table->unsignedBigInteger('price_at_add');

            $table->string('special_instructions', 255)->nullable();

            $table->timestamps();

            $table->index('cart_id');

            $table->foreign(['product_id', 'restaurant_id'], 'cart_items_product_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('products')
                ->cascadeOnDelete();

            // NULL variant_id leaves this constraint satisfied (MATCH SIMPLE).
            $table->foreign(['product_variant_id', 'restaurant_id'], 'cart_items_variant_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('product_variants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
