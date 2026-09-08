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
     * ## Built from coordinates or an address, and never from `maps_url`
     *
     * `maps_url` is whatever the operator pasted — very often a `goo.gl` or
     * `maps.app.goo.gl` short link, which Google will not render inside a
     * frame. Embedding one produces a grey box with a refusal in it, on the
     * page that tells a guest where to stand at nine in the morning. So a
     * custom URL keeps the link and gets no embed, and the two are different
     * questions rather than one value used twice.
     *
     * ## No API key, deliberately
     *
     * The `output=embed` form needs none. The keyed Embed API would put a
     * platform credential in the markup of every operator's page and give the
     * platform a per-render bill for a static map of a marina.
     */
    public function mapsEmbedUrl(): ?string
    {
        $query = match (true) {
            $this->lat !== null && $this->lng !== null => "{$this->lat},{$this->lng}",
            $this->address !== null && $this->address !== '' => $this->address,
            default => null,
        };

        if ($query === null) {
            return null;
        }

        return 'https://www.google.com/maps?q=' . rawurlencode($query) . '&output=embed';
    }
}
