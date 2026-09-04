<?php

declare(strict_types=1);

namespace App\Domain\Branding\Actions;

use App\Domain\Branding\Support\CssSanitizer;
use App\Models\BrandProfile;

/**
 * Save a brand, and record what its colours score (spec BRD-2, BRD-5).
 *
 * The Filament page calls this and so will `PATCH /api/v1/branding` when it
 * arrives, which is the point: the contrast recomputation and the CSS
 * sanitisation are not form behaviour, they are what saving a brand *means*.
 * A panel that recomputed the warnings in its `mutateFormDataBeforeSave` would
 * be a panel whose numbers are right and an API whose numbers are stale, and
 * the badge would then disagree with the palette next to it.
 *
 * ## The contrast check warns; it never blocks
 *
 * BRD-5 is explicit, and it is the reason this returns the profile rather than
 * a result object with an `ok` flag. An operator whose colours have been on
 * their boats for fifteen years is not going to be told by a booking system
 * that their brand is wrong — a save that fails on a ratio is a save they work
 * around by not saving. The number is recorded and shown; the decision stays
 * theirs.
 *
 * ## `custom_css` is not sanitised here
 *
 * It is sanitised by {@see BrandProfile::customCss()} on the way in *and* on
 * the way out, so a stylesheet written by an import that never touched this
 * Action is as safe as one typed into the form. Doing it here as well would be
 * a second place to keep in step with {@see CssSanitizer}.
 */
final class UpdateBrandProfile
{
    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     */
    public function __invoke(BrandProfile $profile, array $attributes): BrandProfile
    {
        $profile->fill($attributes);

        // Computed from the colours **after** the fill, so the warnings describe
        // the palette being saved and not the one being replaced. Off-by-one in
        // this ordering is invisible until an operator fixes a failing colour
        // and the badge keeps saying it failed.
        $profile->contrast_warnings = $profile->evaluateContrast();

        $profile->save();

        return $profile;
    }
}
