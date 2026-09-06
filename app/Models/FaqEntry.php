<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hosted\Support\BlockText;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasUuid;
use Database\Factories\FaqEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

/**
 * One question an operator answers before it is asked.
 *
 * `product_id` null means the question is about the business; a product means
 * it is about that trip. Both render on the FAQ page — the second grouped under
 * the trip's own heading — and #104's product pages will show a trip's own
 * entries beside the general ones.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $product_id
 * @property string $question translatable
 * @property string $answer translatable, plain text
 * @property int $sort_order
 * @property bool $is_visible
 */
class FaqEntry extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FaqEntryFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasUuid;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['question', 'answer'];

    /**
     * **Both**, unlike a home-page block's optional heading.
     *
     * An entry is a pair. Half of one is not a shorter FAQ, it is an entry that
     * renders a question with no answer under it — and in the JSON-LD, an
     * `Answer` with an empty `text`, which is a structured-data error rather
     * than a visible one.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = ['question', 'answer'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The entries a guest should see, in order.
     *
     * @param  Builder<FaqEntry>  $query
     * @return Builder<FaqEntry>
     */
    public function scopeForPage(Builder $query): Builder
    {
        return $query->where('is_visible', true)
            ->orderBy('sort_order')
            // The tie-break every ordered table in this codebase has, so two
            // entries an operator dragged to the same position do not swap
            // between requests.
            ->orderBy('id');
    }

    /**
     * The answer, escaped, with paragraphs.
     *
     * The same renderer as #102's blocks, deliberately: there is one rule about
     * operator prose in this product and one class that implements it.
     */
    public function prose(): HtmlString
    {
        return BlockText::paragraphs($this->answer);
    }

    /**
     * The answer as one flat line, for JSON-LD.
     *
     * Google's `Answer.text` accepts a limited subset of HTML and rejects the
     * rest, and this text has no markup to begin with — so the honest encoding
     * is the plain string with its newlines collapsed. Passing `prose()` would
     * put `<p>` tags inside a JSON string that Blade then escapes again, which
     * is how structured data ends up displaying `&lt;p&gt;` in a search result.
     */
    public function plainAnswer(): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $this->answer));
    }
}
