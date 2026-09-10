<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('phone', 20);
            $table->string('email')->nullable();

            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();

            // Address
            $table->string('address_line');
            $table->string('landmark')->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postal_code', 20);
            $table->string('country', 2)->default('IN');

            /*
             * Coordinates as exact DECIMAL rather than a spatial POINT.
             * See architecture §07 / deviation D8: the bounding-box + Haversine
             * approach runs identically on MariaDB, MySQL 8 and PostgreSQL, and
             * removes the SRID axis-order bug class entirely.
             */
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            $table->decimal('delivery_radius_km', 6, 2)->default(5);

            // Money is stored in minor units (paise). See App\Support\Money.
            $table->unsignedBigInteger('min_order_amount')->default(0);
            $table->unsignedBigInteger('delivery_fee_base')->default(0);
            $table->unsignedBigInteger('delivery_fee_per_km')->default(0);
            $table->unsignedBigInteger('packaging_fee')->default(0);
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);

            $table->unsignedSmallInteger('avg_prep_time_minutes')->default(25);

            // Mandatory: is_open is meaningless without the restaurant's own zone.
            $table->string('timezone', 64)->default('Asia/Kolkata');

            $table->string('status', 30)->default('pending_approval');
            $table->boolean('is_accepting_orders')->default(true);

            $table->timestamps();
            $table->softDeletes();

            /*
             * Drives stage 1 of the nearby search: status filter plus a
             * latitude/longitude range scan, all from one composite B-tree.
             */
            $table->index(['status', 'latitude', 'longitude'], 'restaurants_geo_index');
            $table->index(['status', 'deleted_at']);
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
