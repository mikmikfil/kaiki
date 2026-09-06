<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Models\Concerns\HasUuid;
use App\Observers\TenantObserver;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;
use Stancl\Tenancy\Database\Concerns\TenantRun;

/**
 * The operator. Not tenant-owned — it *is* the tenant (data-model §2.1).
 *
 * Single-database mode (ADR-0001), so this deliberately does not extend
 * stancl's own Tenant model: that one is built around a string key and a `data`
 * JSON blob, and every column here is a real column we filter, sort or index
 * on. Implementing the contract directly costs three small methods and keeps
 * the schema honest.
 *
 * Also the Cashier billable model in M7. Its columns land now because SQLite
 * cannot add them to an existing table without a rebuild (data-model §0).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string|null $vat_number
 * @property string $email
 * @property string $timezone
 * @property string $default_locale
 * @property array<int, string> $supported_locales
 * @property Plan $plan
 * @property TenantStatus $status
 * @property Carbon|null $trial_ends_at
 * @property string|null $custom_domain
 * @property bool $hosted_page_enabled
 * @property bool $is_sandbox
 * @property int $turnaround_buffer_minutes
 * @property int $guest_document_retention_days
 * @property bool $auto_issue_invoice
 * @property array<string, mixed> $settings
 * @property int|null $balance_due_days_before_departure
 * @property string|null $weather_choice_default
 */
#[ObservedBy(TenantObserver::class)]
class Tenant extends Model implements TenantContract
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasInternalKeys;
    use HasUuid;
    use SoftDeletes;
    use TenantRun;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_due_days_before_departure' => 'integer',
            'supported_locales' => 'array',
            'settings' => 'array',
            'plan' => Plan::class,
            'status' => TenantStatus::class,
            'trial_ends_at' => 'datetime',
            'custom_domain_verified_at' => 'datetime',
            'hosted_page_enabled' => 'boolean',
            'is_sandbox' => 'boolean',
            'turnaround_buffer_minutes' => 'integer',
            'guest_document_retention_days' => 'integer',
            'auto_issue_invoice' => 'boolean',
        ];
    }

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): int
    {
        return (int) $this->getKey();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * The operator's brand (BRD-1).
     *
     * `HasOne` rather than `HasMany` because the unique index on
     * `brand_profiles.tenant_id` makes the 1:1 a database fact, and
     * {@see TenantObserver} means it is never null on a tenant that finished
     * being created (BRD-3).
     *
     * @return HasOne<BrandProfile, $this>
     */
    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    /** Operators in `read_only` or `suspended` cannot write (see #7). */
    public function allowsWrites(): bool
    {
        return $this->status->allowsWrites();
    }
}
