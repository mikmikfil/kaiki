<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hosted\Actions\BuildFaqList;
use App\Domain\Hosted\Support\BlockText;
use App\Filament\Forms\TranslatableInput;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasUuid;
use App\Observers\SearchIndexObserver;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;

/**
 * One question an operator is tired of answering on the telephone.
 *
 * ## Tenant-wide by default, product-specific by exception
 *
 * `product_id` is nullable and that is the design, not a convenience — the
 * migration's docblock has the argument. A null entry is about the operator and
 * appears on every page; a set one is about that trip and appears only there.
 *
 * ## Both locales, and where that is enforced
 *
 * §1.6 requires `el` and `en` on every translatable column. This model does not
 * implement `TranslatableSearchable`, so {@see SearchIndexObserver}
 * — which is what refuses a half-translated save elsewhere — never sees it. The
 * rule is applied at the form instead, by {@see TranslatableInput}'s
 * `TranslatableRequired`, which is also the only place an operator can see
 * *which* tab is empty.
 *
 * The consequence is deliberate and small: a row written by an import or a
 * future API with one locale is stored rather than rejected, and renders
 * through the I18N-5 fallback chain like every other translatable field. An
 * answer in the wrong language is worth more to a guest than a missing section.
 *
 * ## Nothing here renders markup
 *
 * `answer` goes through {@see BlockText}, the same one class #102 introduced
 * for the home page, which escapes first and adds paragraphs afterwards. The
 * FAQ is the second place operator prose becomes markup and it uses the first
 * one's code — an FAQ with its own renderer would be an FAQ with its own bugs.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $product_id null means the entry is about the operator
 * @property string $question translatable
 * @property string $answer translatable, plain text
 * @property int $sort_order
 * @property bool $is_published
 */
class Faq extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FaqFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasUuid;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['question', 'answer'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Entries a guest may see (§ the `is_published` criterion of #103).
     *
     * Unpublished rows stay in the panel and reach no guest surface, which is
     * why every guest-facing query goes through {@see BuildFaqList} rather than
     * through the model directly.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Entries about the operator rather than about one trip.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeTenantWide(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }

    /**
     * The operator's own order, then the tie-break the index already provides.
     *
     * Two entries dragged to the same position must not swap places between
     * requests — a page that reorders itself on refresh reads as broken even
     * when both orders are equally valid.
     *
     * @param  Builder<Faq>  $query
     * @return Builder<Faq>
     */
    public function scopeInOperatorOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Is this entry about the operator rather than about one trip? */
    public function isTenantWide(): bool
    {
        return $this->product_id === null;
    }

    /** The operator's answer, escaped, with paragraphs. */
    public function prose(): HtmlString
    {
        return BlockText::paragraphs($this->answer);
    }
}
