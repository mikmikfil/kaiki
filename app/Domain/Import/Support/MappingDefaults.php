<?php

declare(strict_types=1);

namespace App\Domain\Import\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\BookingMode;
use App\Enums\ImportRowType;
use App\Enums\ProductCategory;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The mapping the review screen opens with (`import_jobs.mapping`, §3.7).
 *
 * Every guess here is shown to the operator before anything is written
 * (SAA-14), and every one is conservative:
 *
 * - a product whose title matches an existing Kaiki trip is **linked** rather
 *   than duplicated; anything that is not a YITH booking product is **skipped**;
 * - person types are read from their names — «Ενήλικας», «Παιδί», «Βρέφος» —
 *   into the three usual bands, with the ages an operator most often uses;
 * - only **upcoming** bookings are imported (§3.7 `bookings.import_from`), in
 *   the statuses that mean a trip is still going ahead.
 *
 * On a re-analysis the operator's own choices win: a default fills only what
 * the saved mapping does not already say.
 */
final class MappingDefaults
{
    /** YITH / WooCommerce statuses that mean "the trip is still on". */
    public const DEFAULT_STATUSES = ['paid', 'completed', 'processing', 'confirmed', 'unpaid', 'pending-confirm', 'on-hold'];

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    public static function for(ImportJob $job, array $saved = []): array
    {
        /** @var Collection<int, ImportJobRow> $rows */
        $rows = $job->rows()->get();

        $vessels = Vessel::query()->orderBy('name')->get();
        $defaultVessel = $vessels->count() === 1 ? (int) $vessels->first()?->getKey() : null;

        $categories = [];

        foreach ($rows->where('source_type', ImportRowType::Category) as $row) {
            $categories[$row->source_id] = $saved['categories'][$row->source_id]
                ?? self::guessCategory((string) ($row->source_payload['name'] ?? ''))->value;
        }

        $peopleTypes = [];

        foreach ($rows->where('source_type', ImportRowType::PeopleType) as $row) {
            $peopleTypes[$row->source_id] = $saved['people_types'][$row->source_id]
                ?? self::guessBand((string) ($row->source_payload['title'] ?? ''), $row->source_id);
        }

        $existing = Product::query()->get();
        $products = [];

        foreach ($rows->where('source_type', ImportRowType::Product) as $row) {
            $products[$row->source_id] = $saved['products'][$row->source_id]
                ?? self::guessProduct($row->source_payload, $categories, $existing, $defaultVessel);
        }

        return [
            'version' => 1,
            'products' => $products,
            'people_types' => $peopleTypes,
            'categories' => $categories,
            'bookings' => [
                'import_from' => $saved['bookings']['import_from']
                    ?? Carbon::now(LocalDateTimeResolver::timezone())->toDateString(),
                'statuses' => $saved['bookings']['statuses'] ?? self::DEFAULT_STATUSES,
                'default_status' => 'confirmed',
            ],
            'options' => [
                'vessel_id' => $saved['options']['vessel_id'] ?? $defaultVessel,
                'import_customers' => true,
                'import_media' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $categories
     * @param  Collection<int, Product>  $existing
     * @return array<string, mixed>
     */
    private static function guessProduct(array $payload, array $categories, Collection $existing, ?int $vesselId): array
    {
        if (($payload['is_booking'] ?? false) !== true) {
            return ['action' => 'skip', 'reason' => 'not_booking'];
        }

        $title = SourceValues::normaliseName((string) ($payload['title'] ?? ''));

        $match = $existing->first(static function (Product $product) use ($title): bool {
            foreach ((array) $product->getTranslations('title') as $value) {
                if (is_string($value) && SourceValues::normaliseName($value) === $title) {
                    return true;
                }
            }

            return false;
        });

        if ($match instanceof Product) {
            return ['action' => 'link', 'target_product_id' => (int) $match->getKey()];
        }

        $mode = ($payload['person_types'] ?? []) === [] ? BookingMode::PerVessel : BookingMode::PerSeat;

        $category = null;

        foreach ((array) ($payload['category_ids'] ?? []) as $id) {
            if (isset($categories[(string) $id])) {
                $category = $categories[(string) $id];

                break;
            }
        }

        $category ??= $mode === BookingMode::PerVessel
            ? ProductCategory::PrivateFullDay->value
            : ProductCategory::SharedFullDay->value;

        return [
            'action' => 'create',
            'mode' => $mode->value,
            'category' => $category,
            'vessel_id' => $vesselId,
        ];
    }

    public static function guessCategory(string $name): ProductCategory
    {
        $name = SourceValues::normaliseName($name);

        return match (true) {
            str_contains($name, 'ηλιοβασ') || str_contains($name, 'sunset') => ProductCategory::Sunset,
            str_contains($name, 'ιδιωτ') || str_contains($name, 'private') || str_contains($name, 'ναύλ') => ProductCategory::PrivateFullDay,
            str_contains($name, 'μισ') || str_contains($name, 'half') => ProductCategory::SharedHalfDay,
            default => ProductCategory::SharedFullDay,
        };
    }

    /** @return array<string, mixed> */
    public static function guessBand(string $title, string $sourceId): array
    {
        $name = SourceValues::normaliseName($title);

        return match (true) {
            str_contains($name, 'βρέφ') || str_contains($name, 'βρεφ') || str_contains($name, 'μωρ')
                || str_contains($name, 'infant') || str_contains($name, 'baby') => [
                    'label' => $title, 'age_band_code' => 'infant', 'min_age' => 0, 'max_age' => 2, 'counts_toward_capacity' => false,
                ],
            str_contains($name, 'παιδ') || str_contains($name, 'child') || str_contains($name, 'kid') => [
                'label' => $title, 'age_band_code' => 'child', 'min_age' => 3, 'max_age' => 11, 'counts_toward_capacity' => true,
            ],
            str_contains($name, 'ενήλικ') || str_contains($name, 'ενηλικ') || str_contains($name, 'adult') => [
                'label' => $title, 'age_band_code' => 'adult', 'min_age' => 12, 'max_age' => null, 'counts_toward_capacity' => true,
            ],
            default => [
                'label' => $title, 'age_band_code' => 'type_' . $sourceId, 'min_age' => null, 'max_age' => null, 'counts_toward_capacity' => true,
            ],
        };
    }
}
