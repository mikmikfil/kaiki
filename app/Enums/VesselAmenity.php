<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What is on board, for the spec sheet (`docs/data-model.md` §3.9).
 *
 * §3.9 requires that `vessels.specs.amenities` values "come from a fixed list
 * with EL/EN labels in lang files, so the widget can show icons". An enum is
 * that fixed list: free text here would mean the widget could not map a value
 * to an icon, and "WC" / "wc" / "Τουαλέτα" would be three amenities.
 *
 * The rest of `specs` stays free-form and unknown keys are preserved, so this
 * constrains only the one field the UI actually renders from.
 */
enum VesselAmenity: string
{
    use HasTranslatedLabel;

    case ShadeCanopy = 'shade_canopy';
    case SoundSystem = 'sound_system';
    case Fridge = 'fridge';
    case SnorkellingGear = 'snorkelling_gear';
    case Wc = 'wc';
    case SunDeck = 'sun_deck';
}
