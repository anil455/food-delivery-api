<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TOTP two-factor, for staff and platform accounts.
     *
     * Customers are excluded on purpose: they already authenticate with a
     * one-time code sent to a phone they have proven they hold, so a second
     * factor would add friction without adding a factor.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Encrypted at rest via the model cast. A leaked database row must
            // not hand over a working authenticator seed.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();

            // Null until the user proves the authenticator works. An unconfirmed
            // secret never gates a login, so a half-finished setup cannot lock
            // anyone out.
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
