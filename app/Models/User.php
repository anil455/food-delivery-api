<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;
use App\Enums\UserType;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customers and staff share one table, separated by `type`.
 *
 * Customers authenticate by phone + OTP and have no password; staff
 * authenticate by email + password. Platform-wide privilege is the single
 * is_super_admin flag; per-restaurant privilege lives in restaurant_users.
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /**
     * Attributes a request may never set. Privilege and verification state are
     * assigned by services only.
     */
    protected $guarded = [
        'id',
        'type',
        'is_super_admin',
        'status',
        'phone_verified_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'is_super_admin' => 'boolean',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted, not hashed: the server must be able to read the seed
            // back to verify a code, and recovery codes to compare them.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function selectedRestaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class, 'selected_restaurant_id');
    }

    /** Restaurants this staff member may act on, with their role on the pivot. */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_users')
            ->using(RestaurantUser::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(RestaurantUser::class);
    }

    // ── Role helpers ────────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin === true;
    }

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isCustomer(): bool
    {
        return $this->type === UserType::Customer;
    }

    /** The role this user holds at a restaurant, or null if none. */
    public function roleInRestaurant(Restaurant|int $restaurant): ?StaffRole
    {
        $id = $restaurant instanceof Restaurant ? $restaurant->getKey() : $restaurant;

        $membership = $this->memberships()
            ->where('restaurant_id', $id)
            ->where('status', 'active')
            ->first();

        return $membership?->role;
    }

    /**
     * Membership check used by every policy.
     *
     * Super admins pass for any restaurant; everyone else needs an active
     * membership row, optionally of at least a given role.
     */
    public function hasRoleInRestaurant(Restaurant|int $restaurant, ?StaffRole $atLeast = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $role = $this->roleInRestaurant($restaurant);

        if ($role === null) {
            return false;
        }

        return $atLeast === null || $role->atLeast($atLeast);
    }

    /**
     * Where the sms notification channel delivers.
     *
     * Returning null for an unverified number means a notification is silently
     * skipped rather than texted to a phone nobody has proven they hold.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone_verified_at !== null ? $this->phone : null;
    }

    // ── Filament panel access ───────────────────────────────────────────────

    /**
     * Who may open a panel at all.
     *
     * Customers are excluded outright: they have no password, and the admin
     * panel is not theirs. A blocked staff account loses access immediately,
     * without waiting for a token to expire.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isStaff() || $this->status !== 'active') {
            return false;
        }

        return $panel->getId() === 'platform'
            ? $this->isSuperAdmin()
            : true;
    }

    /**
     * The restaurants shown in the panel switcher.
     *
     * Backed by the same restaurant_users membership the API checks, so the
     * panel cannot disagree with the API about who works where.
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isSuperAdmin()) {
            return Restaurant::query()->orderBy('name')->get();
        }

        return $this->restaurants()
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Restaurant && $this->hasRoleInRestaurant($tenant);
    }

    /** Two-factor gates a login only once the authenticator has been proven. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** The Sanctum abilities a freshly issued token for this user should carry. */
    public function tokenAbilities(): array
    {
        if ($this->isSuperAdmin()) {
            return ['admin'];
        }

        return [$this->type->defaultTokenAbility()];
    }
}
