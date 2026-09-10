<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            /*
             * Deliberately plain nullable foreign keys, NOT the composite tenant
             * keys used across the catalogue. An order item has to survive its
             * product being deleted, and a composite key cannot null just one of
             * its columns while restaurant_id stays NOT NULL. The snapshot below
             * is the record of truth; these ids are only a convenience link for
             * the reorder feature.
             */
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Immutable snapshot taken at checkout.
            $table->string('product_name');
            $table->string('variant_name', 100)->nullable();
            $table->boolean('is_veg')->default(true);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('addons_total')->default(0);
            $table->unsignedBigInteger('line_subtotal');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->json('product_snapshot')->nullable();

            $table->string('special_instructions', 255)->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('restaurant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
