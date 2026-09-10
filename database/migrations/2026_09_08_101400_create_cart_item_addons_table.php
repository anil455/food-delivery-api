<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_item_addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('addon_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('price_at_add');

            $table->timestamps();

            $table->unique(['cart_item_id', 'addon_id']);

            $table->foreign(['addon_id', 'restaurant_id'], 'cart_item_addons_addon_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('addons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_addons');
    }
};
