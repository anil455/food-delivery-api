<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default('staff');
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // A user holds at most one role per restaurant.
            $table->unique(['restaurant_id', 'user_id']);

            // Drives "which restaurants can this staff member act on?" — the
            // lookup every staff request performs before a tenant context is set.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_users');
    }
};
