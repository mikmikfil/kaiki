<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrewSpecialty;
use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use App\Support\Authorization\Capability;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
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
 * @property string|null $email null for «Χωρίς σύνδεση»: crew who never sign in (2026-09-24)
 * @property CrewSpecialty|null $specialty what they do on the boat
 * @property string|null $photo_path on the public disk, for the about page
 * @property array<string, string>|null $bio «Λίγα λόγια», per locale, for the about page
 * @property string|null $locale
 * @property bool $is_super_admin
 */
class User extends Authenticatable implements FilamentUser, HasLocalePreference
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
            'specialty' => CrewSpecialty::class,
            'bio' => 'array',
        ];
    }

    /**
     * «Λίγα λόγια», as the about page shows it (2026-09-24): the visitor's
     * language, else the other one, else nothing.
     */
    public function bioIn(string $locale): string
    {
        $bio = is_array($this->bio) ? $this->bio : [];

        return trim((string) ($bio[$locale] ?? $bio['el'] ?? $bio['en'] ?? ''));
    }

    /**
     * The language notifications to this person are sent in — the password
     * reset above all (2026-09-17). Falls back to the app's.
     */
    public function preferredLocale(): string
    {
        return $this->locale ?? (string) config('app.locale');
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

    /**
     * Crew and nothing more — the deckhand's panel: scan, today, the calendar
     * (Mike, 2026-09-24). One role per person (decision of 2026-09-18), but
     * asked as "no role above crew" so a second assignment can never shrink an
     * owner's screen.
     */
    public function isCrewOnly(): bool
    {
        return Role::highest($this->roles()) === Role::Crew;
    }

    /** A platform super-admin has no tenant and no role assignments. */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin && $this->tenant_id === null;
    }

    /**
     * Does this user hold the capability, through any of their roles?
     *
     * The union of roles applies: someone who is both manager and crew gets
     * everything either role allows. `role_assignments` permits multiple rows
     * per user precisely so a small operation can say "runs the office and also
     * skippers on Sundays" without inventing a fourth role.
     */
    public function hasCapability(Capability $capability): bool
    {
        // A super-admin is not an operator and holds no operator capabilities.
        // Reaching an operator's data is impersonation (TEN-7, M7), an audited
        // action rather than an implicit privilege.
        if ($this->isSuperAdmin()) {
            return false;
        }

        foreach ($this->roles() as $role) {
            if ($capability->grantedTo($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which panel may this user sign in to (SCP-1, SCP-13)?
     *
     * Strictly one each. An operator reaching `/admin` would see every
     * operator's data, and a super-admin landing in `/app` has no tenant to
     * scope to — so both are refused outright rather than rendered partially.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isSuperAdmin(),
            'app' => ! $this->is_super_admin && $this->tenant_id !== null,
            default => false,
        };
    }
}
