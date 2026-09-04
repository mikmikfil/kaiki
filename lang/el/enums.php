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
        'shade_canopy' => ['label' => 'Τέντα σκίασης'],
        'sound_system' => ['label' => 'Ηχοσύστημα'],
        'fridge' => ['label' => 'Ψυγείο'],
        'snorkelling_gear' => ['label' => 'Εξοπλισμός κατάδυσης'],
        'wc' => ['label' => 'Τουαλέτα'],
        'sun_deck' => ['label' => 'Ηλιακό κατάστρωμα'],
    ],

    'locale' => [
        'el' => ['label' => 'Ελληνικά', 'short' => 'ΕΛ'],
        'en' => ['label' => 'English', 'short' => 'EN'],
    ],

];
