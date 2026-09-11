<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The kinds of source record an import reads (`import_job_rows.source_type`).
 *
 * Declared in commit order: categories and person types are consulted by
 * products, and products must exist before the bookings that name them.
 */
enum ImportRowType: string
{
    use HasTranslatedLabel;

    case Category = 'category';
    case PeopleType = 'people_type';
    case Product = 'product';
    case Customer = 'customer';
    case Booking = 'booking';

    /** @return list<self> */
    public static function commitOrder(): array
    {
        return [self::Category, self::PeopleType, self::Product, self::Booking];
    }
}
