<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Models\AgeBand;

/**
 * What puts a passenger in a group (product owner, 2026-09-24).
 *
 * Most groups are ages — «Παιδί» is three to eleven, and checkout checks the
 * date of birth against it. Some are not: «ΑμεΑ» and «Φοιτητής» are about who
 * the passenger is, at any age. A status group has no age check at checkout;
 * what stands in for it is the crew seeing the card at boarding, when the group
 * asks for proof ({@see AgeBand::$requires_proof}).
 */
enum AgeBandKind: string
{
    use HasTranslatedLabel;

    case Age = 'age';

    case Status = 'status';
}
