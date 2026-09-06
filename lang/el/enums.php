<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ετικέτες enum (CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| Ένα αρχείο για κάθε ετικέτα enum που βλέπει ο χειριστής. Δείτε το αντίστοιχο
| αγγλικό αρχείο για το γιατί συγκεντρώθηκαν εδώ.
|
*/

return [

    'role' => [
        'owner' => [
            'label' => 'Ιδιοκτήτης',
            'description' => 'Πλήρης πρόσβαση: χρεώσεις, προσωπικό και διαγραφή λογαριασμού.',
        ],
        'manager' => [
            'label' => 'Υπεύθυνος',
            'description' => 'Καθημερινή λειτουργία: εκδρομές, τιμές, κρατήσεις και επιβάτες. Χωρίς χρεώσεις.',
        ],
        'crew' => [
            'label' => 'Πλήρωμα',
            'description' => 'Βλέπει τις σημερινές αναχωρήσεις και την οθόνη επιβίβασης. Τίποτα άλλο.',
        ],
    ],

    'plan' => [
        'trial' => ['label' => 'Δοκιμαστικό'],
        'solo' => ['label' => 'Solo'],
        'fleet' => ['label' => 'Στόλος'],
        'pro' => ['label' => 'Pro'],
    ],

    'tenant_status' => [
        'trialing' => ['label' => 'Δοκιμαστική περίοδος'],
        'active' => ['label' => 'Ενεργός'],
        'past_due' => ['label' => 'Ληξιπρόθεσμη πληρωμή'],
        'read_only' => ['label' => 'Μόνο ανάγνωση'],
        'suspended' => ['label' => 'Σε αναστολή'],
    ],

    'domain_status' => [
        'pending' => ['label' => 'Αναμονή για DNS'],
        'verified' => ['label' => 'Επαληθευμένο'],
        'failed' => ['label' => 'Η επαλήθευση απέτυχε'],
        'disabled' => ['label' => 'Ανενεργό'],
    ],

    'api_key_type' => [
        'publishable' => ['label' => 'Δημόσιο'],
        'secret' => ['label' => 'Μυστικό'],
    ],

    'api_key_environment' => [
        'live' => ['label' => 'Παραγωγή'],
        'test' => ['label' => 'Δοκιμαστικό'],
    ],

    'api_scope' => [
        'products.read' => ['label' => 'Ανάγνωση εκδρομών'],
        'availability.read' => ['label' => 'Ανάγνωση διαθεσιμότητας'],
        'branding.read' => ['label' => 'Ανάγνωση εμφάνισης'],
        'bookings.write' => ['label' => 'Δημιουργία κρατήσεων'],
        'quotes.write' => ['label' => 'Δημιουργία προσφορών'],
        'webhooks.receive' => ['label' => 'Λήψη webhooks'],
    ],

    /*
    | Κάθε γλώσσα γράφεται στη δική της γλώσσα, και στα δύο αρχεία: κάποιος που
    | βλέπει αγγλικά και ψάχνει τα ελληνικά πρέπει να αναγνωρίσει τη λέξη
    | «Ελληνικά», όχι τη λέξη «Greek».
    */
    'extra_pricing' => [
        'per_booking' => ['label' => 'Ανά κράτηση'],
        'per_person' => ['label' => 'Ανά άτομο'],
        'on_request' => ['label' => 'Κατόπιν αιτήματος'],
    ],

    'age_band_pricing' => [
        'multiplier' => ['label' => 'Ποσοστό της βασικής τιμής'],
        'fixed' => ['label' => 'Δική της τιμή'],
    ],

    'booking_mode' => [
        'per_seat' => ['label' => 'Ανά θέση'],
        'per_vessel' => ['label' => 'Ολόκληρο το σκάφος'],
        'quote' => ['label' => 'Κατόπιν προσφοράς'],
    ],

    'deposit_type' => [
        'none' => ['label' => 'Ολόκληρο το ποσό'],
        'percent' => ['label' => 'Ποσοστό του συνόλου'],
        'fixed' => ['label' => 'Σταθερό ποσό'],
    ],

    'product_category' => [
        'shared_full_day' => ['label' => 'Ημερήσια κοινή εκδρομή'],
        'shared_half_day' => ['label' => 'Ημιήμερη κοινή εκδρομή'],
        'private_full_day' => ['label' => 'Ημερήσια ιδιωτική ναύλωση'],
        'private_half_day' => ['label' => 'Ημιήμερη ιδιωτική ναύλωση'],
        'sunset' => ['label' => 'Ηλιοβασίλεμα'],
        'custom' => ['label' => 'Άλλο'],
    ],

    'product_status' => [
        'draft' => ['label' => 'Πρόχειρη'],
        'active' => ['label' => 'Σε πώληση'],
        'inactive' => ['label' => 'Εκτός πώλησης'],
        'archived' => ['label' => 'Αρχειοθετημένη'],
    ],

    'vessel_type' => [
        'catamaran' => ['label' => 'Καταμαράν'],
        'sailing_yacht' => ['label' => 'Ιστιοπλοϊκό'],
        'motor' => ['label' => 'Μηχανοκίνητο'],
        'rib' => ['label' => 'Φουσκωτό'],
        'traditional_kaiki' => ['label' => 'Παραδοσιακό καΐκι'],
    ],

    'vessel_status' => [
        'active' => ['label' => 'Ενεργό'],
        'inactive' => ['label' => 'Ανενεργό'],
        'maintenance' => ['label' => 'Σε συντήρηση'],
    ],

    'vessel_amenity' => [
        // Άνεση στο κατάστρωμα.
        'shade_canopy' => ['label' => 'Τέντα σκίασης'],
        'sun_deck' => ['label' => 'Ηλιακό κατάστρωμα'],
        'air_conditioning' => ['label' => 'Κλιματισμός'],
        'cabin' => ['label' => 'Καμπίνα'],
        'wc' => ['label' => 'Τουαλέτα'],

        // Θάλασσα και μπάνιο.
        'swim_ladder' => ['label' => 'Σκάλα μπάνιου'],
        'freshwater_shower' => ['label' => 'Ντους γλυκού νερού'],
        'snorkelling_gear' => ['label' => 'Εξοπλισμός κατάδυσης'],
        'paddleboard' => ['label' => 'Σανίδα SUP'],
        'fishing_gear' => ['label' => 'Εξοπλισμός ψαρέματος'],
        'beach_towels' => ['label' => 'Πετσέτες θαλάσσης'],

        // Φαγητό και ποτό.
        'fridge' => ['label' => 'Ψυγείο'],
        'drinking_water' => ['label' => 'Πόσιμο νερό'],
        'galley' => ['label' => 'Κουζίνα'],
        'barbecue' => ['label' => 'Ψησταριά'],
        'coffee_machine' => ['label' => 'Καφετιέρα'],

        // Ρεύμα και συνδεσιμότητα.
        'sound_system' => ['label' => 'Ηχοσύστημα'],
        'usb_charging' => ['label' => 'Φόρτιση USB'],
        'wifi' => ['label' => 'Ασύρματο ίντερνετ'],

        // Σε ποιους ταιριάζει το σκάφος.
        'child_life_jackets' => ['label' => 'Παιδικά σωσίβια'],
        'wheelchair_accessible' => ['label' => 'Πρόσβαση ΑμεΑ'],
        'pet_friendly' => ['label' => 'Δεκτά κατοικίδια'],
    ],

    'departure_status' => [
        'scheduled' => ['label' => 'Προγραμματισμένη'],
        'guaranteed' => ['label' => 'Εγγυημένη'],
        'cancelled' => ['label' => 'Ακυρωμένη'],
        'completed' => ['label' => 'Ολοκληρωμένη'],
    ],

    'departure_cancel_reason' => [
        'weather' => ['label' => 'Καιρός'],
        'operator' => ['label' => 'Απόφαση του πλοιοκτήτη'],
        'min_pax' => ['label' => 'Λίγα άτομα'],
        'vessel_booked_privately' => ['label' => 'Ιδιωτική ναύλωση'],
    ],

    'block_reason' => [
        'private_booking' => ['label' => 'Ιδιωτική ναύλωση'],
        'maintenance' => ['label' => 'Συντήρηση'],
        'external_ical' => ['label' => 'Εξωτερικό ημερολόγιο'],
        'manual' => ['label' => 'Χειροκίνητη'],
    ],

    'audit_action' => [
        'vessel.deleted' => ['label' => 'Διαγραφή σκάφους'],
        'product.deleted' => ['label' => 'Διαγραφή εκδρομής'],
        'departure.cancelled' => ['label' => 'Ακύρωση αναχώρησης'],
        'booking.refunded' => ['label' => 'Επιστροφή χρημάτων κράτησης'],
        'gdpr.purged' => ['label' => 'Διαγραφή προσωπικών δεδομένων'],
        'api_key.revoked' => ['label' => 'Ανάκληση κλειδιού API'],
        'record.deleted' => ['label' => 'Διαγραφή εγγραφής'],
        'override.applied' => ['label' => 'Εφαρμογή παράκαμψης'],
    ],

    'availability_rejection' => [
        'product_not_active' => ['label' => 'Η εκδρομή δεν είναι διαθέσιμη αυτή τη στιγμή.'],
        'vessel_not_active' => ['label' => 'Το σκάφος δεν είναι διαθέσιμο αυτή τη στιγμή.'],
        'departure_cancelled' => ['label' => 'Η αναχώρηση ακυρώθηκε.'],
        'lead_time_too_short' => ['label' => 'Η κράτηση έχει κλείσει για αυτή την αναχώρηση.'],
        'too_far_ahead' => ['label' => 'Δεν δεχόμαστε ακόμη κρατήσεις τόσο νωρίς.'],
        'vessel_busy' => ['label' => 'Το σκάφος είναι δεσμευμένο εκείνη την ώρα.'],
        'not_enough_seats' => ['label' => 'Δεν υπάρχουν αρκετές θέσεις.'],
        'legal_capacity_exceeded' => ['label' => 'Ο συνολικός αριθμός επιβαινόντων ξεπερνά το όριο του σκάφους.'],
        'no_counted_pax' => ['label' => 'Χρειάζεται τουλάχιστον ένας επιβάτης που πιάνει θέση.'],
        'tenant_read_only' => ['label' => 'Οι κρατήσεις δεν είναι διαθέσιμες αυτή τη στιγμή.'],
        'off_grid' => ['label' => 'Διαλέξτε ώρα ανά τέταρτο (π.χ. 09:00, 09:15).'],
        'outside_operating_window' => ['label' => 'Η ώρα είναι εκτός του ωραρίου που δέχεται ο πλοιοκτήτης.'],
        'extension_too_long' => ['label' => 'Δεν μπορείτε να παρατείνετε τόσες ώρες.'],
        'dst_non_existent' => ['label' => 'Εκείνη την ημέρα αλλάζει η ώρα και αυτή η ώρα δεν υπάρχει. Διαλέξτε άλλη.'],
        'no_proposed_window' => ['label' => 'Δεν ορίστηκε ώρα αναχώρησης.'],
        'vessel_held' => ['label' => 'Κάποιος άλλος ολοκληρώνει κράτηση για αυτή την ώρα. Δοκιμάστε ξανά σε λίγο.'],
    ],

    'locale' => [
        'el' => ['label' => 'Ελληνικά', 'short' => 'ΕΛ'],
        'en' => ['label' => 'English', 'short' => 'EN'],
    ],

    'font_source' => [
        'system' => ['label' => 'Γραμματοσειρά συστήματος'],
        'google' => ['label' => 'Google Fonts'],
    ],

    'widget_theme' => [
        'light' => ['label' => 'Ανοιχτό'],
        'dark' => ['label' => 'Σκούρο'],
        'auto' => ['label' => 'Όπως ο επισκέπτης'],
    ],

    /*
    | Πάροχοι συνδέσεων και το περιβάλλον τους (data-model §2.7, PAY-4).
    |
    | Τα ονόματα των παρόχων είναι εμπορικά σήματα και παραμένουν στο λατινικό
    | αλφάβητο και στις δύο γλώσσες — ο διαχειριστής που ψάχνει το «Viva» σε μια
    | λίστα ψάχνει τη λέξη που βλέπει στον δικό του πίνακα Viva.
    */
    'integration_provider' => [
        'viva' => ['label' => 'Viva Wallet'],
        'stripe' => ['label' => 'Stripe'],
        'mydata' => ['label' => 'myDATA (ΑΑΔΕ)'],
        'apifon' => ['label' => 'Apifon'],
        'yuboto' => ['label' => 'Yuboto'],
        'twilio' => ['label' => 'Twilio'],
        'postmark' => ['label' => 'Postmark'],
    ],

    /*
    | Σκόπιμα οι ίδιες δύο λέξεις με το `api_key_environment` παραπάνω. Το
    | PAY-11 τα συνδέει άμεσα — η λειτουργία sandbox σημαίνει ένα κλειδί δοκιμής
    | που φτάνει σε στοιχεία δοκιμής της πύλης πληρωμών — και δύο λεξιλόγια για
    | μία έννοια είναι ο τρόπος που ένα ερώτημα καταλήγει να ρωτά το λάθος.
    */
    'credential_environment' => [
        'live' => ['label' => 'Ζωντανό'],
        'test' => ['label' => 'Δοκιμαστικό'],
    ],

];
