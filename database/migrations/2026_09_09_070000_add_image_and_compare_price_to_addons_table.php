<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('name');
            $table->unsignedBigInteger('compare_at_price')->nullable()->after('price'); // minor units
        });
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'compare_at_price']);
        });
    }
};
