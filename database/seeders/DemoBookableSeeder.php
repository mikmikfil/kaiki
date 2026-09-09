<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\CancellationPolicyTier;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\ScheduleRule;
use App\Models\Season;
use App\Models\SeasonDateRange;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A demo operator you can actually book with.
 *
 * {@see DemoCatalogSeeder} stops at ports and vessels, which is enough to open
 * `/app` and see rows and not enough to *do* anything: no age bands, no season,
 * no rate plan, no schedule and therefore no departures. Every guest-facing
 * surface — the availability endpoint, the price quote, the widget when it
 * lands in M3 — needs the whole chain before it can answer at all.
 *
 * So this seeder builds the rest of it for **Aegean Blue**, the Greek-first
 * fleet operator, and leaves Ionian Sunset thin on purpose: a trial account
 * with one boat and nothing configured is a real state the panel has to render,
 * and if both demo tenants were complete nobody would ever see it.
 *
 * ## Deterministic, and idempotent on re-seed
 *
 * `updateOrCreate` keyed on a natural key throughout, and no faker — the same
 * rule {@see DemoCatalogSeeder} states. A demo that produced different prices
 * on each run would make every screenshot and every conversation about it
 * unreliable.
 *
 * ## Departures come from the real generator
 *
 * `GenerateDepartures` rather than a loop writing rows: the schedule rule, the
 * weekday mask, the DST-correct local-to-UTC conversion and the
 * `departures_tenant_prod_start_uq` idempotency are all things the demo should
 * be exercising rather than side-stepping. A hand-written departure row is a
 * row the availability engine has never agreed to.
 */
class DemoBookableSeeder extends Seeder
{
    /** Three months of sailings — enough to page through a calendar. */
    private const DAYS_AHEAD = 90;

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'aegean-blue')->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function (): void {
            $policy = $this->policy();
            $season = $this->season();

            $sunset = $this->sunsetCruise($policy);
            $charter = $this->privateCharter($policy);

            $this->ratePlan($sunset, $season);
            $this->schedule($sunset);

            $this->ratePlan($charter, $season, adultCents: 45000);
        });
    }

    /**
     * A tiered policy with **no free-cancellation window**.
     *
     * The factory default of 48 hours would return 100% for every cancellation
     * more than two days out, so the tier ladder would never be consulted and
     * the demo would show the same refund for every date — which is the one
     * thing a cancellation demo is meant to show.
     */
    private function policy(): CancellationPolicy
    {
        $policy = CancellationPolicy::query()->updateOrCreate(
            ['name->el' => 'Ευέλικτη'],
            [
                'name' => ['el' => 'Ευέλικτη', 'en' => 'Flexible'],
                'summary' => [
                    'el' => 'Πλήρης επιστροφή έως 15 ημέρες πριν, 50% έως 7 ημέρες πριν.',
                    'en' => 'Full refund up to 15 days before, 50% up to 7 days before.',
                ],
                'free_cancellation_hours' => null,
                'weather_refund_percent' => 100,
                'force_majeure_voucher_months' => 18,
                'no_show_refund_percent' => 0,
                'is_default' => true,
            ],
        );

        foreach ([[15, 100], [7, 50], [2, 0]] as [$days, $percent]) {
            CancellationPolicyTier::query()->updateOrCreate(
                ['cancellation_policy_id' => $policy->getKey(), 'days_before' => $days],
                ['refund_percent' => $percent],
            );
        }

        return $policy->refresh();
    }

    /** One season covering this year and next, so no demo date falls outside it. */
    private function season(): Season
    {
        $season = Season::query()->updateOrCreate(
            ['name->el' => 'Θερινή περίοδος'],
            ['name' => ['el' => 'Θερινή περίοδος', 'en' => 'Summer season'], 'priority' => 1],
        );

        SeasonDateRange::query()->updateOrCreate(
            ['season_id' => $season->getKey(), 'starts_on' => Carbon::now()->startOfYear()->toDateString()],
            ['ends_on' => Carbon::now()->addYear()->endOfYear()->toDateString()],
        );

        return $season->refresh();
    }

    /** The per-seat product: the shape most of the product is built around. */
    private function sunsetCruise(CancellationPolicy $policy): Product
    {
        $vessel = Vessel::query()->orderBy('id')->firstOrFail();
        $port = Port::query()->orderBy('id')->firstOrFail();

        $product = Product::query()->updateOrCreate(
            ['slug' => 'iliovasilema-aigina'],
            [
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
                'category' => ProductCategory::Sunset,
                'mode' => BookingMode::PerSeat,
                'title' => ['el' => 'Ηλιοβασίλεμα στην Αίγινα', 'en' => 'Sunset cruise to Aegina'],
                'summary' => [
                    'el' => 'Τρίωρη κρουαζιέρα με παραδοσιακό καΐκι.',
                    'en' => 'A three-hour cruise on a traditional kaiki.',
                ],
                'description' => [
                    'el' => <<<'EL'
                        Φεύγουμε από τη Μαρίνα Ζέας δύο ώρες πριν τη δύση, με το παραδοσιακό καΐκι μας του 1978. Η διαδρομή περνά έξω από τον Φλοίσβο και ανοίγει προς τον Σαρωνικό, εκεί που το φως αρχίζει να χαμηλώνει.

                        Αγκυροβολούμε σε ήσυχο όρμο της Αίγινας για μπάνιο. Το νερό εκείνη την ώρα είναι ζεστό από όλη μέρα και δεν έχει σχεδόν κανέναν άλλο· δίνουμε πετσέτες και σωσίβια, και όποιος δεν θέλει να μπει μένει στο κατάστρωμα με ένα ποτήρι κρασί.

                        Ο γυρισμός γίνεται με αναμμένα τα φώτα του Πειραιά απέναντι. Τρεις ώρες συνολικά, μέχρι είκοσι άτομα, και ο καπετάνιος κάνει τη διαδρομή τριάντα χρόνια.
                        EL,
                    'en' => <<<'EN'
                        We leave Zea Marina two hours before sunset on our 1978 kaiki. The route runs out past Flisvos and opens into the Saronic, where the light starts to drop.

                        We anchor in a quiet cove off Aegina to swim. The water is warm from the whole day by then and there is almost nobody else there; towels and life jackets are on board, and anyone who would rather not swim stays on deck with a glass of wine.

                        We come back with the lights of Piraeus on across the water. Three hours in all, up to twenty people, and the skipper has been making this run for thirty years.
                        EN,
                ],
                'duration_minutes' => 180,
                'default_start_time' => '18:30',
                'check_in_offset_minutes' => 30,
                'min_pax' => 4,
                'max_pax' => min(20, $vessel->capacity_max),
                'status' => ProductStatus::Active,
                'guest_details_required' => false,
            ],
        );

        $this->ageBands($product);

        return $product->fresh(['ageBands']) ?? $product;
    }

    /** The per-vessel product, so the second booking mode is visible too. */
    private function privateCharter(CancellationPolicy $policy): Product
    {
        $vessel = Vessel::query()->orderBy('id')->skip(1)->first() ?? Vessel::query()->orderBy('id')->firstOrFail();
        $port = Port::query()->orderBy('id')->firstOrFail();

        $product = Product::query()->updateOrCreate(
            ['slug' => 'idiotiki-naulosi-imeras'],
            [
                'vessel_id' => $vessel->getKey(),
                'meeting_point_id' => $port->getKey(),
                'cancellation_policy_id' => $policy->getKey(),
                'category' => ProductCategory::PrivateFullDay,
                'mode' => BookingMode::PerVessel,
                'title' => ['el' => 'Ιδιωτική ναύλωση ημέρας', 'en' => 'Private day charter'],
                'summary' => [
                    'el' => 'Ολόκληρο το σκάφος, δικό σας για μια μέρα.',
                    'en' => 'The whole boat, yours for the day.',
                ],
                'description' => [
                    'el' => <<<'EL'
                        Οκτώ ώρες με το σκάφος και το πλήρωμα στη διάθεσή σας, από τις εννιά το πρωί. Η διαδρομή είναι δική σας απόφαση: τη συζητάτε με τον καπετάνιο το πρωί της αναχώρησης, με βάση τον καιρό της ημέρας και το τι σας ενδιαφέρει.

                        Οι συνηθισμένες επιλογές είναι η Αίγινα για φαγητό στο λιμάνι, το Αγκίστρι για τα νερά του, ή δύο-τρεις όρμοι χωρίς πρόσβαση από στεριά αν θέλετε κυρίως μπάνιο. Μπορείτε επίσης να μην πάτε πουθενά συγκεκριμένα και να μείνετε αγκυροβολημένοι όλη μέρα — γίνεται συχνότερα από όσο νομίζετε.

                        Στο σκάφος υπάρχει σκιά σε όλο το κατάστρωμα, ψυγείο, ντους στην πρύμνη, μάσκες και πετσέτες. Φαγητό φέρνετε δικό σας ή το αναλαμβάνουμε εμείς αν μας το πείτε όταν κλείνετε. Η τιμή είναι για ολόκληρο το σκάφος, οπότε δεν αλλάζει με τον αριθμό των ατόμων.
                        EL,
                    'en' => <<<'EN'
                        Eight hours with the boat and her crew to yourselves, from nine in the morning. The route is your decision: you settle it with the skipper on the morning you sail, around that day's weather and whatever interests you.

                        The usual choices are Aegina for lunch in the harbour, Agistri for the water, or two or three coves with no road to them if you mostly want to swim. You can also go nowhere in particular and stay at anchor all day — it happens more often than you would think.

                        There is shade across the whole deck, a fridge, a shower at the stern, masks and towels. Bring your own food or let us arrange it when you book. The price is for the whole boat, so it does not change with the number of people.
                        EN,
                ],
                'duration_minutes' => 480,
                'default_start_time' => '09:00',
                'check_in_offset_minutes' => 30,
                'min_pax' => 0,
                'max_pax' => $vessel->capacity_max,
                'status' => ProductStatus::Active,
                'guest_details_required' => true,
                'guest_details_deadline_hours' => 48,
            ],
        );

        $this->ageBands($product);

        return $product->fresh(['ageBands']) ?? $product;
    }

    /**
     * Three bands, and the infant is the interesting one.
     *
     * CAT-8 needs exactly one base band and at least one that counts toward
     * capacity. The infant counts toward **neither** the commercial capacity nor
     * the price, and still occupies a manifest line and a seat in AVL-25's legal
     * count — which is the distinction #89 had to settle and which is invisible
     * in a demo that only has adults.
     */
    private function ageBands(Product $product): void
    {
        $bands = [
            ['adult', 'Ενήλικας', 'Adult', 12, null, true, true, 10000],
            ['child', 'Παιδί', 'Child', 3, 11, true, false, 5000],
            ['infant', 'Βρέφος', 'Infant', 0, 2, false, false, 0],
        ];

        foreach ($bands as [$code, $el, $en, $min, $max, $counts, $isBase, $multiplierBp]) {
            AgeBand::query()->updateOrCreate(
                ['product_id' => $product->getKey(), 'code' => $code],
                [
                    'label' => ['el' => $el, 'en' => $en],
                    'min_age' => $min,
                    'max_age' => $max,
                    'counts_toward_capacity' => $counts,
                    'is_base' => $isBase,
                    'pricing_mode' => AgeBandPricing::Multiplier,
                    'price_multiplier_bp' => $multiplierBp,
                    'sort_order' => array_search($code, ['adult', 'child', 'infant'], true),
                ],
            );
        }
    }

    /** A plan with a real adult price, and a deposit so ADR-0004's two sessions are demonstrable. */
    private function ratePlan(Product $product, Season $season, int $adultCents = 6500): RatePlan
    {
        $plan = RatePlan::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'season_id' => $season->getKey()],
            [
                // `name` is a plain column here, not translatable (§2.3).
                'name' => 'Κανονική τιμή',
                'min_lead_time_hours' => 2,
                'max_advance_days' => 365,
                'deposit_type' => 'percent',
                'deposit_percent' => 30,
                'is_active' => true,
            ],
        );

        $adult = $product->ageBands->firstWhere('code', 'adult');

        if ($adult instanceof AgeBand) {
            RatePlanPrice::query()->updateOrCreate(
                ['rate_plan_id' => $plan->getKey(), 'age_band_id' => $adult->getKey()],
                ['price_cents' => $adultCents],
            );
        }

        return $plan->refresh();
    }

    /**
     * Sailings every day of the week, generated by the real generator.
     *
     * `weekday_mask` 127 is all seven bits — a sunset cruise runs daily in
     * season, and a demo with gaps in the calendar looks broken rather than
     * looking like a schedule.
     */
    private function schedule(Product $product): void
    {
        $rule = ScheduleRule::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'start_time' => '18:30:00'],
            [
                'weekday_mask' => 127,
                'valid_from' => Carbon::now()->subMonth()->toDateString(),
                'valid_until' => null,
                'generate_days_ahead' => self::DAYS_AHEAD,
                'is_active' => true,
            ],
        );

        app(GenerateDepartures::class)($rule->refresh());
    }
}
