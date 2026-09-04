<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four uploadable files on a brand profile (`docs/data-model.md` §2.2,
 * spec BRD-1, BRD-7).
 *
 * Not a database column — this names the *slots*, so that the upload Action,
 * the Filament page and `media:rebuild` agree about which column a file lands
 * in and which variant widths it gets. Three places that each spell
 * `logo_light_path` and `'logo'` as literals is three places to get one of them
 * wrong, and the failure is silent: the file uploads, the variants are
 * generated at the wrong sizes, and nobody notices until a logo is blurry in an
 * email.
 *
 * **Deliberately without {@see Concerns\HasTranslatedLabel}.** Every label an
 * operator reads for these fields is a form label with its own help text in
 * `lang/*\/branding.php`; a second set of one-word labels in `enums.php` would
 * be two sources for the same sentence and the coverage gate would then require
 * both to be filled in.
 */
enum BrandAsset: string
{
    case LogoLight = 'logo_light';
    case LogoDark = 'logo_dark';
    case Favicon = 'favicon';
    case EmailHeader = 'email_header';

    /**
     * The `brand_profiles` column this slot writes.
     *
     * A `match`, not `$this->value . '_path'`. Three of the four columns follow
     * that pattern and the fourth is `email_header_image_path`, so the derived
     * form silently produces a column that does not exist — and `getAttribute()`
     * on a missing column returns null rather than throwing, which means the
     * upload succeeds, the file lands on the disk, and the email header is
     * simply never set. Found by the test that asserts every case names a real
     * column.
     */
    public function column(): string
    {
        return match ($this) {
            self::LogoLight => 'logo_light_path',
            self::LogoDark => 'logo_dark_path',
            self::Favicon => 'favicon_path',
            self::EmailHeader => 'email_header_image_path',
        };
    }

    /**
     * The key into `config('kaiki.branding.uploads.variants')`.
     *
     * Both logos share the `logo` widths: they are the same image at the same
     * sizes in the same places, differing only in which background they are
     * drawn on.
     */
    public function variantKey(): string
    {
        return match ($this) {
            self::LogoLight, self::LogoDark => 'logo',
            self::Favicon => 'favicon',
            self::EmailHeader => 'email_header',
        };
    }

    /**
     * The widths this slot's variants are generated at, in pixels.
     *
     * @return list<int>
     */
    public function variantWidths(): array
    {
        /** @var list<int> $widths */
        $widths = config('kaiki.branding.uploads.variants.' . $this->variantKey(), []);

        return $widths;
    }

    /**
     * The storage directory for a tenant's copy of this slot.
     *
     * Tenant-segmented so that a mistaken `Storage::deleteDirectory` or a
     * support request to "send me everything for this operator" is one path,
     * and so a listing of the disk never mixes two operators' files.
     */
    public function directory(int $tenantId): string
    {
        return "brand/{$tenantId}/{$this->value}";
    }
}
