<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot: survives the add-on being renamed, repriced or removed.
            $table->string('addon_name');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('line_total');

            $table->timestamps();

            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_addons');
    }
};
