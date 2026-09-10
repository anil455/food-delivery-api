<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_hours', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday … 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');

            /*
             * Multiple rows per day are allowed, so split service (lunch, then
             * dinner) is expressible. When closes_at <= opens_at the slot runs
             * past midnight into the following day.
             */
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->boolean('is_closed')->default(false);

            $table->timestamps();

            $table->index(['restaurant_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_hours');
    }
};
