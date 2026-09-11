<?php

declare(strict_types=1);

namespace App\Domain\Import\Sources;

use App\Contracts\ImportSource;
use App\Domain\Import\Data\SourceRecord;
use App\Domain\Import\Exceptions\ImportFileUnreadable;
use App\Domain\Import\Parsing\BookingsCsvReader;
use App\Domain\Import\Parsing\WxrReader;
use App\Enums\ImportRowType;
use App\Models\ImportJob;
use Illuminate\Support\Facades\Storage;

/**
 * WooCommerce + YITH Booking, from the two files an operator can download
 * without giving us a key (spec EXT-5, SAA-13).
 *
 * - the **WordPress export** (Tools → Export → Products), for categories,
 *   person types and products;
 * - YITH's **bookings CSV** (Bookings → Export), for the bookings.
 *
 * Either may be absent: a bookings-only CSV imports against products the
 * operator links to existing Kaiki trips on the review screen.
 *
 * The live REST path (`woocommerce_yith`, WP REST or Woo API keys) produces
 * the same {@see SourceRecord}s and is the named follow-up: it needs a real
 * WordPress to be built and tested against, and the machinery here does not
 * change for it.
 */
final class WooCommerceYithSource implements ImportSource
{
    public function __construct(
        private readonly WxrReader $wxr,
        private readonly BookingsCsvReader $csv,
    ) {}

    /** @return iterable<SourceRecord> */
    public function records(ImportJob $job): iterable
    {
        $connection = $job->connection ?? [];
        $disk = Storage::disk((string) ($connection['disk'] ?? 'local'));

        $wxrPath = $connection['wxr_path'] ?? null;
        $csvPath = $connection['csv_path'] ?? null;

        if (is_string($wxrPath) && $wxrPath !== '') {
            if (! $disk->exists($wxrPath)) {
                throw ImportFileUnreadable::missing();
            }

            $catalogue = $this->wxr->read($disk->path($wxrPath));

            foreach ($catalogue['categories'] as $category) {
                yield new SourceRecord(ImportRowType::Category, $category['id'], $category);
            }

            foreach ($catalogue['people_types'] as $type) {
                yield new SourceRecord(ImportRowType::PeopleType, $type['id'], $type);
            }

            foreach ($catalogue['products'] as $product) {
                yield new SourceRecord(ImportRowType::Product, (string) $product['id'], $product);
            }
        }

        if (is_string($csvPath) && $csvPath !== '') {
            if (! $disk->exists($csvPath)) {
                throw ImportFileUnreadable::missing();
            }

            foreach ($this->csv->read($disk->path($csvPath)) as $booking) {
                yield new SourceRecord(ImportRowType::Booking, (string) $booking['id'], $booking);
            }
        }
    }
}
