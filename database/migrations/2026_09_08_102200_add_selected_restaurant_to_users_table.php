<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.selected_restaurant_id is declared in the users migration but its
     * foreign key has to wait until restaurants exists. Splitting it out keeps
     * the create-table order acyclic.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // If the chosen store is removed, the customer simply falls back to
            // nearest-store resolution on their next request.
            $table->foreign('selected_restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->nullOnDelete();

            $table->index('selected_restaurant_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['selected_restaurant_id']);
            $table->dropIndex(['selected_restaurant_id']);
        });
    }
};
