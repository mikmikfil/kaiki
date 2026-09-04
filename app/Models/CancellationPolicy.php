<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Pricing\CancellationPolicyData;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Contracts\TranslatableSearchable;
use Database\Factories\CancellationPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The operator's cancellation terms (`docs/data-model.md` §2.3, spec CAT-13).
 *
 * **Nothing computes a refund from this model.** CXL-1 is fixed: money is
 * decided by `bookings.policy_snapshot`, frozen at booking time. This row feeds
 * that snapshot once, through {@see CancellationPolicyData::fromModel()}, and
 * supplies the policy text a guest reads while browsing. Editing it changes
 * what future guests agree to and nothing about bookings already taken.
 *
 * **No `uuid`**, per §1.1: policies are operator-only objects edited inside a
 * tenant-scoped Filament resource, so an integer id is safe. The document notes
 * they gain one "the day they appear in the public API".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name translatable
 * @property string|null $summary translatable
 * @property int|null $free_cancellation_hours
 * @property int $weather_refund_percent
 * @property int $force_majeure_voucher_months
 * @property int $no_show_refund_percent
 * @property bool $is_default
 */
class CancellationPolicy extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<CancellationPolicyFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'summary'];

    /** @var list<string> */
    protected array $translatableSearch = ['name', 'summary'];

    /**
     * Both locales required on the name — a guest choosing between "Ευέλικτη"
     * and a blank is choosing blind. The summary is optional in both.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = ['name'];

    /** @var list<string> */
    protected array $translatableSort = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'free_cancellation_hours' => 'integer',
            'weather_refund_percent' => 'integer',
            'force_majeure_voucher_months' => 'integer',
            'no_show_refund_percent' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /** @return HasMany<CancellationPolicyTier, $this> */
    public function tiers(): HasMany
    {
        // Ordered largest threshold first, which is evaluation order (§3.3) and
        // also the order an operator reads a refund ladder in.
        return $this->hasMany(CancellationPolicyTier::class)->orderByDesc('days_before');
    }

    /**
     * @param  Builder<CancellationPolicy>  $query
     * @return Builder<CancellationPolicy>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Freeze this policy into the shape a booking stores.
     *
     * The single crossing point between the live table and the refund path —
     * everything downstream takes the value object.
     */
    public function toSnapshotData(): CancellationPolicyData
    {
        return CancellationPolicyData::fromModel($this->loadMissing('tiers'));
    }
}
