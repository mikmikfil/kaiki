<?php

declare(strict_types=1);

namespace App\Domain\Import\Actions;

use App\Domain\Availability\Actions\CreateManualDeparture;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Import\Exceptions\ImportRowRefused;
use App\Domain\Import\Support\MappingDefaults;
use App\Domain\Import\Support\SourceValues;
use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\DepositType;
use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Enums\ImportStatus;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The confirmed import: every `mapped` row becomes a Kaiki row (SAA-13, SAA-15,
 * BKG-34).
 *
 * ## Row by row, and each row on its own
 *
 * Categories and person types first (they are consulted, not written), then
 * products, then the bookings that name them — {@see ImportRowType::commitOrder()}.
 * Each row is its own unit: a booking that cannot be written is marked `failed`
 * with the reason and the run carries on, because one bad row in four hundred
 * must not cost the other three hundred and ninety-nine.
 *
 * ## Resumable, and idempotent by source identifier
 *
 * Only `mapped` and `failed` rows are picked up; an `imported` row is never
 * touched again. So a run that stopped — a failed row, a worker restarted — is
 * resumed by running this again, and it writes only what is still missing.
 * Across imports too: before creating anything, the committer asks whether an
 * **earlier** import of this operator already brought in the same WooCommerce
 * id, and links to that row instead of making a second.
 *
 * ## Nothing is announced (BKG-34)
 *
 * Bookings go through {@see ImportBooking}, which dispatches no event at all —
 * no confirmation email, no SMS, no invoice, no webhook. Departures that a
 * per-seat booking needs are created through {@see CreateManualDeparture}, the
 * same path an operator's own "extra sailing" takes, so their seats are real
 * and the website stops selling what the old shop already sold.
 *
 * ## Products arrive as drafts
 *
 * An imported trip has a WordPress description, one language copied into two,
 * and prices read from YITH's meta. It is created `draft`, so it appears on
 * nobody's website until the operator has looked at it and published it
 * through CAT-15's checklist like any other trip. The importer never creates a
 * vessel, so plan limits (SAA-8) are never at stake: every product is attached
 * to a boat the operator already has.
 */
final class CommitImport
{
    /** Statuses that mean the guest has paid in full at the source. */
    private const PAID_STATUSES = ['paid', 'completed', 'processing'];

    public function __construct(
        private readonly SaveProduct $saveProduct,
        private readonly SaveAgeBands $saveBands,
        private readonly SaveRatePlan $saveRatePlan,
        private readonly CreateManualDeparture $createDeparture,
        private readonly ImportBooking $importBooking,
    ) {}

    public function __invoke(ImportJob $job): void
    {
        $job->forceFill([
            'status' => ImportStatus::Running,
            'is_dry_run' => false,
            'started_at' => $job->started_at ?? Carbon::now(),
            'error_message' => null,
        ])->save();

        $job->appendLog((string) __('imports.log.started'));
        $job->save();

        foreach (ImportRowType::commitOrder() as $type) {
            $rows = $job->rows()
                ->where('source_type', $type->value)
                ->whereIn('status', [ImportRowStatus::Mapped->value, ImportRowStatus::Failed->value])
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $this->commitRow($job, $row);
            }
        }

        $failed = $job->rows()->where('status', ImportRowStatus::Failed->value)->count();
        $imported = $job->rows()->where('status', ImportRowStatus::Imported->value)->count();

        $job->appendLog((string) __('imports.log.finished', ['imported' => $imported, 'failed' => $failed]));

        $job->forceFill([
            'status' => $failed > 0 ? ImportStatus::Failed : ImportStatus::Completed,
            'error_message' => $failed > 0 ? (string) __('imports.errors.rows_failed', ['count' => $failed]) : null,
            'finished_at' => Carbon::now(),
            'stats' => EvaluateImportRows::stats($job),
        ])->save();

        if ($failed === 0) {
            $this->forgetFiles($job);
        }
    }

    private function commitRow(ImportJob $job, ImportJobRow $row): void
    {
        $warnings = array_values(array_filter(
            $row->messages,
            static fn (array $m): bool => str_starts_with((string) ($m['key'] ?? ''), 'imports.warnings.'),
        ));

        try {
            [$targetType, $targetId, $notes] = match ($row->source_type) {
                ImportRowType::Product => $this->product($job, $row),
                ImportRowType::Booking => $this->booking($job, $row),
                default => [null, null, $row->messages],
            };

            $row->forceFill([
                'status' => ImportRowStatus::Imported,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'messages' => array_merge($notes, $row->source_type === ImportRowType::Category || $row->source_type === ImportRowType::PeopleType ? [] : $warnings),
            ])->save();
        } catch (ImportRowRefused $e) {
            $this->markFailed($row, ['key' => $e->key, 'params' => $e->params], $warnings);
        } catch (ValidationException $e) {
            $this->markFailed($row, [
                'key' => 'imports.errors.validation',
                'params' => ['detail' => implode(' ', array_map('strval', Arr::flatten($e->errors())))],
            ], $warnings);
        } catch (Throwable $e) {
            Log::error('import.row_failed', [
                'import_job_id' => $job->getKey(),
                'row_id' => $row->getKey(),
                'source_type' => $row->source_type->value,
                'source_id' => $row->source_id,
                'exception' => $e->getMessage(),
            ]);

            $this->markFailed($row, ['key' => 'imports.errors.row'], $warnings);
        }
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  list<array<string, mixed>>  $warnings
     */
    private function markFailed(ImportJobRow $row, array $error, array $warnings): void
    {
        $row->forceFill(['status' => ImportRowStatus::Failed, 'messages' => array_merge([$error], $warnings)])->save();
    }

    /** @return array{0: string, 1: int, 2: list<array<string, mixed>>} */
    private function product(ImportJob $job, ImportJobRow $row): array
    {
        $prior = $this->priorImport($job, $row, Product::class);

        if ($prior !== null) {
            return ['Product', $prior, [['key' => 'imports.notes.already_imported']]];
        }

        $choice = (array) ($job->mapping['products'][$row->source_id] ?? []);

        if (($choice['action'] ?? null) === 'link') {
            $target = (int) ($choice['target_product_id'] ?? 0);

            if ($target < 1 || ! Product::query()->whereKey($target)->exists()) {
                throw new ImportRowRefused('imports.reasons.link_target_missing');
            }

            return ['Product', $target, [['key' => 'imports.notes.linked']]];
        }

        if (($choice['action'] ?? null) !== 'create') {
            throw new ImportRowRefused('imports.reasons.operator');
        }

        return $this->createProduct($job, $row, $choice);
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return array{0: string, 1: int, 2: list<array<string, mixed>>}
     */
    private function createProduct(ImportJob $job, ImportJobRow $row, array $choice): array
    {
        $payload = $row->source_payload;
        $mode = BookingMode::tryFrom((string) ($choice['mode'] ?? '')) ?? BookingMode::PerSeat;
        $category = ProductCategory::tryFrom((string) ($choice['category'] ?? '')) ?? ProductCategory::SharedFullDay;

        $vessel = Vessel::query()->find((int) ($choice['vessel_id'] ?? $job->mapping['options']['vessel_id'] ?? 0));

        if (! $vessel instanceof Vessel) {
            throw new ImportRowRefused('imports.reasons.no_vessel');
        }

        $title = trim((string) ($payload['title'] ?? '')) ?: '#' . $row->source_id;
        $notes = [['key' => 'imports.notes.created']];

        [$duration, $durationGuessed] = $this->duration($payload);

        if ($durationGuessed) {
            $notes[] = ['key' => 'imports.warnings.duration_guessed', 'params' => ['minutes' => $duration]];
        }

        $capacity = (int) $vessel->capacity_max;
        $maxPax = min($capacity, max(1, (int) ($payload['max_persons'] ?? 0) ?: $capacity));

        $bands = $this->bands($job, $payload);

        $product = DB::transaction(function () use ($vessel, $title, $category, $mode, $payload, $duration, $maxPax, $bands): Product {
            $product = ($this->saveProduct)(new Product, [
                'vessel_id' => $vessel->getKey(),
                'slug' => $this->uniqueSlug($title),
                'category' => $category,
                'mode' => $mode,
                'title' => ['el' => $title, 'en' => $title],
                'summary' => $this->both($payload['summary'] ?? null),
                'description' => $this->both($payload['description'] ?? null),
                'duration_minutes' => $duration,
                'default_start_time' => $mode === BookingMode::PerSeat ? null : '09:00',
                'max_pax' => $maxPax,
                'min_pax' => 0,
                'min_booking_pax' => 1,
                // Seen by nobody until the operator has read it and published
                // it through the same checklist as any other trip (CAT-15).
                'status' => ProductStatus::Draft,
                'images' => [],
            ]);

            ($this->saveBands)($product, $bands);

            return $product;
        });

        $priceNote = $this->ratePlan($product, $payload);

        if ($priceNote !== null) {
            $notes[] = $priceNote;
        }

        return ['Product', (int) $product->getKey(), $notes];
    }

    /**
     * The product's age bands, from the person types it was sold with.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function bands(ImportJob $job, array $payload): array
    {
        $types = (array) ($payload['person_types'] ?? []);

        if ($types === []) {
            return [[
                'code' => 'adult',
                'label' => ['el' => (string) __('imports.default_band', [], 'el'), 'en' => (string) __('imports.default_band', [], 'en')],
                'min_age' => 0,
                'max_age' => null,
                'counts_toward_capacity' => true,
                'pricing_mode' => AgeBandPricing::Fixed->value,
                'is_base' => true,
                'sort_order' => 0,
            ]];
        }

        $bands = [];
        $index = 0;

        foreach (array_keys($types) as $typeId) {
            $map = (array) ($job->mapping['people_types'][(string) $typeId] ?? MappingDefaults::guessBand('', (string) $typeId));
            $label = trim((string) ($map['label'] ?? '')) ?: (string) ($map['age_band_code'] ?? $typeId);

            $bands[] = [
                'code' => (string) ($map['age_band_code'] ?? 'type_' . $typeId),
                'label' => ['el' => $label, 'en' => $label],
                'min_age' => (int) ($map['min_age'] ?? 0),
                'max_age' => isset($map['max_age']) && $map['max_age'] !== '' ? (int) $map['max_age'] : null,
                'counts_toward_capacity' => (bool) ($map['counts_toward_capacity'] ?? true),
                'pricing_mode' => AgeBandPricing::Fixed->value,
                'is_base' => false,
                'sort_order' => $index++,
            ];
        }

        // Exactly one base band (CAT-8): the adult one if there is one,
        // otherwise the first that takes a seat.
        $base = null;

        foreach ($bands as $i => $band) {
            if ($band['code'] === 'adult') {
                $base = $i;

                break;
            }
        }

        if ($base === null) {
            foreach ($bands as $i => $band) {
                if ($band['counts_toward_capacity']) {
                    $base = $i;

                    break;
                }
            }
        }

        $bands[$base ?? 0]['is_base'] = true;

        return $bands;
    }

    /**
     * The product's default rate plan, from YITH's costs. A trip with no price
     * in the export is still imported — as a draft with a note — rather than
     * refused: the operator adds the price, not the whole trip.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null a note for the row, or null
     */
    private function ratePlan(Product $product, array $payload): ?array
    {
        $base = SourceValues::cents(is_string($payload['price'] ?? null) ? $payload['price'] : (is_string($payload['base_cost'] ?? null) ? $payload['base_cost'] : null));

        try {
            if ($product->mode === BookingMode::PerVessel) {
                if ($base === null) {
                    return ['key' => 'imports.warnings.no_price'];
                }

                ($this->saveRatePlan)(new RatePlan, $product, [
                    'name' => (string) __('imports.rate_plan_name'),
                    'deposit_type' => DepositType::None->value,
                    'vessel_price_cents' => $base,
                    'is_active' => true,
                ]);

                return null;
            }

            $costs = (array) ($payload['person_types'] ?? []);
            $prices = [];

            /** @var AgeBand $band */
            foreach ($product->ageBands()->get() as $band) {
                $typeId = $this->typeIdForBand($costs, $band, $product);
                $cost = $typeId === null ? null : SourceValues::cents(is_string($costs[$typeId] ?? null) ? $costs[$typeId] : null);
                $price = $cost ?? ($band->counts_toward_capacity ? $base : 0);

                if ($price === null) {
                    return ['key' => 'imports.warnings.no_price'];
                }

                $prices[(int) $band->getKey()] = $price;
            }

            ($this->saveRatePlan)(new RatePlan, $product, [
                'name' => (string) __('imports.rate_plan_name'),
                'deposit_type' => DepositType::None->value,
                'is_active' => true,
            ], $prices);

            return null;
        } catch (ValidationException $e) {
            return [
                'key' => 'imports.warnings.price_not_saved',
                'params' => ['detail' => implode(' ', array_map('strval', Arr::flatten($e->errors())))],
            ];
        }
    }

    /** @param array<string, mixed> $costs */
    private function typeIdForBand(array $costs, AgeBand $band, Product $product): ?string
    {
        // Bands were created in the person types' order, so the n-th band is
        // the n-th person type.
        $ids = array_map('strval', array_keys($costs));

        return $ids[(int) $band->sort_order] ?? null;
    }

    /** @return array{0: string, 1: int, 2: list<array<string, mixed>>} */
    private function booking(ImportJob $job, ImportJobRow $row): array
    {
        $prior = $this->priorImport($job, $row, Booking::class);

        if ($prior !== null) {
            return ['Booking', $prior, [['key' => 'imports.notes.already_imported']]];
        }

        $payload = $row->source_payload;
        $productRow = $job->rows()
            ->where('source_type', ImportRowType::Product->value)
            ->where('source_id', (string) ($payload['product_id'] ?? ''))
            ->first();

        if (! $productRow instanceof ImportJobRow || $productRow->status !== ImportRowStatus::Imported || $productRow->target_id === null) {
            throw new ImportRowRefused('imports.reasons.product_not_imported', ['product' => (string) ($payload['product'] ?? $payload['product_id'] ?? '')]);
        }

        $product = Product::query()->with(['ageBands', 'vessel'])->find($productRow->target_id);

        if (! $product instanceof Product) {
            throw new ImportRowRefused('imports.reasons.link_target_missing');
        }

        $start = SourceValues::localDateTime(is_string($payload['from'] ?? null) ? $payload['from'] : null);

        if ($start === null) {
            throw new ImportRowRefused('imports.reasons.bad_date', ['value' => (string) ($payload['from'] ?? '')]);
        }

        $pax = $this->pax($job, $product, $payload);
        $total = SourceValues::cents(is_string($payload['total'] ?? null) ? $payload['total'] : null) ?? 0;
        $status = mb_strtolower((string) ($payload['status'] ?? ''));
        $paid = in_array($status, self::PAID_STATUSES, true) ? $total : 0;

        $notes = [];
        $sourceData = [
            'system' => 'woocommerce_yith',
            'booking_id' => $row->source_id,
            'order_id' => $payload['order_id'] ?? null,
            'status' => $status,
            'product' => $payload['product'] ?? null,
        ];

        $draft = fn (Carbon $date, ?string $startTime): BookingDraftData => new BookingDraftData(
            product: $product,
            date: $date,
            guestName: (string) ($payload['customer'] ?? '') !== '' ? (string) $payload['customer'] : (string) __('imports.unnamed_guest'),
            guestEmail: (string) ($payload['email'] ?? ''),
            guestPhone: is_string($payload['phone'] ?? null) ? $payload['phone'] : null,
            locale: (string) (Tenancy::current()->default_locale ?? 'el'),
            source: BookingSource::Import,
            paxByCode: $pax,
            startTime: $startTime,
        );

        if ($product->mode === BookingMode::PerSeat) {
            $departure = $this->departure($product, $start['date'], $start['time'], $notes);
            $booking = ($this->importBooking)($draft($departure->local_date->copy(), null), $total, $paid, $departure, $sourceData);
        } else {
            $instant = LocalDateTimeResolver::resolveForTenant($start['date'], $start['time'])->instantOrFail();
            $booking = ($this->importBooking)($draft($instant, $start['time']), $total, $paid, null, $sourceData);
        }

        $notes[] = ['key' => 'imports.notes.booking_created', 'params' => ['reference' => $booking->reference]];

        return ['Booking', (int) $booking->getKey(), $notes];
    }

    /**
     * The sailing a per-seat booking is on — found, or created as a manual
     * departure so its seats are real.
     *
     * @param  list<array<string, mixed>>  $notes
     */
    private function departure(Product $product, string $date, string $time, array &$notes): Departure
    {
        $existing = Departure::query()
            ->where('product_id', $product->getKey())
            ->whereDate('local_date', $date)
            ->get()
            ->first(static fn (Departure $d): bool => substr((string) $d->local_time, 0, 5) === $time);

        if ($existing instanceof Departure) {
            return $existing;
        }

        $notes[] = ['key' => 'imports.notes.departure_created', 'params' => ['date' => $date, 'time' => $time]];

        // `confirmed: true` accepts a clash with another trip on the same boat:
        // the old shop already sold both, and the departure list is where the
        // operator sees it. A clash with this same trip is still refused.
        return ($this->createDeparture)($product, ['local_date' => $date, 'local_time' => $time], confirmed: true);
    }

    /**
     * Passengers per band code, from "Ενήλικας: 2 | Παιδί: 1" or a head count.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function pax(ImportJob $job, Product $product, array $payload): array
    {
        $codes = $product->ageBands->pluck('code')->map(static fn ($c): string => (string) $c)->all();
        $base = (string) ($product->ageBands->firstWhere('is_base', true)->code ?? ($codes[0] ?? 'adult'));

        $byName = [];

        foreach ((array) ($job->mapping['people_types'] ?? []) as $map) {
            if (is_array($map) && isset($map['label'], $map['age_band_code'])) {
                $byName[SourceValues::normaliseName((string) $map['label'])] = (string) $map['age_band_code'];
            }
        }

        $pax = [];

        foreach (SourceValues::personCounts(is_string($payload['person_types'] ?? null) ? $payload['person_types'] : null) as $name => $count) {
            $code = $byName[SourceValues::normaliseName($name)] ?? $base;
            $code = in_array($code, $codes, true) ? $code : $base;
            $pax[$code] = ($pax[$code] ?? 0) + $count;
        }

        if ($pax === []) {
            $persons = (int) ($payload['persons'] ?? 0);

            if ($persons < 1) {
                throw new ImportRowRefused('imports.reasons.no_pax');
            }

            $pax[$base] = $persons;
        }

        return $pax;
    }

    /**
     * An earlier import's target for the same source record, if it still exists.
     *
     * @param  class-string<Product|Booking>  $model
     */
    private function priorImport(ImportJob $job, ImportJobRow $row, string $model): ?int
    {
        $prior = ImportJobRow::query()
            ->where('source_type', $row->source_type->value)
            ->where('source_id', $row->source_id)
            ->where('status', ImportRowStatus::Imported->value)
            ->whereNotNull('target_id')
            ->where('import_job_id', '!=', $job->getKey())
            ->orderByDesc('id')
            ->first();

        if (! $prior instanceof ImportJobRow || $prior->target_id === null) {
            return null;
        }

        return $model::query()->whereKey($prior->target_id)->exists() ? $prior->target_id : null;
    }

    /**
     * Minutes, and whether they were guessed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: bool}
     */
    private function duration(array $payload): array
    {
        $value = (int) ($payload['duration'] ?? 0);

        if ($value < 1) {
            return [240, true];
        }

        $minutes = match ((string) ($payload['duration_unit'] ?? 'hour')) {
            'minute', 'minutes' => $value,
            'day', 'days' => $value * 1440,
            default => $value * 60,
        };

        return [max(15, min(1440, $minutes)), false];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title, '-', 'el') ?: 'trip';
        $base = mb_substr($base, 0, 110);
        $slug = $base;
        $n = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /** @return array{el: string, en: string}|null */
    private function both(mixed $value): ?array
    {
        return is_string($value) && trim($value) !== '' ? ['el' => trim($value), 'en' => trim($value)] : null;
    }

    /** The uploaded files hold every past customer's details; once imported, they go. */
    private function forgetFiles(ImportJob $job): void
    {
        $connection = $job->connection ?? [];
        $disk = Storage::disk((string) ($connection['disk'] ?? 'local'));

        foreach (['wxr_path', 'csv_path'] as $key) {
            $path = $connection[$key] ?? null;

            if (is_string($path) && $path !== '') {
                $disk->delete($path);
            }
        }

        $job->appendLog((string) __('imports.log.files_deleted'));
        $job->save();
    }
}
