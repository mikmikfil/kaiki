<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where an import's records came from (`import_jobs.source`, SAA-13).
 *
 * `wxr` is the file path this round builds: a WordPress export (WXR) for the
 * catalogue plus YITH Booking's bookings CSV. `woocommerce_yith` is the live
 * REST path, which needs a real WordPress to be built against and is named
 * here so the column already has its value. `csv` is a bookings-only upload
 * against products that already exist in Kaiki.
 */
enum ImportSource: string
{
    use HasTranslatedLabel;

    case WoocommerceYith = 'woocommerce_yith';
    case Csv = 'csv';
    case Wxr = 'wxr';
}
