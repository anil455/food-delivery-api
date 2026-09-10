<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->id();

            /*
             * The opaque handle returned by send-otp and required by verify-otp.
             * Verification looks the record up by this, never by phone alone,
             * which removes the race between two concurrent send requests for
             * the same number.
             */
            $table->uuid('verification_id')->unique();

            $table->string('phone', 20);            // always E.164
            $table->string('purpose', 30)->default('login');

            /*
             * Bcrypt hash of the code, never the code itself. Six digits is a
             * small keyspace, so the real defence is the short TTL and the
             * attempt cap below; hashing stops a casual database read from
             * handing over live codes.
             */
            $table->string('otp_hash');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');

            // Set on success, on hitting max attempts, and when superseded by a
            // resend. A consumed record can never verify again.
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            // Lookup for "void every live code for this phone" on resend.
            $table->index(['phone', 'purpose', 'consumed_at']);
            // Drives the scheduled prune of expired rows.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_verifications');
    }
};
