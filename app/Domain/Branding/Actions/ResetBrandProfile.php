<?php

declare(strict_types=1);

namespace App\Domain\Branding\Actions;

use App\Enums\BrandAsset;
use App\Models\BrandProfile;
use Illuminate\Support\Arr;

/**
 * Put a brand back to the platform defaults (spec BRD-4, `docs/data-model.md` §2.2).
 *
 * §2.2 is explicit that this **overwrites the row** rather than deleting it,
 * which is why `brand_profiles` has no soft deletes: BRD-3 promises a profile
 * always exists, and a deleted-then-restored one is a window in which every
 * booking page renders unbranded.
 *
 * ## What reset does and does not clear
 *
 * Colours, font, radius and theme go back to the platform defaults, and the
 * contrast warnings are recomputed against them rather than emptied — the
 * default palette has a real score and the badge should show it.
 *
 * The four uploads are cleared too, and their files deleted: an operator
 * resetting their brand is asking to look like the platform, and a reset that
 * left their old logo on every page would be the one thing they meant.
 *
 * **`email_footer_text` and `social_links` survive.** They are not brand
 * *styling* — a mail footer is often a legal address and a VAT number, and the
 * social links are the operator's accounts. Neither is something "reset to the
 * platform look" should silently delete, and neither is recoverable from a
 * default.
 */
final class ResetBrandProfile
{
    public function __construct(
        private readonly UpdateBrandProfile $update,
        private readonly UploadBrandAsset $assets,
    ) {}

    public function __invoke(BrandProfile $profile): BrandProfile
    {
        foreach (BrandAsset::cases() as $asset) {
            if ($profile->getAttribute($asset->column()) !== null) {
                $this->assets->remove($profile, $asset);
            }
        }

        // Through the update Action rather than a `fill()` here, so the
        // contrast warnings for the default palette are written by the same
        // code that writes them for every other save.
        //
        // `social_links` is excluded by name. `platformDefaults()` is what a
        // *new* profile starts as, where an empty link set is correct; a reset
        // is something an existing operator asks for, and it would take their
        // Instagram account with it. `contrast_warnings` is left in only
        // because the Action recomputes it a line later.
        return ($this->update)($profile, Arr::except(BrandProfile::platformDefaults(), ['social_links']));
    }
}
