<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Actions\SaveProduct;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Concerns\HasUuid;
use App\Models\Contracts\TranslatableSearchable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The catalogue item (`docs/data-model.md` §2.3, spec CAT-4, CAT-5).
 *
 * **`mode` drives everything downstream.** Which availability service answers,
 * whether departures are generated, whether seats are counted or a whole boat
 * is blocked, whether a price is ever shown — nearly every rule in the engine
 * branches on it. §2.3 makes it immutable once a booking exists, and the guard
 * for that lives in {@see SaveProduct} rather than
 * here, because it needs a collaborator the model should not know about.
 *
 * ## What this model deliberately does not do
 *
 * No availability, no pricing. Occupancy is queried only through
 * `App\Domain\Availability\VesselCalendar` (ADR-0023) and an architecture test
 * enforces it; `price_from_cents` is derived by #33. A product knows what it
 * *is*, not what it costs today or whether it can run on Tuesday.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $vessel_id
 * @property string $slug
 * @property ProductCategory $category
 * @property BookingMode $mode
 * @property string $title translatable
 * @property string|null $summary translatable
 * @property string|null $description translatable
 * @property int $duration_minutes
 * @property string|null $default_start_time
 * @property bool $flexible_start
 * @property string|null $earliest_start_time
 * @property string|null $latest_start_time
 * @property int $check_in_offset_minutes
 * @property int|null $meeting_point_id
 * @property array<string, list<string>>|null $includes
 * @property array<string, list<string>>|null $excludes
 * @property array<string, list<string>>|null $what_to_bring
 * @property array<string, mixed>|null $itinerary_stops
 * @property array<int, array<string, mixed>> $images
 * @property int $min_pax
 * @property int $max_pax
 * @property int $min_booking_pax
 * @property int|null $cancellation_policy_id
 * @property bool $guest_details_required
 * @property int $guest_details_deadline_hours
 * @property int|null $vat_rate_id
 * @property int|null $price_from_cents
 * @property ProductStatus $status
 * @property int $sort_order
 * @property bool $is_featured
 */
class Product extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * `includes`, `excludes`, `what_to_bring` and `itinerary_stops` are
     * translatable **arrays** (§3.5, §3.6) rather than strings, which the
     * package handles the same way — the value behind a locale key is simply
     * not a scalar.
     *
     * @var list<string>
     */
    public array $translatable = [
        'title', 'summary', 'description',
        'includes', 'excludes', 'what_to_bring', 'itinerary_stops',
        'meta_title', 'meta_description',
    ];

    /**
     * Searched, but not all of it.
     *
     * An operator looking for a trip types its name or a phrase from the
     * summary. Folding the full description in as well would make every product
     * match almost every query — the index is a haystack, and a haystack that
     * contains everything finds nothing.
     *
     * @var list<string>
     */
    protected array $translatableSearch = ['title', 'summary'];

    /** @var list<string> */
    protected array $translatableSort = ['title'];

    /**
     * Only the title. A product with no summary is ordinary and a product with
     * no English title is not — the second is a booking page with a blank where
     * the trip's name should be.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = ['title'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'images' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'mode' => BookingMode::class,
            'status' => ProductStatus::class,
            'duration_minutes' => 'integer',
            'flexible_start' => 'boolean',
            'check_in_offset_minutes' => 'integer',
            'images' => 'array',
            'min_pax' => 'integer',
            'max_pax' => 'integer',
            'min_booking_pax' => 'integer',
            'guest_details_required' => 'boolean',
            'guest_details_deadline_hours' => 'integer',
            'price_from_cents' => 'integer',
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Port, $this> */
    public function meetingPoint(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'meeting_point_id');
    }

    /** @return BelongsTo<CancellationPolicy, $this> */
    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    /**
     * The passenger categories this product is sold in (CAT-7).
     *
     * Ordered by the operator's own sequence rather than by age: an operator
     * who puts "Adult" first means it to be first in the booking form, and
     * sorting by `min_age` would silently promote the infant band.
     *
     * @return HasMany<AgeBand, $this>
     */
    public function ageBands(): HasMany
    {
        return $this->hasMany(AgeBand::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<VatRate, $this> */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    /**
     * The policy that actually applies (§2.3).
     *
     * Null on the product means the tenant default, which #23 guarantees always
     * exists. The one documented resolution of that fallback, so nothing else
     * writes the `??` — a second implementation is a second answer.
     */
    public function effectiveCancellationPolicy(): ?CancellationPolicy
    {
        return $this->cancellation_policy_id !== null
            ? $this->cancellationPolicy
            : CancellationPolicy::query()->default()->first();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOfMode(Builder $query, BookingMode $mode): Builder
    {
        return $query->where('mode', $mode);
    }

    /**
     * The ceiling this product may sell up to, as a legal fact rather than a
     * preference (CAT-5).
     *
     * `vessels.capacity_max` is the boat's certificate. A product may sell
     * fewer seats than the boat holds — an operator often does, for comfort —
     * but never more, and the guard is in the Action so the API and the
     * importer are bound by it too.
     */
    public function vesselCapacityCeiling(): ?int
    {
        return $this->vessel?->capacity_max;
    }

    /**
     * The itinerary's coordinates, which belong to no locale (§3.6).
     *
     * ## The `_geo` sidecar is only half-ignored by the package, and that is a trap
     *
     * §3.6 says the leading underscore marks the entry as not-a-locale "so
     * `spatie/laravel-translatable` and the observer ignore it". The **accessor**
     * does — `$product->itinerary_stops` correctly returns the current locale's
     * stop list and never sees `_geo`. But **`getTranslations()` returns it as
     * though it were a locale**, alongside `el` and `en`.
     *
     * So anything that walks `getTranslations('itinerary_stops')` — a form, an
     * export, a future search index — gets a pseudo-locale called `_geo` whose
     * value is a map of coordinates. These two methods exist so nothing has to
     * discover that.
     *
     * The sidecar itself is still right: duplicating coordinates per locale
     * invites the Greek and English versions of one stop to disagree about
     * where it is.
     *
     * @return array<string, array{lat: float, lng: float}>
     */
    public function itineraryGeo(): array
    {
        $geo = $this->getTranslations('itinerary_stops')['_geo'] ?? [];

        return is_array($geo) ? $geo : [];
    }

    /**
     * The stops for one locale, without the sidecar.
     *
     * @return list<array<string, mixed>>
     */
    public function itineraryStopsFor(?string $locale = null): array
    {
        $stops = $this->getTranslations('itinerary_stops')[$locale ?? app()->getLocale()] ?? [];

        return is_array($stops) ? array_values($stops) : [];
    }

    /**
     * Every locale's stops, with `_geo` removed.
     *
     * The shape a caller usually means by "the translations of this field", as
     * opposed to what the package hands back.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function itineraryTranslations(): array
    {
        $all = $this->getTranslations('itinerary_stops');

        unset($all['_geo']);

        return $all;
    }

    /** Does this product's mode allow the flexible-start window (CAT-5)? */
    public function supportsFlexibleStart(): bool
    {
        return $this->mode->allowsFlexibleStart();
    }
}
