<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addon_groups', function (Blueprint $table) {
            // Groups sharing the same non-null key form one family: the customer
            // holds a single selection across all of them (e.g. "Choose Beverage"
            // and "Choose Beverage Upgrade"). Null means the group is independent.
            $table->string('exclusive_key', 64)->nullable()->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('addon_groups', function (Blueprint $table) {
            $table->dropColumn('exclusive_key');
        });
    }
};
