<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CancellationPolicyTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a refund ladder (`docs/data-model.md` §2.3, spec CXL-3).
 *
 * No uuid: nothing outside the panel addresses a tier, and the guest sees the
 * ladder as part of its policy rather than as rows. No soft deletes either —
 * a removed rung is removed, and every booking that relied on it holds its own
 * frozen copy in `policy_snapshot`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cancellation_policy_id
 * @property int $days_before
 * @property int $refund_percent
 */
class CancellationPolicyTier extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CancellationPolicyTierFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'days_before' => 'integer',
            'refund_percent' => 'integer',
        ];
    }

    /** @return BelongsTo<CancellationPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }
}
