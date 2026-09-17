<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who a trip question is asked of (product owner, 2026-09-17).
 *
 * Per person — asked in every passenger's panel at checkout, answered once for
 * each, and shown beside that name on the manifest and the boarding list — or
 * per booking, asked once of whoever is paying.
 */
enum TripQuestionScope: string
{
    use HasTranslatedLabel;

    case PerPerson = 'per_person';
    case PerBooking = 'per_booking';
}
