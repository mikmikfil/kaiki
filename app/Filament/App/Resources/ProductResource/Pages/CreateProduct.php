<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveExtra;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Domain\Pricing\Actions\SavePriceTable;
use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\RelationManagers\ExtrasRelationManager;
use App\Filament\App\Support\ScheduleConflictNotice;
use App\Filament\Forms\MoneyInput;
use App\Models\Extra;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\ScheduleRule;
use App\Models\Season;
use App\Models\Vessel;
use App\Support\Tenancy;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The first trip, in five steps (product owner, 2026-09-22, direction Α).
 *
 * ## Why creating is not editing
 *
 * *«Μέσα στην εκδρομή παραμένει μπέρδεμα για κάποιον που το βλέπει πρώτη
 * φορά»*, and walking the form end to end showed exactly why: the edit page's
 * five tabs are right for somebody who knows what they are looking for and
 * wrong for somebody meeting a trip for the first time. Half of them are
 * inert until the trip exists — «Τιμές» is empty until a booking mode is
 * chosen, the price grid needs a saved price list, the schedule needs a saved
 * trip — so a first-timer reads a form where most of what they see does not
 * work yet.
 *
 * So: **steps to create, tabs to edit**. Each step asks for one thing, in the
 * order the answers are needed, and nothing that is not needed yet is on
 * screen. The tabs are untouched and take over the moment the trip exists.
 *
 * ## The steps make a trip that actually sells
 *
 * That is the test this page is written against: an operator who answers every
 * step has a published trip with departures on the calendar, not a draft with
 * a list of what is still missing. So the wizard collects, in one submit, what
 * the publish checklist asks for — boat and titles (1), meeting point (2), age
 * bands with prices (3), cancellation policy (5) — plus the things the
 * checklist does not ask for and a trip cannot sell without: its **price
 * lists**, its **timetable**, and the page a guest actually reads (4).
 *
 * *«Όταν πρωτοφτιάχνω μια εκδρομή πρέπει να μπουν μέσα όλα»* (product owner,
 * 2026-09-22). So the submit is no longer one price list and one schedule rule:
 * it is a list per period, a rule per day-set per time, and every text and
 * photograph the trip page has room for.
 *
 * Nothing here is a second implementation of those: {@see SaveRatePlan},
 * {@see SaveScheduleRule} and the same {@see ConsumesAgeBands} the edit page
 * uses do the writing, in the one order that works.
 */
class CreateProduct extends CreateRecord
{
    use ConsumesAgeBands;
    use HasTripWizard;

    protected static string $resource = ProductResource::class;

    /**
     * No «create another»: it made a second click on a slow connection look
     * like the first one had not worked.
     */
    protected static bool $canCreateAnother = false;

    /** «Νέα εκδρομή», not Filament's «Νέα εγγραφή: Εκδρομή» (2026-09-24). */
    public function getTitle(): string
    {
        return __('catalog.product.wizard.title');
    }

    /** Where the wizard lands: the trip's own page, on the tab it left off. */
    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * Everything the five steps promised, in the one order that works.
     *
     * The product first, because a band needs a `product_id`; the bands before
     * the price list, because a price row needs an `age_band_id`; the schedule
     * last, because a rule with no trip is nothing. Publishing is a second save
     * of the same product, for the reason {@see ConsumesAgeBands} gives: the
     * checklist has to be judged against the bands that now exist.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Product
    {
        // The wizard's own fields, which are not product columns.
        $wants = $data['wizard_publish'] ?? 'publish';
        $schedules = (array) ($data['wizard_schedules'] ?? []);
        $vesselPrice = $data['wizard_vessel_price'] ?? null;
        $vesselSeasonPrices = (array) ($data['wizard_vessel_season_prices'] ?? []);

        // «Μέχρι N άτομα, +Y € ο καθένας παραπάνω», where the operator sells
        // that way. Both or neither — `SaveRatePlan` clears one without the
        // other, so a half-answer is not quietly stored.
        $includedPax = $data['wizard_included_pax'] ?? null;
        $extraPaxPrice = $data['wizard_extra_pax_price'] ?? null;

        unset(
            $data['wizard_publish'],
            $data['wizard_schedules'],
            $data['wizard_vessel_price'],
            $data['wizard_vessel_season_prices'],
            $data['wizard_included_pax'],
            $data['wizard_extra_pax_price'],
        );

        /*
         * The price table of the edit page, asked in the wizard (2026-09-24):
         * the periods ticked, a price per group and column, the terms once.
         *
         * **Keyed by the repeater's own keys, matched by position.** A
         * Filament repeater's state is keyed by a generated id; `SaveAgeBands`
         * writes the set in the order given and hands back nothing to key on,
         * so the row an operator typed into is the band at the same position.
         */
        $seasonIds = array_values(array_map('intval', (array) ($data['wizard_seasons'] ?? [])));
        $typed = (array) ($data['wizard_prices'] ?? []);
        $terms = (array) ($data['wizard_terms'] ?? []);

        unset($data['wizard_seasons'], $data['wizard_prices'], $data['wizard_terms']);

        // «Πρόσθετα» (2026-09-24): saved after the trip, the way its own table
        // saves them — scoped to this trip.
        $extras = array_values((array) ($data['wizard_extras'] ?? []));
        unset($data['wizard_extras']);

        // A draft may be saved before «Πόσα άτομα» is answered; the column is
        // not nullable, so it takes the boat's certificate until it is.
        if (($data['max_pax'] ?? null) === null || $data['max_pax'] === '') {
            $data['max_pax'] = (int) (Vessel::query()->whereKey($data['vessel_id'] ?? null)->value('capacity_max') ?? 1);
        }

        $columns = [PriceTable::NEW_DEFAULT, ...array_map(static fn (int $id): string => 's' . $id, $seasonIds)];
        $grid = [];

        foreach (array_keys($data['age_bands'] ?? []) as $key) {
            $row = [];

            foreach ($columns as $column) {
                $row[$column] = $this->cents($typed[$key][$column] ?? null);
            }

            $grid[] = $row;
        }

        $this->guardWizardPrices($grid);

        // Always created as a draft, whatever the operator asked for: the
        // checklist cannot be judged before the bands and the prices exist.
        $data['status'] = ProductStatus::Draft->value;

        $product = $this->saveProductWithBands(new Product, $data);

        if ($product->mode === BookingMode::PerSeat) {
            $this->createSeatPrices($product, $grid, $seasonIds, $terms);
        }

        $this->createRatePlans($product, $vesselPrice, $vesselSeasonPrices, $includedPax, $extraPaxPrice);
        $this->createExtras($product, $extras);
        $this->createSchedules($product, $schedules);

        if ($wants === 'publish') {
            $this->publish($product);
        }

        return $product->refresh();
    }

    /**
     * A whole-boat charter's price lists: the boat's price all year and per
     * period. Per seat is {@see createSeatPrices()}.
     *
     * @param  array<array-key, array<string, mixed>>  $vesselSeasonPrices
     */
    private function createRatePlans(
        Product $product,
        mixed $vesselPrice,
        array $vesselSeasonPrices,
        mixed $includedPax = null,
        mixed $extraPaxPrice = null,
    ): void {
        if ($product->mode === BookingMode::PerVessel) {
            $this->createVesselPlans($product, $vesselPrice, $vesselSeasonPrices, $includedPax, $extraPaxPrice);
        }
    }

    /**
     * Refused before anything is written when a price is typed and another
     * left empty: a trip half-priced is one the table would refuse to save,
     * and by then the trip itself would exist. Nothing typed at all is fine —
     * the trip is a draft and the table is on its edit page.
     *
     * @param  list<array<string, int|null>>  $grid  band position => column => cents
     */
    private function guardWizardPrices(array $grid): void
    {
        $cells = array_merge(...array_map('array_values', $grid ?: [[]]));
        $empty = count(array_filter($cells, static fn (?int $cents): bool => $cents === null));

        if ($empty > 0 && $empty < count($cells)) {
            throw ValidationException::withMessages([
                'data.wizard_prices' => trans_choice('pricing.price_table.missing_banner', $empty, ['count' => $empty]),
            ]);
        }
    }

    /**
     * Per seat: the same {@see SavePriceTable} as the edit page, with the same
     * column keys — «Όλο τον χρόνο» as `new`, a ticked period as `s{id}`.
     *
     * @param  list<array<string, int|null>>  $grid
     * @param  list<int>  $seasonIds
     * @param  array<string, mixed>  $terms
     */
    private function createSeatPrices(Product $product, array $grid, array $seasonIds, array $terms): void
    {
        $bands = $product->ageBands()->orderBy('sort_order')->get()->values();
        $cells = array_merge(...array_map('array_values', $grid ?: [[]]));

        if ($cells === [] || in_array(null, $cells, true)) {
            return;
        }

        $cents = [];

        foreach ($bands as $position => $band) {
            $cents[PriceTable::rowKey($band)] = $grid[$position] ?? [];
        }

        try {
            app(SavePriceTable::class)($product, $cents, $seasonIds, EditProduct::termsFromForm($terms));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'data.wizard_prices' => array_merge(...array_values($exception->errors())),
            ]);
        }
    }

    /**
     * The wizard's «Πρόσθετα», each through {@see SaveExtra} as the trip's own
     * table does. A row with no name is a box opened and left empty.
     *
     * @param  list<array<string, mixed>>  $extras
     */
    private function createExtras(Product $product, array $extras): void
    {
        foreach ($extras as $row) {
            $name = (array) ($row['name'] ?? []);

            if (array_filter($name, static fn (mixed $value): bool => is_string($value) && trim($value) !== '') === []) {
                continue;
            }

            try {
                app(SaveExtra::class)(new Extra, ExtrasRelationManager::attributesFrom($row), [$product->getKey() => []]);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    'data.wizard_extras' => array_merge(...array_values($exception->errors())),
                ]);
            }
        }
    }

    /**
     * The same, for a charter: one price for the boat, and one per period.
     *
     * @param  array<array-key, array<string, mixed>>  $seasonRows
     */
    private function createVesselPlans(
        Product $product,
        mixed $vesselPrice,
        array $seasonRows,
        mixed $includedPax = null,
        mixed $extraPaxPrice = null,
    ): void {
        // Carried by every list, the all-year one and each period's: «μέχρι 8
        // άτομα» is a fact about the boat, not about the month.
        $extra = [];
        $extraCents = $this->cents($extraPaxPrice);

        if (is_numeric($includedPax) && (int) $includedPax > 0 && $extraCents !== null) {
            $extra = ['included_pax' => (int) $includedPax, 'extra_pax_price_cents' => $extraCents];
        }

        // Already cents: `MoneyInput` dehydrates its own state, so what arrives
        // here is the integer, not «450,00».
        $cents = $this->cents($vesselPrice);

        if ($cents === null) {
            return;
        }

        $this->savePlan($product, [...$this->planAttributes(null), ...$extra, 'vessel_price_cents' => $cents]);

        foreach ($seasonRows as $row) {
            $seasonId = $row['season_id'] ?? null;
            $seasonCents = $this->cents($row['price'] ?? null);

            if ($seasonId === null || $seasonId === '' || $seasonCents === null) {
                continue;
            }

            $this->savePlan(
                $product,
                [...$this->planAttributes((int) $seasonId), ...$extra, 'vessel_price_cents' => $seasonCents],
                seasonId: (int) $seasonId,
            );
        }
    }

    /**
     * The one shape of price list this page makes.
     *
     * Paid in full at checkout, which is what a trip with no deposit policy
     * means. The operator changes it on the trip's own «Τιμές» tab, where the
     * rest of the price list lives.
     *
     * @return array<string, mixed>
     */
    private function planAttributes(?int $seasonId): array
    {
        return [
            'season_id' => $seasonId,
            'is_active' => true,
            'deposit_type' => DepositType::None->value,
            'min_lead_time_hours' => 0,
        ];
    }

    /**
     * Save one price list, and say so rather than throwing when it is refused.
     *
     * A refusal here must not cost the operator the wizard. The trip, its
     * bands and its other lists are already saved; a period list that does not
     * cover every band is one line of explanation and a tab to fix it on, not
     * a reason to lose four steps of typing.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>|null  $rows
     */
    private function savePlan(Product $product, array $attributes, ?array $rows = null, ?int $seasonId = null): void
    {
        try {
            app(SaveRatePlan::class)(new RatePlan, $product, $attributes, $rows);
        } catch (ValidationException $exception) {
            $season = $seasonId === null
                ? __('pricing.rate_plan.form.season.default')
                : (string) (Season::query()->whereKey($seasonId)->value('name') ?? $seasonId);

            Notification::make()
                ->warning()
                ->title(__('catalog.product.wizard.prices.plan_refused', ['season' => $season]))
                ->body(implode('<br>', array_map('e', Arr::flatten($exception->errors()))))
                ->persistent()
                ->send();
        }
    }

    /** Whatever the form sent, in cents. `MoneyInput` dehydrates to an integer already. */
    private function cents(mixed $typed): ?int
    {
        return is_int($typed) ? $typed : MoneyInput::toCents($typed);
    }

    /**
     * The timetable, and the departures it makes.
     *
     * One `ScheduleRule` per day-set **per time**, which is how the trip's own
     * «Δρομολόγια» tab stores «φεύγει στις 10:00 και στις 18:00» too: sharing
     * the days and the window but separate rows, so 18:00 can be paused in
     * September without touching the morning.
     *
     * Only for a per-seat trip: a charter has no repeating schedule, it has a
     * day somebody asks for. Generating the departures here rather than waiting
     * for the nightly run is the difference between a trip that is published
     * and a trip that is published **and bookable** — the calendar is empty
     * until they exist, and an operator who has just finished a guide should be
     * able to look at it.
     *
     * @param  array<array-key, array<string, mixed>>  $schedules
     */
    private function createSchedules(Product $product, array $schedules): void
    {
        if ($product->mode !== BookingMode::PerSeat) {
            return;
        }

        $today = Carbon::today(Tenancy::current()->timezone)->toDateString();

        foreach ($schedules as $row) {
            // `WeekdayMask` owns the Monday-is-bit-0 arithmetic; nothing else
            // in the codebase writes `1 << $day`.
            $mask = WeekdayMask::fromDays(array_values((array) ($row['days'] ?? [])));

            $times = array_values(array_filter(
                array_values((array) ($row['times'] ?? [])),
                static fn (mixed $time): bool => is_string($time) && $time !== '',
            ));

            if ($mask === 0 || $times === []) {
                continue;
            }

            $from = $row['valid_from'] ?? null;
            $until = $row['valid_until'] ?? null;

            foreach ($times as $time) {
                try {
                    $rule = app(SaveScheduleRule::class)(new ScheduleRule, $product, [
                        'weekday_mask' => $mask,
                        'start_time' => $time,
                        'valid_from' => is_string($from) && $from !== '' ? $from : $today,
                        'valid_until' => is_string($until) && $until !== '' ? $until : null,
                        'is_active' => true,
                    ]);
                } catch (ValidationException $exception) {
                    // Two rows that mean the same sailing, most often — the
                    // operator typed Tuesday 09:00 twice. The trip and every
                    // other rule are already saved, so this is one line of
                    // explanation, not a reason to lose the whole guide.
                    Notification::make()
                        ->warning()
                        ->title(__('catalog.product.wizard.when.schedule_refused', ['time' => substr($time, 0, 5)]))
                        ->body(implode('<br>', array_map('e', Arr::flatten($exception->errors()))))
                        ->persistent()
                        ->send();

                    continue;
                }

                app(GenerateDepartures::class)($rule);

                // Whether the boat is already out on another trip at this hour
                // — after the departures exist, so the answer is about what is
                // really on the calendar.
                ScheduleConflictNotice::sendFor($rule);
            }
        }
    }

    /**
     * «Δημοσίευση τώρα», when everything the checklist asks for is there.
     *
     * A refusal is not an error here: the operator answered four questions and
     * one of them — an English title, a policy — may still be missing. The trip
     * stays a draft, they land on its page, and the checklist beside the form
     * says what is left. Throwing would lose the whole wizard.
     */
    private function publish(Product $product): void
    {
        try {
            // **`SaveProduct` directly, not `saveProductWithBands`.** That
            // helper saves the band set it is given, and the set here would be
            // the empty one in `['status' => …]` — it would wipe the bands this
            // wizard has just created and then refuse to publish a trip for
            // having none. The bands are already saved; this is only the status.
            app(SaveProduct::class)($product, ['status' => ProductStatus::Active->value]);
        } catch (ValidationException $exception) {
            $reasons = $exception->errors()['data.status'] ?? [];

            Notification::make()
                ->warning()
                ->title(__('catalog.product.status_actions.not_published'))
                ->body($reasons === [] ? null : implode('<br>', array_map('e', $reasons)))
                ->persistent()
                ->send();
        }
    }
}
