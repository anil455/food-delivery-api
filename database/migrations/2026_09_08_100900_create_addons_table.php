<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('addon_group_id');

            $table->string('name');
            $table->unsignedBigInteger('price')->default(0);   // minor units
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id', 'restaurant_id'], 'addons_tenant_unique');
            $table->index(['addon_group_id', 'is_available']);

            $table->foreign(['addon_group_id', 'restaurant_id'], 'addons_group_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('addon_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
