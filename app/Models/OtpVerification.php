<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One OTP challenge. Rows are short-lived and pruned on a schedule.
 *
 * A row is "live" only while it is unconsumed and unexpired. Consuming happens
 * on success, on exhausting attempts, and when a resend supersedes it, so a
 * consumed row can never verify again for any reason.
 */
class OtpVerification extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    public function scopeForPhone(Builder $query, string $phone, string $purpose): Builder
    {
        return $query->where('phone', $phone)->where('purpose', $purpose);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < (int) config('otp.max_attempts');
    }

    public function consume(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }
}
