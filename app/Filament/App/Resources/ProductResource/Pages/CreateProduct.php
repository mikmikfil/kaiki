<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Enums\BookingMode;
use App\Enums\DepositType;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource;
use App\Filament\Forms\MoneyInput;
use App\Models\AgeBand;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\ScheduleRule;
use App\Models\Season;
use App\Support\Tenancy;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        unset(
            $data['wizard_publish'],
            $data['wizard_schedules'],
            $data['wizard_vessel_price'],
            $data['wizard_vessel_season_prices'],
        );

        /*
         * Prices travel on the band rows so an operator types a name and its
         * price in one place; they are not columns on `age_bands`.
         *
         * **Keyed by the repeater's own keys, with the position counted on the
         * side.** A Filament repeater's state is keyed by a generated id, not
         * by 0..n, so an `unset()` after an `array_values()` walk removes
         * nothing and `wizard_price` travels on to `SaveAgeBands` as an
         * attribute no band has. The position is still what the saved bands are
         * matched on afterwards, so it is counted separately.
         */
        $prices = [];
        $seasonPrices = [];
        $position = 0;

        foreach ($data['age_bands'] ?? [] as $key => $band) {
            $prices[$position] = $band['wizard_price'] ?? null;

            foreach ((array) ($band['wizard_season_prices'] ?? []) as $row) {
                $season = $row['season_id'] ?? null;

                if ($season === null || $season === '') {
                    continue;
                }

                $seasonPrices[(int) $season][$position] = $row['price'] ?? null;
            }

            unset($data['age_bands'][$key]['wizard_price'], $data['age_bands'][$key]['wizard_season_prices']);

            $position++;
        }

        // Always created as a draft, whatever the operator asked for: the
        // checklist cannot be judged before the bands and the prices exist.
        $data['status'] = ProductStatus::Draft->value;

        $product = $this->saveProductWithBands(new Product, $data);

        $this->createRatePlans($product, $prices, $seasonPrices, $vesselPrice, $vesselSeasonPrices);
        $this->createSchedules($product, $schedules);

        if ($wants === 'publish') {
            $this->publish($product);
        }

        return $product->refresh();
    }

    /**
     * The trip's price lists: «Όλο τον χρόνο», and one for every period the
     * operator gave a different price for.
     *
     * The all-year list is the one every trip needs and nobody thinks to make —
     * it is what applies when no period matches, and without it a trip outside
     * its seasons has no price at all. So it is made first and from it the
     * period lists are filled in: **a period list inherits every band the
     * operator did not price separately** (PRC-4 wants every band covered, and
     * «το καλοκαίρι ο ενήλικας 55» is not a statement about children).
     *
     * @param  array<int, int|string|null>  $prices  band position => the price
     * @param  array<int, array<int, int|string|null>>  $seasonPrices  season id => band position => the price
     * @param  array<array-key, array<string, mixed>>  $vesselSeasonPrices
     */
    private function createRatePlans(
        Product $product,
        array $prices,
        array $seasonPrices,
        mixed $vesselPrice,
        array $vesselSeasonPrices,
    ): void {
        if ($product->mode === BookingMode::PerVessel) {
            $this->createVesselPlans($product, $vesselPrice, $vesselSeasonPrices);

            return;
        }

        if ($product->mode !== BookingMode::PerSeat) {
            return;
        }

        // Matched by position: `SaveAgeBands` writes the set in the order it
        // was given and hands back nothing this page can key on, so the row an
        // operator typed a price into is the row at the same index.
        $bands = $product->ageBands()->orderBy('sort_order')->get();
        $base = $this->bandRows($bands, $prices);

        if ($base === []) {
            return;
        }

        // The prices are a fourth argument, not an attribute: they are rows in
        // `rate_plan_prices`, and `SaveRatePlan` is the only thing that writes
        // them (PRC-4's band coverage is checked in there).
        $this->savePlan($product, $this->planAttributes(null), $base);

        foreach ($seasonPrices as $seasonId => $typed) {
            // Union rather than spread: both sides are keyed by band id, and
            // `[...$a, ...$b]` renumbers integer keys — which would hand
            // `SaveRatePlan` prices for bands 0, 1, 2.
            $rows = $this->bandRows($bands, $typed) + $base;

            $this->savePlan($product, $this->planAttributes($seasonId), $rows, $seasonId);
        }
    }

    /**
     * The same, for a charter: one price for the boat, and one per period.
     *
     * @param  array<array-key, array<string, mixed>>  $seasonRows
     */
    private function createVesselPlans(Product $product, mixed $vesselPrice, array $seasonRows): void
    {
        // Already cents: `MoneyInput` dehydrates its own state, so what arrives
        // here is the integer, not «450,00».
        $cents = $this->cents($vesselPrice);

        if ($cents === null) {
            return;
        }

        $this->savePlan($product, [...$this->planAttributes(null), 'vessel_price_cents' => $cents]);

        foreach ($seasonRows as $row) {
            $seasonId = $row['season_id'] ?? null;
            $seasonCents = $this->cents($row['price'] ?? null);

            if ($seasonId === null || $seasonId === '' || $seasonCents === null) {
                continue;
            }

            $this->savePlan(
                $product,
                [...$this->planAttributes((int) $seasonId), 'vessel_price_cents' => $seasonCents],
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
     * Typed prices onto the bands that were saved, by position.
     *
     * @param  Collection<int, AgeBand>  $bands
     * @param  array<int, int|string|null>  $prices  band position => the price
     * @return array<int, int> age band id => cents, the shape `SaveRatePlan` reads
     */
    private function bandRows(Collection $bands, array $prices): array
    {
        $rows = [];

        foreach ($bands as $index => $band) {
            $cents = $this->cents($prices[$index] ?? null);

            if ($cents === null) {
                continue;
            }

            $rows[$band->getKey()] = $cents;
        }

        return $rows;
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
