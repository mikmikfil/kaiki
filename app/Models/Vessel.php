<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Concerns\HasUuid;
use App\Models\Contracts\TranslatableSearchable;
use App\Observers\VesselObserver;
use App\Support\Tenancy;
use Database\Factories\VesselFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The bookable resource (`docs/data-model.md` §2.3, spec CAT-1, CAT-2).
 *
 * Nothing in the product is bookable without a free vessel window (AVL-1), so
 * this model sits under the whole availability engine. It deliberately holds no
 * availability logic itself: occupancy is queried only through
 * `App\Domain\Availability\VesselCalendar` (ADR-0023), and an architecture test
 * enforces that.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property VesselType $type
 * @property string|null $registration_number
 * @property int|null $length_cm
 * @property int $capacity_max
 * @property int $crew_count
 * @property string|null $captain_name
 * @property int|null $home_port_id
 * @property int|null $turnaround_buffer_minutes
 * @property string|null $description
 * @property array<string, mixed> $specs
 * @property array<int, array<string, mixed>> $images
 * @property VesselStatus $status
 * @property int $sort_order
 * @property string|null $search_index ADR-0008 companion, written by SearchIndexObserver
 * @property string|null $name_sort ADR-0008 companion, written by SearchIndexObserver
 */
#[ObservedBy(VesselObserver::class)]
class Vessel extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<VesselFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * The **name is not here on purpose**: a boat's name is a proper noun
     * (§1.6), not a phrase to render into English.
     *
     * @var list<string>
     */
    public array $translatable = ['description'];

    /** @var list<string> */
    protected array $translatableSearch = ['description'];

    /**
     * The plain column that still needs folding.
     *
     * `name` is the first thing an operator types into the search box, and a
     * raw `LIKE` over it is not portable: MySQL folds Greek tonos, SQLite does
     * not, so `οδυσσευς` would find `Οδυσσεύς` in production and miss it
     * locally. It joins the same `search_index` haystack as `description`, so
     * one search finds a boat by either.
     *
     * @var list<string>
     */
    protected array $foldedSearch = ['name'];

    /** @var list<string> */
    protected array $foldedSort = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => VesselType::class,
            'status' => VesselStatus::class,
            'length_cm' => 'integer',
            'capacity_max' => 'integer',
            'crew_count' => 'integer',
            'turnaround_buffer_minutes' => 'integer',
            'specs' => 'array',
            'images' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Port, $this> */
    public function homePort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'home_port_id');
    }

    /**
     * The turnaround gap this boat actually needs, in minutes (CAT-2, AVL-7).
     *
     * **The one documented resolution of the inheritance**, so nothing else
     * writes the `??`. `turnaround_buffer_minutes` is nullable-with-inheritance
     * rather than defaulted per vessel precisely so that changing the tenant
     * default changes behaviour — a per-row copy of `60` would make the tenant
     * setting decorative, and the operator who lowered it would be the last to
     * find out.
     *
     * §7.2 requires this be "read once and passed in — never joined inside
     * these queries", so this is the application-side resolver and there is no
     * SQL `COALESCE` in the availability path.
     *
     * The tenant is taken from the resolved context when it is the owner of
     * this row, which is the overwhelmingly common case and costs no query; the
     * relation is only loaded when the two disagree — a super-admin looking
     * across operators, or a console command iterating tenants.
     */
    public function effectiveTurnaroundBufferMinutes(): int
    {
        if ($this->turnaround_buffer_minutes !== null) {
            return $this->turnaround_buffer_minutes;
        }

        $current = Tenancy::current();

        $tenant = $current !== null && $current->getKey() === $this->tenant_id
            ? $current
            : $this->tenant()->withoutGlobalScopes()->first();

        // A vessel whose tenant cannot be loaded is a row that should not exist
        // — `tenant_id` is a cascading foreign key. Falling back to the
        // documented default keeps a conflict check answering rather than
        // fataling, and the reconciler is what notices the orphan.
        return $tenant->turnaround_buffer_minutes ?? self::defaultTurnaroundBufferMinutes();
    }

    /** The AVL-7 fixed default, matching the `tenants` column default. */
    public static function defaultTurnaroundBufferMinutes(): int
    {
        return 60;
    }

    /** Does this vessel inherit its buffer rather than setting its own? */
    public function inheritsTurnaroundBuffer(): bool
    {
        return $this->turnaround_buffer_minutes === null;
    }
}
