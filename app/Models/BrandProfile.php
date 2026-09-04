<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Branding\Actions\UpdateBrandProfile;
use App\Domain\Branding\Support\ContrastChecker;
use App\Domain\Branding\Support\CssSanitizer;
use App\Enums\FontSource;
use App\Enums\WidgetTheme;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Observers\SearchIndexObserver;
use App\Observers\TenantObserver;
use Database\Factories\BrandProfileFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The operator's brand — exactly one row per tenant (`docs/data-model.md` §2.2,
 * spec BRD-1, BRD-3).
 *
 * Every branded surface reads this: the widget on a third-party site, the
 * hosted booking page, the confirmation email, the ticket PDF. BRD-3's promise
 * is that the row **always exists**, which is why {@see TenantObserver}
 * creates it with the tenant and why there are no soft deletes — a deleted
 * brand profile is a tenant rendering unbranded, and no code path should have
 * to ask whether it got one back.
 *
 * ## No uuid, and no `find()` by anything but the tenant
 *
 * Nothing addresses this row from outside. `GET /api/v1/branding` (BRD-6, #35)
 * is authenticated as the operator and returns *the* profile; it takes no
 * identifier at all. The unique index on `tenant_id` is both the 1:1 constraint
 * and the index that read uses.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $logo_light_path
 * @property string|null $logo_dark_path
 * @property string|null $favicon_path
 * @property string|null $email_header_image_path
 * @property string $color_primary
 * @property string $color_secondary
 * @property string $color_accent
 * @property string $color_background
 * @property string $color_text
 * @property string $font_family
 * @property FontSource $font_source
 * @property int $button_radius_px
 * @property WidgetTheme $widget_theme
 * @property string|null $email_footer_text
 * @property array<string, string> $social_links
 * @property string|null $custom_css sanitised on read as well as write
 * @property array<string, array{ratio: float, threshold: float, passes: bool}> $contrast_warnings
 */
class BrandProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BrandProfileFactory> */
    use HasFactory;

    use HasKaikiTranslations;

    protected $guarded = [];

    /**
     * Translatable (§1.6, §3.14) but deliberately **not searchable**, so this
     * model takes `HasKaikiTranslations` for the I18N-5 fallback and not
     * `HasTranslatableSearch`.
     *
     * Nothing searches or sorts a mail footer — there is one row per tenant and
     * it is reached by its tenant — so the companion columns would be two
     * columns maintained for no reader. Skipping the trait also skips
     * {@see SearchIndexObserver}'s both-locales rule, which is
     * correct here: §1.6's rule applies to a translatable field that has been
     * filled in, not to one deliberately left empty, and an operator with
     * nothing to say in a mail footer is ordinary. The form still asks for both
     * locales when one is typed — see the branding page.
     *
     * @var list<string>
     */
    public array $translatable = ['email_footer_text'];

    /**
     * The two JSON columns are `NOT NULL` with **no database default**, because
     * MySQL 8 refuses a literal `DEFAULT` on a JSON column and the expression
     * form has no SQLite equivalent (ENV-12 forbids branching on the driver).
     * The default therefore lives here, as it does for `vessels.specs`.
     *
     * `contrast_warnings` starts empty rather than computed: a row that has
     * never been saved through the form has never been checked, and an empty
     * object says exactly that. {@see UpdateBrandProfile}
     * fills it on the first save.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'social_links' => '{}',
        'contrast_warnings' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'font_source' => FontSource::class,
            'widget_theme' => WidgetTheme::class,
            'button_radius_px' => 'integer',
            // `social_links` and `contrast_warnings` are **not** here: both are
            // JSON *objects* in the data model, and the `array` cast writes an
            // empty PHP array as `[]`. See the two accessors below.
        ];
    }

    /**
     * `{}` when empty, not `[]` (`docs/data-model.md` §2.2, §3.10).
     *
     * Laravel's `array` cast round-trips correctly in PHP — `json_decode('[]')`
     * and `json_decode('{}')` are both `[]` — so this looks like pedantry until
     * something outside PHP reads the column. §2.2 documents the default as
     * `{}` and §3.10 documents a keyed object; a column holding `[]` is a
     * column whose shape changes the moment an operator adds their first link,
     * which is the sort of thing a widget's TypeScript types notice and a PHP
     * test never does.
     *
     * `TSet` is the type an assignment may carry, so both parameters are the
     * array shape: `$profile->social_links = [...]` is what callers write, and
     * the string is only what lands in the column.
     *
     * @return Attribute<array<string, string>, array<string, string>>
     */
    protected function socialLinks(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): array => self::decodeMap($value),
            set: static fn (array $value): string => self::encodeMap($value),
        );
    }

    /**
     * The same shape rule as {@see socialLinks()}.
     *
     * @return Attribute<array<string, array{ratio: float, threshold: float, passes: bool}>, array<string, array{ratio: float, threshold: float, passes: bool}>>
     */
    protected function contrastWarnings(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): array => self::decodeMap($value),
            set: static fn (array $value): string => self::encodeMap($value),
        );
    }

    /** @return array<string, mixed> */
    private static function decodeMap(?string $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $value */
    private static function encodeMap(array $value): string
    {
        // `JSON_FORCE_OBJECT` rather than a cast to stdClass, because it also
        // covers the case that actually reaches production: a list-shaped array
        // arriving from an import, which would otherwise be stored as an array
        // and read back as one.
        return (string) json_encode($value, $value === [] ? JSON_FORCE_OBJECT : 0);
    }

    /**
     * Sanitised on **read** as well as on write (`docs/data-model.md` §2.2).
     *
     * The column keeps what the operator typed; this decides what anything
     * rendering it gets. That asymmetry is the whole point: tightening
     * {@see CssSanitizer} later protects every stylesheet already in the
     * database, in one file, with no data migration over operators' CSS — and
     * without silently rewriting what they will see next time they open the
     * editor as though they had written it that way.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function customCss(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?string => CssSanitizer::sanitize($value),
            set: static fn (?string $value): ?string => CssSanitizer::sanitize($value),
        );
    }

    /** The raw column, exactly as stored — for the editor, and for nothing else. */
    public function rawCustomCss(): ?string
    {
        $value = $this->getAttributes()['custom_css'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The platform defaults BRD-3 writes, from `config('kaiki.branding')`.
     *
     * These are also the column defaults in the migration — the column default
     * protects a row written around the observer by an import or a raw insert;
     * this is what the observer writes. `BrandProfileDefaultsTest` asserts the
     * two agree by reading the schema, because two sources that quietly
     * disagree mean a tenant's brand depends on which code path created it.
     *
     * @return array<string, mixed>
     */
    public static function platformDefaults(): array
    {
        /** @var array<string, string> $colors */
        $colors = config('kaiki.branding.defaults.colors');

        return [
            'color_primary' => $colors['primary'],
            'color_secondary' => $colors['secondary'],
            'color_accent' => $colors['accent'],
            'color_background' => $colors['background'],
            'color_text' => $colors['text'],
            'font_family' => config('kaiki.branding.defaults.font_family'),
            'font_source' => config('kaiki.branding.defaults.font_source'),
            'button_radius_px' => config('kaiki.branding.defaults.button_radius_px'),
            'widget_theme' => config('kaiki.branding.defaults.widget_theme'),
            'social_links' => [],
            'contrast_warnings' => [],
        ];
    }

    /**
     * The WCAG results for the colours currently on this row (BRD-5).
     *
     * Computed rather than read, so the caller can compare what the palette
     * *would* score against what `contrast_warnings` says it scored when it was
     * last saved.
     *
     * @return array<string, array{ratio: float, threshold: float, passes: bool}>
     */
    public function evaluateContrast(): array
    {
        return ContrastChecker::evaluate(
            $this->color_text,
            $this->color_background,
            $this->color_primary,
        );
    }

    /** Does any stored contrast result fall below its WCAG AA threshold? */
    public function hasContrastWarnings(): bool
    {
        foreach ($this->contrast_warnings as $result) {
            if ($result['passes'] === false) {
                return true;
            }
        }

        return false;
    }
}
