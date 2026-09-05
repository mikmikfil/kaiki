<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Exceptions\AuditLogIsAppendOnly;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in the operator's audit trail (ADR-0025, spec SEC-16;
 * `docs/data-model.md` §2.8).
 *
 * ## Append-only, enforced rather than agreed
 *
 * ADR-0025: *"Never updated and never deleted by application code — an audit
 * row that can be edited is not an audit row."* The migration leaves out
 * `updated_at`, which stops the obvious accident; this model **throws** on
 * `updating` and `deleting`, which stops the deliberate one.
 *
 * Both halves are needed. A missing column is a convention a future developer
 * reads as an oversight and adds; a boot guard is a statement. The one
 * exception is the retention purge, which deletes through the query builder
 * rather than through the model — see {@see AuditLog::purge()} for why that is
 * the honest way to spell it rather than a hole in the guard.
 *
 * ## Why there is no uuid
 *
 * §1.1 gives public identifiers a uuid; an audit row has no public surface at
 * all. It is never fetched by id from an API, never linked to, and never leaves
 * `/app`. Adding one would be a column nobody reads on the table with the
 * strictest growth budget in M1.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id null = a system action, or an erased actor
 * @property AuditAction $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label what it was called at the time
 * @property string|null $reason SEC-16's "where applicable"
 * @property array<string, mixed> $context no personal data — ADR-0025 §3
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * `created_at` only. The migration has no `updated_at` column, so Eloquent
     * must be told not to write one — otherwise every insert fails on a column
     * that does not exist.
     */
    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'context' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Not `saving`: an insert is a save, and inserting is the only thing
        // this model is for. `updating` fires only on a row that already exists.
        static::updating(static function (self $log): never {
            throw AuditLogIsAppendOnly::onUpdate($log);
        });

        static::deleting(static function (self $log): never {
            throw AuditLogIsAppendOnly::onDelete($log);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The actor, as something a panel can render.
     *
     * Null when there was none, and null when there was one who has since been
     * erased — the two are deliberately indistinguishable here, because after a
     * GDPR erasure they are the same fact: this row does not identify a person.
     */
    public function actorName(): ?string
    {
        return $this->user?->name;
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        // Ties broken by id, so a page boundary cannot repeat or skip a row.
        // Two actions in the same second is ordinary — a delete usually fires
        // several.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Delete rows older than the retention window (ADR-0025 §3).
     *
     * **Through the query builder, deliberately.** The model's `deleting` guard
     * would refuse this, and disabling the guard for the purge would leave a
     * hole anything could reach through. Going around the model instead makes
     * the exception one named method, on the class, with this docblock beside
     * it — which is the difference between an exception and a loophole.
     *
     * Not tenant-scoped: retention is a platform obligation and runs across
     * every operator at once. `withoutTenancy()` is the documented way to say
     * so, and `PurgeAuditLogCommand` is the only caller.
     */
    public static function olderThan(Carbon $before): int
    {
        return Tenancy::withoutTenancy(
            static fn (): int => static::query()->where('created_at', '<', $before)->count(),
        );
    }

    public static function purge(Carbon $before): int
    {
        return Tenancy::withoutTenancy(
            static fn (): int => static::query()->where('created_at', '<', $before)->delete(),
        );
    }
}
