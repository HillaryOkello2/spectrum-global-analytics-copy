<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Traits\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable;

    /** Bypasses every gate — see AppServiceProvider::boot(). */
    public const SYSTEM_ADMIN = 'System Admin';

    public const ADMIN = 'admin';

    public const SUBSCRIBER = 'subscriber';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'country',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->first_name} {$this->last_name}"),
        );
    }

    /**
     * Which frontend portal this user belongs to — the frontend uses this to
     * route after login and on session rehydration (§18.2 login flow).
     */
    public function portal(): string
    {
        return $this->isStaff() ? 'admin' : 'subscriber';
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::staffRoleNames());
    }

    /**
     * Every role except `subscriber` counts as staff, including any an admin
     * creates — so a new role is assignable the moment it exists.
     *
     * @return array<int, string>
     */
    public static function staffRoleNames(): array
    {
        return Role::whereNot('name', self::SUBSCRIBER)->pluck('name')->all();
    }

    /**
     * The two admin people-endpoints address disjoint populations: /admin/users
     * is staff, /admin/subscribers is subscribers.
     */
    public function scopeStaff(Builder $query): Builder
    {
        return $query->role(self::staffRoleNames());
    }

    public function scopeSubscriber(Builder $query): Builder
    {
        return $query->role(self::SUBSCRIBER);
    }

    /**
     * Permissions this user can actually exercise, for UI gating.
     *
     * A System Admin holds no permission rows — it bypasses gates instead — so
     * reporting its raw permission set would understate what it can do.
     *
     * @return Collection<int, string>
     */
    public function effectivePermissions(): Collection
    {
        return $this->hasRole(self::SYSTEM_ADMIN)
            ? Permission::orderBy('name')->pluck('name')
            : $this->getAllPermissions()->pluck('name');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', SubscriptionStatus::Active)
            ->latest('starts_at');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductConsumption::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ProductPurchase::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['tier'] ?? null, function (Builder $q, string $tier) {
                $q->whereHas('activeSubscription.tier', fn (Builder $q) => $q->where('name', $tier));
            });
    }
}
