<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SeasonDateRangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a season runs (`docs/data-model.md` §2.3).
 *
 * **Local calendar dates, both bounds inclusive.** "1 June to 15 September" is
 * what an operator writes, and a 15 September departure is in the season. No
 * uuid and no soft deletes: a range is an internal join with no public
 * identity, and a removed range is removed — a quote already given carries its
 * own price snapshot.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $season_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 */
class SeasonDateRange extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SeasonDateRangeFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * Does this range contain the date?
     *
     * Compared on the **calendar date** rather than the instant, because both
     * sides are dates and a `Carbon` carrying a time would otherwise exclude
     * the last day from 00:00:01 onwards.
     */
    public function contains(Carbon $date): bool
    {
        return $date->toDateString() >= $this->starts_on->toDateString()
            && $date->toDateString() <= $this->ends_on->toDateString();
    }

    /** Inclusive length, so 1 June to 1 June is one day rather than zero. */
    public function lengthInDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** Do two ranges share any date? Inclusive at both ends. */
    public function overlaps(self $other): bool
    {
        return $this->starts_on->toDateString() <= $other->ends_on->toDateString()
            && $other->starts_on->toDateString() <= $this->ends_on->toDateString();
    }
}
