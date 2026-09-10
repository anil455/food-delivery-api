<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Slugs are unique per restaurant, not globally — two restaurants
            // may both have a "starters" category.
            $table->unique(['restaurant_id', 'slug']);

            /*
             * Parent side of the composite tenant foreign key. This is what
             * makes it physically impossible for a product in restaurant A to
             * reference a category in restaurant B. See architecture §08 L1.
             */
            $table->unique(['id', 'restaurant_id'], 'categories_tenant_unique');

            $table->index(['restaurant_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
