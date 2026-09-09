<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Concerns\HasUuid;
use App\Models\Contracts\TranslatableSearchable;
use Database\Factories\PortFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A meeting point *and* a home port (`docs/data-model.md` §2.3, spec CAT-3).
 *
 * One model for both roles by design — `products.meeting_point_id` and
 * `vessels.home_port_id` point at the same table, because operators reuse the
 * same marina for both and the data is identical. §4 of the brief names the
 * concept "Port / MeetingPoint" and forbids splitting or renaming it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string|null $address
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $instructions
 * @property string|null $photo_path
 * @property string|null $maps_url
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $search_index ADR-0008 companion, written by SearchIndexObserver
 * @property string|null $name_sort_el ADR-0008 companion, written by SearchIndexObserver
 * @property string|null $name_sort_en ADR-0008 companion, written by SearchIndexObserver
 */
class Port extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<PortFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'instructions'];

    /**
     * Both, because an operator hunting for a pickup point searches by whatever
     * they remember — the marina's name or the "blue kiosk" in the directions.
     *
     * @var list<string>
     */
    protected array $translatableSearch = ['name', 'instructions'];

    /** @var list<string> */
    protected array $translatableSort = ['name'];

    /**
     * `instructions` is deliberately absent: a port with no written directions
     * is ordinary, and requiring them would block an operator from saving a
     * marina everyone already knows how to find.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Cast as decimal strings rather than floats. These are the only
            // decimal columns in the schema (§2.3) and they exist because a
            // float drifts; casting to float here would reintroduce the drift
            // the column type was chosen to avoid.
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Vessel, $this> */
    public function vessels(): HasMany
    {
        return $this->hasMany(Vessel::class, 'home_port_id');
    }

    /**
     * Where to send a guest who taps "open in maps".
     *
     * The operator's own `maps_url` wins when set, because some marinas resolve
     * badly from a postal address and the operator knows the pin that works.
     * Otherwise coordinates, which are exact, and only then the address string.
     * Null when there is nothing to link to at all — the caller hides the
     * button rather than rendering a search for the empty string.
     */
    public function mapsUrl(): ?string
    {
        if ($this->maps_url !== null && $this->maps_url !== '') {
            return $this->maps_url;
        }

        if ($this->lat !== null && $this->lng !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                . rawurlencode("{$this->lat},{$this->lng}");
        }

        if ($this->address !== null && $this->address !== '') {
            return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($this->address);
        }

        return null;
    }

    /**
     * The same place, as an embeddable map (HOS-2).
     *
     * ## OpenStreetMap, because Google's keyless embed stopped existing
     *
     * This used to be `https://www.google.com/maps?q=…&output=embed`, which
     * needed no API key and worked for years. It does not work now:
     * that URL 301s to `/maps/embed?origin=mfe&pb=…`, which answers **404**
     * and sends `X-Frame-Options: SAMEORIGIN` with it. So every trip page with
     * a meeting point had a full-width grey void in the middle of it — no
     * console error, no failing test, nothing to see from in here. It was
     * found by looking at the page.
     *
     * Google's supported replacement is the Embed API, which is keyed. That
     * would put a platform credential in the markup of every operator's page
     * and hand the platform a per-render bill for a static picture of a
     * marina. OSM's `export/embed.html` is keyless, framed by design, and
     * costs nothing; the attribution it requires is rendered under the frame.
     *
     * ## Coordinates only
     *
     * The embed takes a bounding box, not a search term, so an address with no
     * coordinates can no longer be drawn — it keeps {@see mapsUrl()} and gets
     * no frame. That is the same subtraction this method already made for a
     * pasted short link, and for the same reason: on the section that tells a
     * guest where to stand at nine in the morning, nothing is better than a
     * broken box.
     *
     * ## Built from coordinates, and never from `maps_url`
     *
     * `maps_url` is whatever the operator pasted — very often a `goo.gl` or
     * `maps.app.goo.gl` short link, which no provider will render inside a
     * frame. A custom URL keeps the link and gets no embed, so the two are
     * different questions rather than one value used twice.
     */
    public function mapsEmbedUrl(): ?string
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }

        $lat = (float) $this->lat;
        $lng = (float) $this->lng;

        // About 350m across and 220m down at Aegean latitudes: a marina and
        // the streets that reach it, which is the question this map answers.
        // Wider and the pin is a dot in a city; tighter and there is no
        // landmark next to it to recognise.
        $box = implode(',', [
            $this->coordinate($lng - 0.004),
            $this->coordinate($lat - 0.002),
            $this->coordinate($lng + 0.004),
            $this->coordinate($lat + 0.002),
        ]);

        return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($box)
            . '&layer=mapnik&marker=' . rawurlencode($this->coordinate($lat) . ',' . $this->coordinate($lng));
    }

    /**
     * Six decimals — about 10cm — and never exponential notation.
     *
     * `(string) 1.0E-5` is `1.0E-5`, which a bounding box parser reads as zero
     * or as nothing. No meeting point is at that longitude, but the null island
     * case is exactly the one nobody tests.
     */
    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
