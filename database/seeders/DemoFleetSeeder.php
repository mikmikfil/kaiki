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
     * @var list<array{name: string, type: VesselType, capacity: int, crew: int, buffer: int, wind: int}>
     */
    private const VESSELS = [
        ['name' => 'Ποσειδώνας', 'type' => VesselType::TraditionalKaiki, 'capacity' => 42, 'crew' => 3, 'buffer' => 60, 'wind' => 7],
        ['name' => 'Γαλήνη', 'type' => VesselType::Motor, 'capacity' => 28, 'crew' => 2, 'buffer' => 45, 'wind' => 6],
        ['name' => 'Αμφιτρίτη', 'type' => VesselType::Catamaran, 'capacity' => 18, 'crew' => 2, 'buffer' => 90, 'wind' => 6],
        ['name' => 'Θαλασσινός', 'type' => VesselType::Rib, 'capacity' => 12, 'crew' => 1, 'buffer' => 30, 'wind' => 5],
        ['name' => 'Ναυσικά', 'type' => VesselType::SailingYacht, 'capacity' => 10, 'crew' => 2, 'buffer' => 120, 'wind' => 6],
        ['name' => 'Αίολος', 'type' => VesselType::Motor, 'capacity' => 34, 'crew' => 3, 'buffer' => 45, 'wind' => 7],
        ['name' => 'Μελτέμι', 'type' => VesselType::Catamaran, 'capacity' => 24, 'crew' => 2, 'buffer' => 90, 'wind' => 6],
        ['name' => 'Κυματοθραύστης', 'type' => VesselType::Rib, 'capacity' => 8, 'crew' => 1, 'buffer' => 30, 'wind' => 4],
        ['name' => 'Αργώ', 'type' => VesselType::TraditionalKaiki, 'capacity' => 48, 'crew' => 4, 'buffer' => 60, 'wind' => 7],
        ['name' => 'Ζέφυρος', 'type' => VesselType::SailingYacht, 'capacity' => 12, 'crew' => 2, 'buffer' => 120, 'wind' => 6],
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
     *     desc_el: string, desc_en: string,
     *     category: ProductCategory, mode: BookingMode, minutes: int, start: string,
     *     min: int, max: int, cents: int
     * }>
     */
    /**
     * The four the rail recommends.
     *
     * Something has to be, or `is_featured` is a column nothing on the demo
     * exercises and the "featured" rail is silently just the first six in sort
     * order — which is what it was until now. These four are the ones with the
     * best photographs and the widest spread of category: a shared morning, a
     * full day, a sunset for two, and a private charter.
     *
     * @var list<string>
     */
    private const FEATURED = [
        'proino-kolymvitiko',
        'olimeri-tria-nisia',
        'romantiko-dilino',
        'idiotiki-imera-skafos',
    ];

    private const TRIPS = [
        [
            'slug' => 'proino-kolymvitiko', 'el' => 'Πρωινό κολυμβητικό', 'en' => 'Morning swim cruise',
            'summary_el' => 'Τρεις όρμοι πριν ζεστάνει η μέρα.', 'summary_en' => 'Three coves before the day gets hot.',
            'desc_el' => <<<'EL'
Φεύγουμε από τη Μαρίνα Ζέας στις εννιά, όταν η θάλασσα είναι ακόμα λάδι και τα περισσότερα σκάφη δεν έχουν ξεκινήσει. Οι πρώτες δύο ώρες είναι οι πιο ήσυχες της ημέρας και ο λόγος που αυτή η εκδρομή φεύγει τόσο νωρίς.

Σταματάμε σε τρεις όρμους. Στον πρώτο το νερό είναι βαθύ και καθαρό για βουτιές από την πλώρη, στον δεύτερο ρηχό και ζεστό για όποιον προτιμά να περπατήσει μέσα του, και στον τρίτο τρώμε. Δίνουμε μάσκες και σωσίβια, και ο καπετάνιος μένει στο σκάφος όσο είστε στο νερό.

Γυρνάμε στη μαρίνα κατά τη μία, πριν δυναμώσει το μελτέμι και πριν ζεστάνει η μέρα. Είναι εκδρομή για οικογένειες και για όποιον θέλει να έχει το απόγευμά του ελεύθερο.
EL,
            'desc_en' => <<<'EN'
We leave Zea Marina at nine, while the sea is still glass and most boats have not started their engines. The first two hours are the quietest of the day, and they are the reason this trip sails so early.

We stop in three coves. The first is deep and clear enough to dive from the bow, the second shallow and warm for anyone who would rather walk into it, and in the third we eat. Masks and life jackets are on board, and the skipper stays with the boat while you are in the water.

We are back at the marina by one, before the meltemi picks up and before the day gets hot. It suits families, and anyone who wants their afternoon back.
EN,
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 240, 'start' => '09:00', 'min' => 6, 'max' => 24, 'cents' => 5500,
        ],
        [
            'slug' => 'olimeri-tria-nisia', 'el' => 'Ολοήμερη στα τρία νησιά', 'en' => 'Full day, three islands',
            'summary_el' => 'Με γεύμα στο σκάφος και δύο στάσεις για μπάνιο.', 'summary_en' => 'Lunch on board and two swim stops.',
            'desc_el' => <<<'EL'
Μια ολόκληρη μέρα στον Σαρωνικό, σε τρία νησιά που δεν μοιάζουν καθόλου μεταξύ τους. Φεύγουμε στις οκτώ και μισή και γυρνάμε με το φως να πέφτει.

Στην Αίγινα δένουμε στο λιμάνι και έχετε χρόνο για τον ναό της Αφαίας ή απλώς για καφέ στην προκυμαία. Στην Αγκίστρι σταματάμε ανοιχτά για μπάνιο σε νερό που έχει το χρώμα που βλέπετε στις φωτογραφίες και σπάνια αλλού. Στον Πόρο περπατάμε στο σοκάκι κάτω από το ρολόι.

Το γεύμα γίνεται στο σκάφος, ψάρι της ημέρας με σαλάτα και κρασί χύμα, και σερβίρεται ανάμεσα στις δύο στάσεις για μπάνιο. Δεν είναι εκδρομή που τρέχει: τα νησιά είναι τρία γιατί η μέρα είναι μεγάλη, όχι για να προλάβουμε.
EL,
            'desc_en' => <<<'EN'
A full day in the Saronic, across three islands that have almost nothing in common. We leave at half past eight and come back as the light drops.

On Aegina we tie up in the harbour and there is time for the temple of Aphaia, or simply for a coffee on the quay. Off Agistri we stop in open water to swim, in the colour you see in photographs and rarely anywhere else. On Poros we walk the lane under the clock tower.

Lunch is cooked on board — the day's fish with salad and house wine — and served between the two swimming stops. This is not a trip that rushes: there are three islands because the day is long, not because we are trying to fit them in.
EN,
            'category' => ProductCategory::SharedFullDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 480, 'start' => '08:30', 'min' => 8, 'max' => 40, 'cents' => 9500,
        ],
        [
            'slug' => 'apogevmatino-psarema', 'el' => 'Απογευματινό ψάρεμα', 'en' => 'Afternoon fishing trip',
            'summary_el' => 'Με τον καπετάνιο και τα σύνεργά του.', 'summary_en' => 'With the skipper and his tackle.',
            'desc_el' => <<<'EL'
Βγαίνουμε στις τρεις με τον καπετάνιο και τα δικά του σύνεργα. Δεν χρειάζεται να ξέρετε ψάρεμα και δεν χρειάζεται να φέρετε τίποτα — καλάμια, δολώματα και πάγος είναι στο σκάφος.

Πάμε σε σημεία που ψαρεύει ο ίδιος εδώ και τριάντα χρόνια, όχι εκεί που πάνε τα τουριστικά. Ανάλογα με τον καιρό και την εποχή θα πιάσετε σκορπίνα, μελανούρι ή γόπα· κάποιες μέρες τίποτα, και αυτό είναι μέρος του ψαρέματος.

Ό,τι πιαστεί καθαρίζεται στο σκάφος και ψήνεται εκεί, ή το παίρνετε σπίτι αν προτιμάτε. Γυρνάμε με το σούρουπο. Μέγιστο δώδεκα άτομα, γιατί περισσότερα καλάμια από αυτά μπερδεύονται μεταξύ τους.
EL,
            'desc_en' => <<<'EN'
We head out at three with the skipper and his own tackle. You do not need to know how to fish and you do not need to bring anything — rods, bait and ice are on the boat.

We go to marks he has fished for thirty years, not to the ones the tourist boats use. Depending on the season and the weather you might land scorpion fish, sea bream or bogue; some days nothing at all, which is also part of fishing.

Whatever is caught is cleaned on board and grilled there, or you take it home if you would rather. We come back at dusk. Twelve people maximum, because more rods than that simply tangle.
EN,
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 300, 'start' => '15:00', 'min' => 4, 'max' => 12, 'cents' => 7000,
        ],
        [
            'slug' => 'romantiko-dilino', 'el' => 'Ρομαντικό δείπνο εν πλω', 'en' => 'Dinner under way',
            'summary_el' => 'Δύο άτομα, ένα τραπέζι στην πλώρη.', 'summary_en' => 'Two people, one table on the bow.',
            'desc_el' => <<<'EL'
Ένα τραπέζι στην πλώρη, δύο άτομα, και το σκάφος δικό σας για τρεισήμισι ώρες. Φεύγουμε στις εφτάμισι, την ώρα που ο ήλιος αρχίζει να χαμηλώνει πάνω από τη Σαλαμίνα.

Το δείπνο είναι τεσσάρων πιάτων και μαγειρεύεται εν πλω: ορεκτικά ενώ βγαίνουμε από το λιμάνι, κυρίως πιάτο αγκυροβολημένοι σε ήσυχο όρμο, γλυκό όταν έχει σκοτεινιάσει. Το κρασί το διαλέγετε εσείς από τη λίστα όταν κλείνετε.

Το πλήρωμα είναι δύο άτομα και μένει στην πρύμνη. Αν έχετε λόγο να το θυμάστε — επέτειος, πρόταση, κάτι δικό σας — πείτε το μας όταν κλείνετε και θα το φροντίσουμε χωρίς να το κάνουμε παράσταση.
EL,
            'desc_en' => <<<'EN'
One table on the bow, two people, and the boat is yours for three and a half hours. We leave at half past seven, as the sun starts to drop behind Salamina.

Dinner is four courses and is cooked under way: starters as we clear the harbour, the main at anchor in a quiet cove, dessert once it is properly dark. You choose the wine from the list when you book.

There are two crew and they stay aft. If there is a reason you will remember the evening — an anniversary, a proposal, something of your own — tell us when you book and we will take care of it without turning it into a performance.
EN,
            'category' => ProductCategory::Sunset, 'mode' => BookingMode::PerVessel,
            'minutes' => 210, 'start' => '19:30', 'min' => 2, 'max' => 8, 'cents' => 38000,
        ],
        [
            'slug' => 'idiotiki-imera-skafos', 'el' => 'Ιδιωτική ημέρα με σκάφος', 'en' => 'Private day on the water',
            'summary_el' => 'Το σκάφος δικό σας, η διαδρομή δική σας.', 'summary_en' => 'Your boat, your route.',
            'desc_el' => <<<'EL'
Το σκάφος δικό σας από τις δέκα το πρωί ως τις έξι το απόγευμα, και η διαδρομή δική σας. Λέτε στον καπετάνιο τι σας ενδιαφέρει — μπάνιο, ένα νησί, ένα ψαροχώρι για φαγητό — και τη φτιάχνει μαζί σας το πρωί που θα φύγετε, με βάση τον καιρό της ημέρας.

Χωράει δώδεκα άτομα άνετα. Υπάρχει σκιά σε όλο το κατάστρωμα, ψυγείο, ντους στην πρύμνη και μουσική που ελέγχετε εσείς. Πετσέτες, μάσκες και σωσίβια είναι στο σκάφος.

Το φαγητό είτε το φέρνετε είτε το αναλαμβάνουμε εμείς — πείτε μας όταν κλείνετε. Η τιμή είναι για ολόκληρο το σκάφος, όχι ανά άτομο, οπότε δεν αλλάζει αν είστε τέσσερις ή δώδεκα.
EL,
            'desc_en' => <<<'EN'
The boat is yours from ten in the morning until six, and so is the route. You tell the skipper what interests you — swimming, an island, a fishing village for lunch — and he plans it with you on the morning you sail, around that day's weather.

It takes twelve comfortably. There is shade across the whole deck, a fridge, a shower at the stern, and music you control. Towels, masks and life jackets are on board.

Bring your own food or let us arrange it — tell us when you book. The price is for the whole boat rather than per person, so it does not change whether there are four of you or twelve.
EN,
            'category' => ProductCategory::PrivateFullDay, 'mode' => BookingMode::PerVessel,
            'minutes' => 480, 'start' => '10:00', 'min' => 1, 'max' => 12, 'cents' => 62000,
        ],
        [
            'slug' => 'spilies-kai-ormoi', 'el' => 'Σπηλιές και όρμοι', 'en' => 'Caves and coves',
            'summary_el' => 'Με ταχύπλοο εκεί που δεν φτάνουν τα μεγάλα.', 'summary_en' => 'By RIB, where the big boats cannot go.',
            'desc_el' => <<<'EL'
Με ταχύπλοο, εκεί που δεν φτάνουν τα μεγάλα σκάφη. Τρεις ώρες σε ακτογραμμή που από τη στεριά δεν φαίνεται καθόλου.

Μπαίνουμε σε δύο θαλάσσιες σπηλιές — στη μία με το σκάφος, στη δεύτερη κολυμπώντας από την είσοδο, που είναι χαμηλή και θέλει λίγη προσοχή. Ανάμεσά τους σταματάμε σε δύο όρμους χωρίς πρόσβαση από δρόμο, όπου συνήθως δεν υπάρχει κανείς άλλος.

Δέκα άτομα το πολύ και ελάχιστο τέσσερα. Θέλει μαγιό, καπέλο και παπούτσια που δεν σας πειράζει να βραχούν· τα υπόλοιπα τα έχουμε εμείς. Δεν είναι κατάλληλο για πολύ μικρά παιδιά, γιατί το ταχύπλοο πηδάει στο κύμα.
EL,
            'desc_en' => <<<'EN'
By RIB, where the big boats cannot go. Three hours along a coastline that is invisible from the road.

We go into two sea caves — the first with the boat, the second by swimming in from an entrance that is low and needs a little care. Between them we stop in two coves with no road to them, where there is usually nobody else at all.

Ten people maximum and four minimum. Bring a swimsuit, a hat and shoes you do not mind getting wet; we have the rest. It is not suitable for very small children, because a RIB slams in any chop.
EN,
            'category' => ProductCategory::SharedHalfDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 180, 'start' => '11:30', 'min' => 4, 'max' => 10, 'cents' => 6500,
        ],
        [
            'slug' => 'istioploia-me-pania', 'el' => 'Ιστιοπλοΐα με πανιά', 'en' => 'Sailing, engine off',
            'summary_el' => 'Χωρίς μηχανή, μόνο αέρας.', 'summary_en' => 'No engine, just wind.',
            'desc_el' => <<<'EL'
Χωρίς μηχανή. Ανοίγουμε πανιά μόλις βγούμε από τον λιμενοβραχίονα και τα κλείνουμε όταν γυρίσουμε, και ό,τι γίνεται ενδιάμεσα το κάνει ο αέρας.

Δεν χρειάζεται εμπειρία. Αν θέλετε, θα σας βάλουμε στο τιμόνι και θα σας δείξουμε πώς διαβάζεται ο άνεμος στην επιφάνεια του νερού πριν φτάσει στο σκάφος· αν δεν θέλετε, καθίστε στην πλώρη και μην κάνετε τίποτα απολύτως. Και τα δύο είναι σωστές απαντήσεις.

Επτά ώρες, με στάση για μπάνιο και ελαφρύ γεύμα εν πλω. Η διαδρομή εξαρτάται από τον αέρα της ημέρας και αποφασίζεται το πρωί — σε ένα ιστιοφόρο ο προορισμός είναι πρόταση, όχι υπόσχεση.
EL,
            'desc_en' => <<<'EN'
Engine off. We put the sails up as soon as we clear the breakwater and take them down when we come back, and whatever happens in between is the wind's doing.

No experience needed. If you want, we will put you on the helm and show you how to read the wind on the surface of the water before it reaches the boat; if you do not, sit on the bow and do absolutely nothing. Both are correct answers.

Seven hours, with a swimming stop and a light lunch under way. The route depends on the day's wind and is decided that morning — on a sailing boat a destination is a proposal, not a promise.
EN,
            'category' => ProductCategory::SharedFullDay, 'mode' => BookingMode::PerSeat,
            'minutes' => 420, 'start' => '09:30', 'min' => 4, 'max' => 10, 'cents' => 11000,
        ],
        [
            'slug' => 'metafora-sto-nisi', 'el' => 'Μεταφορά στο νησί', 'en' => 'Transfer to the island',
            'summary_el' => 'Απευθείας, χωρίς στάσεις.', 'summary_en' => 'Direct, no stops.',
            'desc_el' => <<<'EL'
Απευθείας μεταφορά στο νησί, χωρίς αναμονή στο λιμάνι και χωρίς αλλαγή πλοίου. Φεύγουμε από τη Μαρίνα Ζέας στην ώρα που βολεύει εσάς και όχι στην ώρα του δρομολογίου.

Η διαδρομή είναι περίπου μιάμιση ώρα ανάλογα με τη θάλασσα. Υπάρχει χώρος για αποσκευές, σκιά και τουαλέτα στο σκάφος, και το πλήρωμα βοηθάει στην επιβίβαση και στην αποβίβαση.

Χρήσιμο για όποιον έχει ραντεβού, πλοίο ή πτήση να προλάβει, και για οικογένειες με μικρά παιδιά που δεν αντέχουν την ουρά. Ρωτήστε μας για επιστροφή την ίδια μέρα — συνήθως γίνεται.
EL,
            'desc_en' => <<<'EN'
A direct transfer to the island, with no waiting at the port and no changing boats. We leave Zea Marina at a time that suits you rather than at a timetable's.

The crossing is about an hour and a half depending on the sea. There is room for luggage, shade and a toilet on board, and the crew help with getting on and off.

Useful if you have an appointment, a ferry or a flight to make, and for families with small children who will not survive the queue. Ask us about coming back the same day — it is usually possible.
EN,
            'category' => ProductCategory::Custom, 'mode' => BookingMode::PerSeat,
            'minutes' => 90, 'start' => '07:30', 'min' => 6, 'max' => 34, 'cents' => 3200,
        ],
        [
            'slug' => 'ilioyasilema-me-krasi', 'el' => 'Ηλιοβασίλεμα με κρασί', 'en' => 'Sunset with a glass of wine',
            'summary_el' => 'Δύο ώρες, ένα ποτήρι, το φως που φεύγει.', 'summary_en' => 'Two hours, one glass, the light going.',
            'desc_el' => <<<'EL'
Βγαίνουμε μία ώρα πριν τη δύση και αγκυροβολούμε ανοιχτά, με τη μύτη του σκάφους στραμμένη εκεί που θα πέσει ο ήλιος.

Στο τραπέζι υπάρχουν τρία ελληνικά κρασιά, τυριά και ό,τι είναι της εποχής. Ο καπετάνιος λέει δύο πράγματα για το καθένα και μετά σας αφήνει ήσυχους — δεν είναι γευσιγνωσία με σημειώσεις, είναι ένα ποτήρι κρασί σε ένα σκάφος στη σωστή ώρα.

Δύο ώρες συνολικά και γυρνάμε με τα φώτα του Πειραιά ανοιγμένα. Είναι η εκδρομή που προτείνουμε σε όποιον έχει μία μόνο ελεύθερη βραδιά.
EL,
            'desc_en' => <<<'EN'
We sail an hour before sunset and anchor in open water, with the bow pointed at the place the sun will go down.

On the table are three Greek wines, some cheese and whatever is in season. The skipper says a couple of things about each and then leaves you alone — this is not a tasting with notes, it is a glass of wine on a boat at the right time of day.

Two hours in all, and we come back with the lights of Piraeus already on. It is the trip we suggest to anyone with only one free evening.
EN,
            'category' => ProductCategory::Sunset, 'mode' => BookingMode::PerSeat,
            'minutes' => 150, 'start' => '19:00', 'min' => 6, 'max' => 28, 'cents' => 4800,
        ],
        [
            'slug' => 'misi-mera-idiotiko', 'el' => 'Ιδιωτικό μισής ημέρας', 'en' => 'Private half day',
            'summary_el' => 'Για μια παρέα που θέλει τον χρόνο της.', 'summary_en' => 'For a group that wants its own pace.',
            'desc_el' => <<<'EL'
Μισή μέρα με το σκάφος δικό σας, για όποιον δεν θέλει να δεσμεύσει ολόκληρη. Διαλέγετε πρωί ή απόγευμα όταν κλείνετε.

Το πρωινό φεύγει στις δέκα και προλαβαίνει δύο όρμους με ήσυχο νερό. Το απογευματινό φεύγει στις τρεις, πιάνει τον ήλιο χαμηλά και γυρνάει με το σούρουπο. Ο καπετάνιος προτείνει διαδρομή ανάλογα με τον αέρα, αλλά αποφασίζετε εσείς.

Τιμή για ολόκληρο το σκάφος. Χωράει άνετα οκτώ άτομα, υπάρχει σκιά και ψυγείο, και μπορείτε να φέρετε φαγητό και ποτό δικό σας χωρίς επιπλέον χρέωση.
EL,
            'desc_en' => <<<'EN'
Half a day with the boat to yourselves, for anyone who does not want to commit a whole one. You pick morning or afternoon when you book.

The morning leaves at ten and gets to two coves while the water is still calm. The afternoon leaves at three, catches the sun low and comes back at dusk. The skipper will suggest a route based on the wind, but the decision is yours.

Priced for the whole boat. It takes eight comfortably, there is shade and a fridge, and you can bring your own food and drink at no extra charge.
EN,
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
                    // ADR-0027. Varied, and lower for the small boats: a RIB
                    // stops sailing well before a 48-seat kaiki does, and a
                    // fleet on one number demonstrates nothing.
                    'max_wind_bft' => $spec['wind'],
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
                    'description' => ['el' => $trip['desc_el'], 'en' => $trip['desc_en']],
                    'duration_minutes' => $trip['minutes'],
                    'default_start_time' => $trip['start'],
                    'check_in_offset_minutes' => 30,
                    'min_pax' => $trip['min'],
                    'max_pax' => min($trip['max'], $vessel->capacity_max),
                    'status' => ProductStatus::Active,
                    'is_featured' => in_array($trip['slug'], self::FEATURED, true),
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
