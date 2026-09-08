<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Availability\Actions\GenerateDepartures;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Models\AgeBand;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\ScheduleRule;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Ten boats and ten trips per demo operator.
 *
 * ## Why a fleet rather than the two of everything the other seeders make
 *
 * `DemoCatalogSeeder` and `DemoBookableSeeder` build the *minimum* that proves
 * each feature works, which is right for a test fixture and wrong for looking
 * at the product. Three screens only become themselves at scale:
 *
 * - The **vessel calendar** with one boat is a line. With ten it is a fleet,
 *   and the turnaround margins, the overlaps and the empty afternoons are
 *   visible as a shape rather than as an example.
 * - The **catalogue search** with two trips cannot demonstrate a filter.
 * - The **dashboard** figures read as arithmetic on two rows and as a business
 *   on twenty.
 *
 * ## It tops up rather than replacing
 *
 * Everything is `updateOrCreate` on a stable slug, and the counts are targets
 * rather than quantities: running this twice adds nothing, and running it after
 * the other demo seeders leaves their carefully chosen rows alone. Re-seeding a
 * demo database should be boring.
 *
 * ## The variety is deliberate, not decorative
 *
 * Different durations, start times, prices, capacities, boat types and booking
 * modes — because a fleet where every trip is three hours at 18:30 makes a
 * calendar of identical bars, which demonstrates the calendar less well than
 * one boat would. The mix is what makes a screenshot informative.
 */
class DemoFleetSeeder extends Seeder
{
    /** How many of each an operator ends up with. */
    private const TARGET = 10;

    /** Three months, matching `DemoBookableSeeder` so the two agree. */
    private const DAYS_AHEAD = 90;

    /**
     * The boats, in the order they are added.
     *
     * Greek names because the operators are Greek and a demo full of English
     * boat names is a demo of somebody else's product. Capacities vary widely:
     * a twelve-seat RIB and a forty-eight-seat day boat behave differently in
     * every capacity calculation on the platform.
     *
     * @var list<array{name: string, type: VesselType, capacity: int, crew: int, buffer: int}>
     */
    private const VESSELS = [
        ['name' => 'Ποσειδώνας', 'type' => VesselType::TraditionalKaiki, 'capacity' => 42, 'crew' => 3, 'buffer' => 60],
        ['name' => 'Γαλήνη', 'type' => VesselType::Motor, 'capacity' => 28, 'crew' => 2, 'buffer' => 45],
        ['name' => 'Αμφιτρίτη', 'type' => VesselType::Catamaran, 'capacity' => 18, 'crew' => 2, 'buffer' => 90],
        ['name' => 'Θαλασσινός', 'type' => VesselType::Rib, 'capacity' => 12, 'crew' => 1, 'buffer' => 30],
        ['name' => 'Ναυσικά', 'type' => VesselType::SailingYacht, 'capacity' => 10, 'crew' => 2, 'buffer' => 120],
        ['name' => 'Αίολος', 'type' => VesselType::Motor, 'capacity' => 34, 'crew' => 3, 'buffer' => 45],
        ['name' => 'Μελτέμι', 'type' => VesselType::Catamaran, 'capacity' => 24, 'crew' => 2, 'buffer' => 90],
        ['name' => 'Κυματοθραύστης', 'type' => VesselType::Rib, 'capacity' => 8, 'crew' => 1, 'buffer' => 30],
        ['name' => 'Αργώ', 'type' => VesselType::TraditionalKaiki, 'capacity' => 48, 'crew' => 4, 'buffer' => 60],
        ['name' => 'Ζέφυρος', 'type' => VesselType::SailingYacht, 'capacity' => 12, 'crew' => 2, 'buffer' => 120],
    ];

    /**
     * The trips.
     *
     * Start times are spread across the day on purpose: a calendar where every
     * bar begins at 18:30 shows a stack rather than a schedule, and the
     * turnaround margin — the thing #119 exists to draw — is only visible when
     * two sailings on one boat nearly touch.
     *
     * @var list<array{
     *     slug: string, el: string, en: string, summary_el: string, summary_en: string,
     *     category: ProductCategory, mode: BookingMode, minutes: int, start: string,
     *     min: int, max: int, cents: int
     * }>
     */
    private const TRIPS = [
        [
            'slug' => 'proino-kolymvitiko', 'el' => 'Πρωινό κολυμβητικό', 'en' => 'Morning swim cruise',
            'summary_el' => 'Τρεις όρμοι πριν ζεστάνει η μέρα.', 'summary_en' => 'Three coves before the day gets hot.',
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 240, 'start' => '09:00', 'min' => 6, 'max' => 24, 'cents' => 5500,
        ],
        [
            'slug' => 'olimeri-tria-nisia', 'el' => 'Ολοήμερη στα τρία νησιά', 'en' => 'Full day, three islands',
            'summary_el' => 'Με γεύμα στο σκάφος και δύο στάσεις για μπάνιο.', 'summary_en' => 'Lunch on board and two swim stops.',
            'category' => ProductCategory::SharedFullDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 480, 'start' => '08:30', 'min' => 8, 'max' => 40, 'cents' => 9500,
        ],
        [
            'slug' => 'apogevmatino-psarema', 'el' => 'Απογευματινό ψάρεμα', 'en' => 'Afternoon fishing trip',
            'summary_el' => 'Με τον καπετάνιο και τα σύνεργά του.', 'summary_en' => 'With the skipper and his tackle.',
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 300, 'start' => '15:00', 'min' => 4, 'max' => 12, 'cents' => 7000,
        ],
        [
            'slug' => 'romantiko-dilino', 'el' => 'Ρομαντικό δείπνο εν πλω', 'en' => 'Dinner under way',
            'summary_el' => 'Δύο άτομα, ένα τραπέζι στην πλώρη.', 'summary_en' => 'Two people, one table on the bow.',
            'category' => ProductCategory::Sunset, 'mode' => BookingMode::PerVessel,
            'minutes' => 210, 'start' => '19:30', 'min' => 2, 'max' => 8, 'cents' => 38000,
        ],
        [
            'slug' => 'idiotiki-imera-skafos', 'el' => 'Ιδιωτική ημέρα με σκάφος', 'en' => 'Private day on the water',
            'summary_el' => 'Το σκάφος δικό σας, η διαδρομή δική σας.', 'summary_en' => 'Your boat, your route.',
            'category' => ProductCategory::PrivateFullDay, 'mode' => BookingMode::PerVessel,
            'minutes' => 480, 'start' => '10:00', 'min' => 1, 'max' => 12, 'cents' => 62000,
        ],
        [
            'slug' => 'spilies-kai-ormoi', 'el' => 'Σπηλιές και όρμοι', 'en' => 'Caves and coves',
            'summary_el' => 'Με ταχύπλοο εκεί που δεν φτάνουν τα μεγάλα.', 'summary_en' => 'By RIB, where the big boats cannot go.',
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 180, 'start' => '11:30', 'min' => 4, 'max' => 10, 'cents' => 6500,
        ],
        [
            'slug' => 'istioploia-me-pania', 'el' => 'Ιστιοπλοΐα με πανιά', 'en' => 'Sailing, engine off',
            'summary_el' => 'Χωρίς μηχανή, μόνο αέρας.', 'summary_en' => 'No engine, just wind.',
            'category' => ProductCategory::SharedFullDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 420, 'start' => '09:30', 'min' => 4, 'max' => 10, 'cents' => 11000,
        ],
        [
            'slug' => 'metafora-sto-nisi', 'el' => 'Μεταφορά στο νησί', 'en' => 'Transfer to the island',
            'summary_el' => 'Απευθείας, χωρίς στάσεις.', 'summary_en' => 'Direct, no stops.',
            'category' => ProductCategory::Custom, 'mode' => BookingMode::PerSeat,
            'minutes' => 90, 'start' => '07:30', 'min' => 6, 'max' => 34, 'cents' => 3200,
        ],
        [
            'slug' => 'ilioyasilema-me-krasi', 'el' => 'Ηλιοβασίλεμα με κρασί', 'en' => 'Sunset with a glass of wine',
            'summary_el' => 'Δύο ώρες, ένα ποτήρι, το φως που φεύγει.', 'summary_en' => 'Two hours, one glass, the light going.',
            'category' => ProductCategory::Sunset, 'mode' => BookingMode::PerSeat,
            'minutes' => 150, 'start' => '19:00', 'min' => 6, 'max' => 28, 'cents' => 4800,
        ],
        [
            'slug' => 'misi-mera-idiotiko', 'el' => 'Ιδιωτικό μισής ημέρας', 'en' => 'Private half day',
            'summary_el' => 'Για μια παρέα που θέλει τον χρόνο της.', 'summary_en' => 'For a group that wants its own pace.',
            'category' => ProductCategory::PrivateHalfDay, 'mode' => BookingMode::PerVessel,
            'minutes' => 240, 'start' => '13:00', 'min' => 1, 'max' => 18, 'cents' => 34000,
        ],
    ];

    public function run(): void
    {
        Tenant::query()
            ->whereIn('slug', ['aegean-blue', 'ionian-sunset'])
            ->get()
            ->each(function (Tenant $tenant): void {
                Tenancy::forTenant($tenant, function (): void {
                    $this->fillFleet();
                    $this->fillCatalogue();
                });
            });
    }

    /** Top the boats up to ten, leaving the seeded ones untouched. */
    private function fillFleet(): void
    {
        $port = Port::query()->orderBy('id')->first();

        foreach (self::VESSELS as $index => $spec) {
            if (Vessel::query()->count() >= self::TARGET) {
                return;
            }

            Vessel::query()->updateOrCreate(
                ['name' => $spec['name']],
                [
                    'type' => $spec['type'],
                    'capacity_max' => $spec['capacity'],
                    'crew_count' => $spec['crew'],
                    // AVL-8's buffer, varied per boat: a fleet where every
                    // turnaround is 60 minutes draws ten identical margins and
                    // teaches nobody what the setting does.
                    'turnaround_buffer_minutes' => $spec['buffer'],
                    'home_port_id' => $port?->getKey(),
                    'registration_number' => 'NAY-' . str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT),
                    'captain_name' => null,
                    'status' => VesselStatus::Active,
                    'sort_order' => $index + 10,
                    'description' => null,
                    'specs' => [],
                    'images' => [],
                ],
            );
        }
    }

    /** Top the trips up to ten, each one actually bookable. */
    private function fillCatalogue(): void
    {
        $policy = CancellationPolicy::query()->orderBy('id')->first();
        $season = $this->season();
        $port = Port::query()->orderBy('id')->first();

        $vessels = Vessel::query()->orderBy('id')->get();

        if ($vessels->isEmpty() || $port === null) {
            return;
        }

        foreach (self::TRIPS as $index => $trip) {
            if (Product::query()->count() >= self::TARGET) {
                return;
            }

            // Spread across the fleet so the calendar has more than one busy
            // row, and so no boat is asked to be in two places at once.
            $vessel = $vessels[$index % $vessels->count()];

            $product = Product::query()->updateOrCreate(
                ['slug' => $trip['slug']],
                [
                    'vessel_id' => $vessel->getKey(),
                    'meeting_point_id' => $port->getKey(),
                    'cancellation_policy_id' => $policy?->getKey(),
                    'category' => $trip['category'],
                    'mode' => $trip['mode'],
                    'title' => ['el' => $trip['el'], 'en' => $trip['en']],
                    'summary' => ['el' => $trip['summary_el'], 'en' => $trip['summary_en']],
                    'description' => ['el' => $trip['summary_el'], 'en' => $trip['summary_en']],
                    'duration_minutes' => $trip['minutes'],
                    'default_start_time' => $trip['start'],
                    'check_in_offset_minutes' => 30,
                    'min_pax' => $trip['min'],
                    'max_pax' => min($trip['max'], $vessel->capacity_max),
                    'status' => ProductStatus::Active,
                    'guest_details_required' => false,
                ],
            );

            $this->ageBands($product);
            $this->ratePlan($product->refresh(), $season, $trip['cents']);
            $this->schedule($product, $trip['start']);
        }
    }

    private function season(): Season
    {
        $season = Season::query()->orderBy('id')->first();

        if ($season instanceof Season) {
            return $season;
        }

        return Season::query()->create([
            'name' => ['el' => 'Σεζόν', 'en' => 'Season'],
            'priority' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Adult, child, infant — the CAT-8 set.
     *
     * An infant that takes no seat is here on purpose: it is the case OPS-9's
     * head count gets wrong, and a demo without one cannot show that the
     * manifest counts bodies rather than seats.
     */
    private function ageBands(Product $product): void
    {
        if (AgeBand::query()->where('product_id', $product->getKey())->exists()) {
            return;
        }

        // The same shape `DemoBookableSeeder` writes, deliberately: two seeders
        // disagreeing about what an age band is would make the demo's own
        // pricing inconsistent between trips.
        foreach ([
            ['adult', 'Ενήλικας', 'Adult', 12, null, true, true, 10000],
            ['child', 'Παιδί', 'Child', 3, 11, true, false, 5000],
            ['infant', 'Βρέφος', 'Infant', 0, 2, false, false, 0],
        ] as $position => [$code, $el, $en, $min, $max, $counts, $isBase, $multiplierBp]) {
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
                    'sort_order' => $position,
                ],
            );
        }
    }

    private function ratePlan(Product $product, Season $season, int $adultCents): void
    {
        $plan = RatePlan::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'season_id' => $season->getKey()],
            [
                // A plain column here, not translatable (§2.3).
                'name' => 'Κανονική τιμή',
                'min_lead_time_hours' => 2,
                'max_advance_days' => 365,
                'deposit_type' => 'percent',
                'deposit_percent' => 30,
                'is_active' => true,
            ],
        );

        $adult = AgeBand::query()
            ->where('product_id', $product->getKey())
            ->where('code', 'adult')
            ->first();

        if ($adult instanceof AgeBand) {
            RatePlanPrice::query()->updateOrCreate(
                ['rate_plan_id' => $plan->getKey(), 'age_band_id' => $adult->getKey()],
                ['price_cents' => $adultCents],
            );
        }
    }

    /**
     * A daily rule, generated out to the horizon.
     *
     * Every day rather than a weekday mask, because a demo calendar with gaps
     * looks like a bug to somebody who has not read the schedule rule — and the
     * one screen this data exists for is the calendar.
     */
    private function schedule(Product $product, string $start): void
    {
        $rule = ScheduleRule::query()->updateOrCreate(
            ['product_id' => $product->getKey(), 'start_time' => $start . ':00'],
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
