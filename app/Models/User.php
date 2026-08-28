<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Operator staff and platform super-admins in one table (data-model §2.1).
 *
 * `tenant_id` is **nullable**: null means a platform super-admin on `/admin`,
 * non-null means operator staff on `/app`. That is why this model does not use
 * `BelongsToTenant` — a global scope here would make super-admins unqueryable
 * by their own panel. Access control for users is a policy question, handled
 * in #9.
 *
 * Email is globally unique, so one person cannot hold accounts at two operators
 * with the same address (ADR-0020 Option C). That is a real constraint, taken
 * knowingly: paying for multi-tenancy here means paying in tenant resolution,
 * which is the exact place a mistake leaks one operator's bookings to another.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property bool $is_super_admin
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'is_super_admin' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * Roles this user holds in their own tenant.
     *
     * Reads through the relation without the tenant scope, because a user is
     * frequently loaded before a tenant is resolved — during authentication,
     * for one. The query is still confined to this user's own rows, and the
     * user belongs to exactly one tenant.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        return $this->roleAssignments()
            ->withoutGlobalScopes()
            ->pluck('role')
            ->map(static fn (string|Role $role): Role => $role instanceof Role ? $role : Role::from($role))
            ->all();
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles(), strict: true);
    }

    public function isOwner(): bool
    {
        return $this->hasRole(Role::Owner);
    }

    /** A platform super-admin has no tenant and no role assignments. */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin && $this->tenant_id === null;
    }
}
