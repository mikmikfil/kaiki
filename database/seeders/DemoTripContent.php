<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Support\TripPageContent;

/**
 * The trip pages' optional content for the demo operator (2026-09-16).
 *
 * «Τι θα ζήσετε», the programme, and what is included, not included and worth
 * bringing, per trip slug — the same words the operator's WordPress demo site
 * shows, with English beside them. Used by {@see DemoFleetSeeder} and
 * {@see DemoBookableSeeder}, which write the trips, so a fresh seed and the
 * development database carry the same content.
 *
 * One trip, `metafora-sto-nisi`, has almost nothing — no programme, no
 * exclusions, nothing to bring — on purpose: it is the page that shows every
 * empty section closing up rather than printing a heading over nothing.
 *
 * Not a seeder itself: a list of attributes the two seeders spread into their
 * `updateOrCreate()`. The itinerary goes through {@see TripPageContent}, the
 * same code the panel form saves with, so the demo rows have exactly the shape
 * an operator's would.
 */
final class DemoTripContent
{
    /**
     * @var array<string, array{
     *     highlights: array{el: list<string>, en: list<string>},
     *     itinerary: list<array{0: string, 1: string, 2: string}>,
     *     includes: array{el: list<string>, en: list<string>},
     *     excludes: array{el: list<string>, en: list<string>},
     *     bring: array{el: list<string>, en: list<string>}
     * }>
     */
    private const TRIPS = [
        'proino-kolymvitiko' => [
            'highlights' => [
                'el' => ['Τρεις στάσεις για μπάνιο σε όρμους με κρυστάλλινα νερά', 'Φεύγουμε νωρίς, πριν από τον κόσμο και τη ζέστη', 'Μάσκες και αναπνευστήρες για όλους'],
                'en' => ['Three swimming stops in coves with crystal-clear water', 'We leave early, ahead of the crowds and the heat', 'Masks and snorkels for everyone'],
            ],
            'itinerary' => [
                ['09:00', 'Επιβίβαση στη Μαρίνα Ζέας', 'Boarding at Zea Marina'],
                ['09:45', 'Πρώτη στάση για μπάνιο στον όρμο της Βάρκιζας', 'First swimming stop in the bay of Vari'],
                ['10:45', 'Κολύμπι και καφές στο κατάστρωμα', 'Swimming and coffee on deck'],
                ['11:45', 'Τελευταία βουτιά σε έναν κρυφό όρμο', 'A last swim in a hidden cove'],
                ['13:00', 'Επιστροφή στη μαρίνα', 'Back at the marina'],
            ],
            'includes' => [
                'el' => ['Καπετάνιος και πλήρωμα', 'Καφές, νερό και φρούτα', 'Μάσκες και αναπνευστήρες', 'Ασφάλεια επιβατών'],
                'en' => ['Skipper and crew', 'Coffee, water and fruit', 'Masks and snorkels', 'Passenger insurance'],
            ],
            'excludes' => [
                'el' => ['Μεταφορά από και προς τη μαρίνα', 'Αλκοολούχα ποτά'],
                'en' => ['Transfers to and from the marina', 'Alcoholic drinks'],
            ],
            'bring' => [
                'el' => ['Μαγιό και πετσέτα', 'Αντηλιακό', 'Καπέλο και γυαλιά ηλίου'],
                'en' => ['Swimsuit and towel', 'Sunscreen', 'Hat and sunglasses'],
            ],
        ],
        'olimeri-tria-nisia' => [
            'highlights' => [
                'el' => ['Αίγινα, Αγκίστρι και Μονή σε μία μέρα', 'Ελεύθερος χρόνος για φαγητό στην Αίγινα', 'Μπάνιο στα τιρκουάζ νερά της Μονής'],
                'en' => ['Aegina, Agistri and Moni in one day', 'Free time for lunch on Aegina', 'A swim in the turquoise water of Moni'],
            ],
            'itinerary' => [
                ['08:30', 'Αναχώρηση από τη Μαρίνα Ζέας', 'Departure from Zea Marina'],
                ['10:30', 'Αγκίστρι: μπάνιο στο Δραγονέρα', 'Agistri: a swim at Dragonera'],
                ['12:30', 'Μονή: κολύμπι και περίπατος με τα παγόνια', 'Moni: swimming and a walk among the peacocks'],
                ['14:00', 'Αίγινα: ελεύθερος χρόνος για φαγητό', 'Aegina: free time for lunch'],
                ['17:30', 'Επιστροφή στον Πειραιά', 'Back in Piraeus'],
            ],
            'includes' => [
                'el' => ['Καπετάνιος και πλήρωμα', 'Ελαφρύ πρωινό εν πλω', 'Νερό και αναψυκτικά', 'Ασφάλεια επιβατών'],
                'en' => ['Skipper and crew', 'Light breakfast on board', 'Water and soft drinks', 'Passenger insurance'],
            ],
            'excludes' => [
                'el' => ['Γεύμα στην Αίγινα', 'Αλκοολούχα ποτά'],
                'en' => ['Lunch on Aegina', 'Alcoholic drinks'],
            ],
            'bring' => [
                'el' => ['Μαγιό και πετσέτα', 'Αντηλιακό', 'Μετρητά για την Αίγινα'],
                'en' => ['Swimsuit and towel', 'Sunscreen', 'Cash for Aegina'],
            ],
        ],
        'spilies-kai-ormoi' => [
            'highlights' => [
                'el' => ['Με ταχύπλοο σε σπηλιές που δεν φτάνουν τα μεγάλα σκάφη', 'Κολύμπι μέσα στη Γαλάζια Σπηλιά', 'Μικρή παρέα, το πολύ 12 άτομα'],
                'en' => ['By speedboat to caves the big boats cannot reach', 'A swim inside the Blue Cave', 'A small group, twelve people at most'],
            ],
            'itinerary' => [
                ['10:00', 'Αναχώρηση με ταχύπλοο', 'Departure by speedboat'],
                ['10:40', 'Γαλάζια Σπηλιά', 'The Blue Cave'],
                ['11:30', 'Μπάνιο σε απάτητο όρμο', 'A swim in an untouched cove'],
                ['13:00', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Κυβερνήτης', 'Νερό', 'Σωσίβια για όλους'],
                'en' => ['Skipper', 'Water', 'Life jackets for everyone'],
            ],
            'excludes' => ['el' => ['Φαγητό'], 'en' => ['Food']],
            'bring' => [
                'el' => ['Μαγιό', 'Αντηλιακό', 'Αδιάβροχη θήκη για κινητό'],
                'en' => ['Swimsuit', 'Sunscreen', 'A waterproof phone pouch'],
            ],
        ],
        'iliovasilema-aigina' => [
            'highlights' => [
                'el' => ['Το ηλιοβασίλεμα πίσω από τον ναό της Αφαίας', 'Ένα ποτήρι κρασί και μεζεδάκια', 'Επιστροφή με τα φώτα του Πειραιά'],
                'en' => ['The sunset behind the temple of Aphaia', 'A glass of wine and small plates', 'Back with the lights of Piraeus ahead'],
            ],
            'itinerary' => [
                ['18:00', 'Επιβίβαση', 'Boarding'],
                ['19:00', 'Βουτιά στα ανοιχτά της Αίγινας', 'A swim off Aegina'],
                ['20:15', 'Ηλιοβασίλεμα με κρασί', 'Sunset with wine'],
                ['21:00', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Ένα ποτήρι κρασί', 'Μεζεδάκια', 'Νερό'],
                'en' => ['A glass of wine', 'Small plates', 'Water'],
            ],
            'excludes' => ['el' => ['Επιπλέον ποτά'], 'en' => ['Further drinks']],
            'bring' => [
                'el' => ['Ζακέτα για την επιστροφή', 'Φωτογραφική μηχανή'],
                'en' => ['A jacket for the way back', 'A camera'],
            ],
        ],
        'apogevmatino-psarema' => [
            'highlights' => [
                'el' => ['Ψάρεμα με τον καπετάνιο και τα σύνεργά του', 'Μαθαίνετε να δένετε αγκίστρι και να διαβάζετε τον βυθό', 'Όσα πιάσετε τα ψήνουμε στο σκάφος'],
                'en' => ['Fishing with the skipper and his tackle', 'Learn to tie a hook and read the seabed', 'Whatever you catch, we grill on board'],
            ],
            'itinerary' => [
                ['15:00', 'Αναχώρηση', 'Departure'],
                ['15:45', 'Πρώτο σημείο για ψάρεμα', 'First fishing spot'],
                ['17:30', 'Ψήσιμο της ψαριάς', 'Grilling the catch'],
                ['20:00', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Εξοπλισμός ψαρέματος', 'Δολώματα', 'Ψήσιμο και σαλάτα'],
                'en' => ['Fishing gear', 'Bait', 'Grilling and a salad'],
            ],
            'excludes' => ['el' => ['Αλκοολούχα ποτά'], 'en' => ['Alcoholic drinks']],
            'bring' => [
                'el' => ['Κλειστά παπούτσια', 'Καπέλο'],
                'en' => ['Closed shoes', 'A hat'],
            ],
        ],
        'istioploia-me-pania' => [
            'highlights' => [
                'el' => ['Χωρίς μηχανή, μόνο αέρας', 'Πιάνετε το τιμόνι αν θέλετε', 'Ελαφρύ γεύμα εν πλω'],
                'en' => ['No engine, only wind', 'Take the helm if you like', 'A light lunch on board'],
            ],
            'itinerary' => [
                ['09:30', 'Επιβίβαση και ενημέρωση ασφαλείας', 'Boarding and safety briefing'],
                ['10:00', 'Ανοίγουμε πανιά', 'Sails up'],
                ['13:00', 'Στάση για μπάνιο και γεύμα', 'A stop for a swim and lunch'],
                ['16:30', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Κυβερνήτης και βοηθός', 'Ελαφρύ γεύμα', 'Νερό και αναψυκτικά'],
                'en' => ['Skipper and mate', 'A light lunch', 'Water and soft drinks'],
            ],
            'excludes' => ['el' => ['Μεταφορά'], 'en' => ['Transfers']],
            'bring' => [
                'el' => ['Αντιολισθητικά παπούτσια', 'Αντηλιακό', 'Αντιανεμικό'],
                'en' => ['Non-slip shoes', 'Sunscreen', 'A windbreaker'],
            ],
        ],
        'romantiko-dilino' => [
            'highlights' => [
                'el' => ['Δείπνο τριών πιάτων εν πλω', 'Όλο το σκάφος για την παρέα σας', 'Ιδανικό για επέτειο ή πρόταση γάμου'],
                'en' => ['A three-course dinner under way', 'The whole boat for the two of you', 'Made for an anniversary or a proposal'],
            ],
            'itinerary' => [
                ['19:00', 'Επιβίβαση με σαμπάνια', 'Boarding with champagne'],
                ['20:00', 'Δείπνο στο κατάστρωμα', 'Dinner on deck'],
                ['21:30', 'Επιστροφή κάτω από τα αστέρια', 'Back under the stars'],
            ],
            'includes' => [
                'el' => ['Δείπνο τριών πιάτων', 'Μπουκάλι κρασί', 'Πλήρωμα'],
                'en' => ['A three-course dinner', 'A bottle of wine', 'Crew'],
            ],
            'excludes' => [
                'el' => ['Λουλούδια και διακόσμηση (κατόπιν αιτήματος)'],
                'en' => ['Flowers and decoration (on request)'],
            ],
            'bring' => ['el' => ['Ζακέτα'], 'en' => ['A jacket']],
        ],
        'idiotiki-imera-skafos' => [
            'highlights' => [
                'el' => ['Εσείς διαλέγετε πού θα πάμε', 'Ολόκληρο το σκάφος για την παρέα σας', 'Πρόγραμμα φτιαγμένο μαζί με τον καπετάνιο'],
                'en' => ['You choose where we go', 'The whole boat for your group', 'A plan made together with the skipper'],
            ],
            'itinerary' => [
                ['10:00', 'Αναχώρηση την ώρα που σας βολεύει', 'Departure at the time that suits you'],
                ['', 'Στάσεις για μπάνιο όπου θέλετε', 'Swimming stops wherever you like'],
                ['', 'Φαγητό σε ταβέρνα νησιού ή εν πλω', 'Lunch at an island taverna or on board'],
                ['18:00', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Καπετάνιος και πλήρωμα', 'Καύσιμα', 'Νερό, αναψυκτικά, φρούτα'],
                'en' => ['Skipper and crew', 'Fuel', 'Water, soft drinks, fruit'],
            ],
            'excludes' => [
                'el' => ['Φαγητό', 'Λιμενικά τέλη νησιών'],
                'en' => ['Food', 'Island harbour fees'],
            ],
            'bring' => [
                'el' => ['Μαγιό και πετσέτα', 'Αντηλιακό'],
                'en' => ['Swimsuit and towel', 'Sunscreen'],
            ],
        ],
        'idiotiki-naulosi-imeras' => [
            'highlights' => [
                'el' => ['Καταμαράν έως 60 ατόμων', 'Για εταιρικές εκδρομές και μεγάλες παρέες', 'Δυνατότητα catering'],
                'en' => ['A catamaran for up to 60 people', 'For company outings and large groups', 'Catering available'],
            ],
            'itinerary' => [
                ['09:00', 'Επιβίβαση', 'Boarding'],
                ['11:00', 'Πρώτη στάση για μπάνιο', 'First swimming stop'],
                ['14:00', 'Φαγητό', 'Lunch'],
                ['17:00', 'Επιστροφή', 'Return'],
            ],
            'includes' => [
                'el' => ['Πλήρωμα τεσσάρων ατόμων', 'Καύσιμα', 'Ηχοσύστημα'],
                'en' => ['A crew of four', 'Fuel', 'Sound system'],
            ],
            'excludes' => ['el' => ['Catering', 'Ποτά'], 'en' => ['Catering', 'Drinks']],
            'bring' => ['el' => ['Μαγιό και πετσέτα'], 'en' => ['Swimsuit and towel']],
        ],
        'metafora-sto-nisi' => [
            'highlights' => [
                'el' => ['Γρήγορη μεταφορά σε Αίγινα, Αγκίστρι ή Πόρο', 'Όποια ώρα σας βολεύει', 'Χωρίς ουρές στο λιμάνι'],
                'en' => ['A fast transfer to Aegina, Agistri or Poros', 'At whatever time suits you', 'No queues at the port'],
            ],
            'itinerary' => [],
            'includes' => ['el' => ['Κυβερνήτης', 'Αποσκευές'], 'en' => ['Skipper', 'Luggage']],
            'excludes' => ['el' => [], 'en' => []],
            'bring' => ['el' => [], 'en' => []],
        ],
    ];

    /**
     * The attributes to spread into one trip's `updateOrCreate()`; empty for a
     * slug with no demo content.
     *
     * An empty list is left out rather than written: a null assigned to a
     * translatable attribute is stored as `{"el": null}`, not as the null
     * column that means «not configured» — so {@see self::emptyColumns()} is
     * what clears one in an existing database.
     *
     * @return array<string, mixed>
     */
    public static function for(string $slug): array
    {
        $trip = self::TRIPS[$slug] ?? null;

        if ($trip === null) {
            return [];
        }

        $rows = array_map(static fn (array $stop): array => [
            'time' => $stop[0] !== '' ? $stop[0] : null,
            'name' => ['el' => $stop[1], 'en' => $stop[2]],
            'description' => ['el' => '', 'en' => ''],
        ], $trip['itinerary']);

        return array_filter([
            'highlights' => TripPageContent::listFromForm($trip['highlights']),
            'itinerary_stops' => TripPageContent::itineraryFromRows($rows),
            'includes' => TripPageContent::listFromForm($trip['includes']),
            'excludes' => TripPageContent::listFromForm($trip['excludes']),
            'what_to_bring' => TripPageContent::listFromForm($trip['bring']),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The content columns a slug has **no** demo content for, to be set to a
     * real null with a raw update.
     *
     * @return list<string>
     */
    public static function emptyColumns(string $slug): array
    {
        if (! isset(self::TRIPS[$slug])) {
            return [];
        }

        return array_values(array_diff(
            ['highlights', 'itinerary_stops', 'includes', 'excludes', 'what_to_bring'],
            array_keys(self::for($slug)),
        ));
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::TRIPS);
    }
}
