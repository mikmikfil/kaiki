<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * Under which licence a boat carries passengers (ν. 4926/2022, 2026-09-24).
 *
 * Printed on the passenger list. The reading of the law that the competitor
 * study gave — to be confirmed by the lawyer — is that an ημερόπλοιο sells
 * seats, and a professional pleasure boat is chartered whole: so a trip sold
 * per seat on a pleasure boat is flagged on the trip form, not refused.
 */
enum VesselLicence: string implements HasLabel
{
    use HasTranslatedLabel;

    /** Ημερόπλοιο: a day passenger boat, which may sell per seat. */
    case DayCruise = 'day_cruise';

    /** Επαγγελματικό πλοίο αναψυχής: chartered whole. */
    case ProfessionalPleasure = 'professional_pleasure';

    public function sellsPerSeat(): bool
    {
        return $this === self::DayCruise;
    }
}
