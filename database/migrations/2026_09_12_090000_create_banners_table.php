<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable on purpose, unlike every other tenant-owned table: null
             * means a platform banner (shown everywhere), set means a single
             * restaurant's own promotion (shown only within its delivery
             * radius). BelongsToRestaurant assumes an always-present tenant,
             * so this table is scoped by hand instead — see BannerFinder.
             */
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();

            // Nullable: only the image is required to publish a banner.
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();

            // Nullable: a banner is created first, then its image uploaded
            // through a separate multipart endpoint, same two-step flow as
            // Category and Product images.
            $table->string('image_path')->nullable();

            // Where tapping the banner goes. Kept generic rather than a hard
            // foreign key so a platform banner can point at a URL a
            // restaurant banner never would.
            $table->string('link_type', 20)->nullable();
            $table->string('link_value')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'is_active']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
