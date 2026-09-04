<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What kind of boat this is (spec CAT-1, `docs/data-model.md` §2.3).
 *
 * CAT-1 is marked **(FIXED)** and lists exactly these five. It is not an
 * open vocabulary: the widget renders an icon per type and the hosted page
 * filters on them, so a sixth case is a product decision with UI attached,
 * not a value somebody adds because an operator asked.
 *
 * `traditional_kaiki` is the boat the product is named after — the wooden
 * Aegean day-trip caique. It stays a Latin key with Greek and English labels in
 * lang files, like every other enum value in the schema.
 */
enum VesselType: string
{
    use HasTranslatedLabel;

    case Catamaran = 'catamaran';
    case SailingYacht = 'sailing_yacht';
    case Motor = 'motor';
    case Rib = 'rib';
    case TraditionalKaiki = 'traditional_kaiki';
}
