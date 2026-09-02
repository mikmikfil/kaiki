<?php

declare(strict_types=1);

namespace Tests\Support\Translatable;

use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Contracts\TranslatableSearchable;
use App\Observers\SearchIndexObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A model that exists only to prove the translatable machinery works.
 *
 * `Product`, `Vessel`, `Port` and the rest of `docs/data-model.md` §1.6 are
 * #16 and later. Waiting for one of them would mean shipping
 * {@see HasTranslatableSearch}, {@see HasKaikiTranslations} and
 * {@see SearchIndexObserver} with **no execution at all** behind them, and
 * discovering their behaviour halfway through building the catalogue — which is
 * the worst moment, because a bug there looks like a bug in the catalogue.
 *
 * It also pins the adoption cost the traits advertise. Everything below the
 * class declaration is four attribute lists: if a real model ever needs more
 * than this — an observer of its own, a manual `search_index` write, a locale
 * loop — the arrangement has failed at the thing it exists to do, and this
 * fixture is where that shows up first.
 *
 * PHPStan reads `tests`, so this is also what makes the two traits *analysed*.
 * A trait nothing uses is skipped entirely (`trait.unused`), which is how the
 * missing observer this fixture depends on went unreported through a whole
 * red build.
 *
 * Deliberately **not** tenant-owned, though every real translatable table is
 * (§1.6). Tenancy is #5's concern and has its own suite in
 * `tests/Feature/Tenancy`; mixing it in here would mean a failure could be
 * either the fallback chain or the global scope, and the point of a fixture is
 * that there is only one thing it can be.
 *
 * The `@property` lines are what a real catalogue model will need too: reading
 * `$product->title` goes through the package's attribute accessor, which
 * returns the **resolved translation** rather than the JSON, and static
 * analysis has no way to know that from the column type.
 *
 * @property int $id
 * @property string $title
 * @property string|null $summary
 * @property list<string> $includes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TranslatableFixture extends Model implements TranslatableSearchable
{
    use HasKaikiTranslations;
    use HasTranslatableSearch;

    protected $table = 'translatable_fixtures';

    protected $guarded = [];

    /**
     * `spatie/laravel-translatable` reads this one; it must be public.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'summary', 'includes'];

    /**
     * `includes` is here to keep the **array** case honest.
     *
     * A translatable array (`{"el": ["…"], "en": ["…"]}`, §3.5) is the shape
     * that breaks a naive `implode()` in the observer, and `products.includes`
     * is a real column that a guest genuinely searches — "does the trip include
     * lunch" is asked by typing *lunch* into a search box.
     *
     * @var array<int, string>
     */
    protected array $translatableSearch = ['title', 'summary', 'includes'];

    /**
     * Only `title` is sortable, because only `title` has companion columns.
     *
     * The gap is the interesting part: `summary` is searchable but not
     * sortable, so `orderByTranslation('summary')` must be refused rather than
     * ordering by a column that does not exist.
     *
     * @var array<int, string>
     */
    protected array $translatableSort = ['title'];

    /**
     * @var array<int, string>
     */
    protected array $requiredTranslations = ['title'];
}
