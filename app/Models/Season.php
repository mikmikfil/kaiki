<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Actions\SaveSeason;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Contracts\TranslatableSearchable;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A pricing calendar (`docs/data-model.md` §2.3, spec CAT-9, PRC-3, PRC-4).
 *
 * **No `uuid`** per §1.1: seasons are operator-only objects edited inside a
 * tenant-scoped resource, so an integer id is safe until they appear in the
 * public API.
 *
 * Ranges may overlap **across** seasons — that is what `priority` is for, and
 * it is how "August" sits inside "Summer" and wins. Within one season they may
 * not, which is a set-level rule and lives in
 * {@see SaveSeason}.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name translatable
 * @property string|null $code
 * @property int $priority higher wins
 * @property bool $is_active
 */
class Season extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var list<string> */
    protected array $translatableSearch = ['name'];

    /** @var list<string> */
    protected array $translatableSort = ['name'];

    /** @var list<string> */
    protected array $requiredTranslations = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SeasonDateRange, $this> */
    public function dateRanges(): HasMany
    {
        return $this->hasMany(SeasonDateRange::class)->orderBy('starts_on');
    }

    /**
     * @param  Builder<Season>  $query
     * @return Builder<Season>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Does any of this season's ranges contain the date? */
    public function contains(Carbon $date): bool
    {
        return $this->dateRanges->contains(fn (SeasonDateRange $range): bool => $range->contains($date));
    }

    /**
     * The narrowest of this season's ranges that contains the date, in days.
     *
     * PRC-4's second tie-break. A season whose August range matches beats one
     * whose whole-summer range also matches, because the narrower statement is
     * the more specific one — which is what an operator means by writing it.
     */
    public function narrowestMatchingRangeDays(Carbon $date): ?int
    {
        return $this->dateRanges
            ->filter(fn (SeasonDateRange $range): bool => $range->contains($date))
            ->map(fn (SeasonDateRange $range): int => $range->lengthInDays())
            ->min();
    }
}
