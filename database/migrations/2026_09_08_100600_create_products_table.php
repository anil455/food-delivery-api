<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            // Minor units (paise). See App\Support\Money.
            $table->unsignedBigInteger('base_price');
            $table->unsignedBigInteger('compare_at_price')->nullable();
            $table->decimal('tax_percentage', 5, 2)->nullable();

            $table->boolean('is_veg')->default(true);
            $table->unsignedTinyInteger('spice_level')->nullable();
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();

            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['restaurant_id', 'slug']);
            $table->unique(['id', 'restaurant_id'], 'products_tenant_unique');

            $table->index(['restaurant_id', 'category_id', 'is_active']);
            $table->index(['restaurant_id', 'is_available', 'is_active']);

            /*
             * The composite tenant foreign key: a product may only point at a
             * category belonging to the same restaurant. Enforced by InnoDB, so
             * it holds even if every layer of application code is bypassed.
             */
            $table->foreign(['category_id', 'restaurant_id'], 'products_category_tenant_fk')
                ->references(['id', 'restaurant_id'])
                ->on('categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
