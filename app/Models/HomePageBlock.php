<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Hosted\Support\BlockSettings;
use App\Domain\Hosted\Support\BlockText;
use App\Enums\HomeBlockType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasUuid;
use App\Rules\TranslatableRequired;
use Database\Factories\HomePageBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * One block on an operator's home page.
 *
 * The page is the ordered list of these rows; there is no `pages` table,
 * because there is one page. When #104's product pages want blocks of their own
 * they will need a `page` column and a decision about whether a block can be
 * shared, and neither is worth guessing at now.
 *
 * ## Nothing here renders markup
 *
 * `heading` is escaped by Blade like any other string. `body` goes through
 * {@see BlockText}, which is the only class in the product that produces markup
 * from operator input, and it produces exactly two elements. See
 * {@see HomeBlockType} for why there is no rich-text block and why that is a
 * decision rather than an omission.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property HomeBlockType $type
 * @property int $sort_order
 * @property bool $is_visible
 * @property string|null $heading translatable
 * @property string|null $body translatable, plain text
 * @property string|null $image_path
 * @property array<int, array<string, mixed>>|null $images
 * @property array<string, mixed>|null $settings
 */
class HomePageBlock extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HomePageBlockFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasUuid;

    protected $guarded = [];

    /**
     * Neither is required, and that is in the migration's docblock: a gallery
     * has no prose and a trips block often has no heading.
     *
     * @var list<string>
     */
    public array $translatable = ['heading', 'body'];

    /**
     * Empty on purpose.
     *
     * `SearchIndexObserver` refuses a translatable column that is missing a
     * locale, which is right for a product title and wrong here — the column is
     * nullable, and a block with no heading in either language is an ordinary
     * gallery rather than a mistake. What must not happen is a heading in one
     * language only, and {@see TranslatableRequired} handles that at
     * the form, where the operator can see which tab is empty.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => HomeBlockType::class,
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'images' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * The blocks of the current tenant's page, in order, ready to render.
     *
     * @param  Builder<HomePageBlock>  $query
     * @return Builder<HomePageBlock>
     */
    public function scopeForPage(Builder $query): Builder
    {
        return $query->where('is_visible', true)
            ->orderBy('sort_order')
            // A tie-break the index already provides, so two blocks an operator
            // dragged to the same position do not swap places between requests
            // and make the page look like it is flickering.
            ->orderBy('id');
    }

    /**
     * This block's settings, filled in and reconciled.
     *
     * Always read through here rather than off `$this->settings` — a row
     * written before a setting existed has no key for it, and
     * {@see BlockSettings} is what makes that a default rather than a null in a
     * template.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return BlockSettings::normalise($this->type, $this->settings);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    /** The operator's prose, escaped, with paragraphs. */
    public function prose(): HtmlString
    {
        return BlockText::paragraphs($this->body);
    }

    /**
     * The gallery's images, each with alt text in the current locale.
     *
     * Alt text is **required per image** by A11Y and is stored per locale, so a
     * gallery on the Greek page does not describe its photographs in English.
     * An image with no alt in this locale falls back to the other one rather
     * than rendering `alt=""` — a wrong-language description is worth more to a
     * screen reader than none.
     *
     * @return list<array{path: string, alt: string}>
     */
    public function galleryImages(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $images = [];

        foreach ((array) $this->images as $image) {
            if (! is_array($image) || ! is_string($image['path'] ?? null) || $image['path'] === '') {
                continue;
            }

            $alt = is_array($image['alt'] ?? null) ? $image['alt'] : [];

            $images[] = [
                'path' => $image['path'],
                'alt' => (string) ($alt[$locale] ?? $alt['el'] ?? $alt['en'] ?? ''),
            ];
        }

        return $images;
    }
}
