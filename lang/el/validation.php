<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Μηνύματα επικύρωσης (I18N-1)
|--------------------------------------------------------------------------
|
| Hand-written rather than pulled from `laravel-lang/lang`: that package is not
| on the ADR-0019 shortlist, and installing something unapproved is a hard stop
| (ARC-21). Every key here has a twin in `lang/en/validation.php`, enforced by
| LangKeyParityTest.
|
| Addressed formally (πληθυντικός ευγενείας) throughout — these messages reach
| operators and their guests, not developers.
|
*/

return [

    'accepted' => 'Το πεδίο :attribute πρέπει να γίνει αποδεκτό.',
    'accepted_if' => 'Το πεδίο :attribute πρέπει να γίνει αποδεκτό όταν το :other είναι :value.',
    'active_url' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση URL.',
    'after' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία μετά τις :date.',
    'after_or_equal' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία ίση ή μεταγενέστερη από :date.',
    'alpha' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα.',
    'alpha_dash' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα, αριθμούς, παύλες και κάτω παύλες.',
    'alpha_num' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα και αριθμούς.',
    'any_of' => 'Το πεδίο :attribute δεν είναι έγκυρο.',
    'array' => 'Το πεδίο :attribute πρέπει να είναι πίνακας.',
    'ascii' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο αλφαριθμητικούς χαρακτήρες και σύμβολα ενός byte.',
    'before' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία πριν από τις :date.',
    'before_or_equal' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία ίση ή προγενέστερη από :date.',
    'between' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει από :min έως :max στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι από :min έως :max kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι από :min έως :max.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει από :min έως :max χαρακτήρες.',
    ],
    'boolean' => 'Το πεδίο :attribute πρέπει να είναι ναι ή όχι.',
    'can' => 'Το πεδίο :attribute περιέχει μη επιτρεπτή τιμή.',
    'confirmed' => 'Η επιβεβαίωση του πεδίου :attribute δεν ταιριάζει.',
    'contains' => 'Από το πεδίο :attribute λείπει μια απαιτούμενη τιμή.',
    'current_password' => 'Ο κωδικός πρόσβασης δεν είναι σωστός.',
    'date' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη ημερομηνία.',
    'date_equals' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία ίση με :date.',
    'date_format' => 'Το πεδίο :attribute πρέπει να έχει τη μορφή :format.',
    'decimal' => 'Το πεδίο :attribute πρέπει να έχει :decimal δεκαδικά ψηφία.',
    'declined' => 'Το πεδίο :attribute πρέπει να απορριφθεί.',
    'declined_if' => 'Το πεδίο :attribute πρέπει να απορριφθεί όταν το :other είναι :value.',
    'different' => 'Τα πεδία :attribute και :other πρέπει να διαφέρουν.',
    'digits' => 'Το πεδίο :attribute πρέπει να έχει :digits ψηφία.',
    'digits_between' => 'Το πεδίο :attribute πρέπει να έχει από :min έως :max ψηφία.',
    'dimensions' => 'Οι διαστάσεις της εικόνας :attribute δεν είναι έγκυρες.',
    'distinct' => 'Το πεδίο :attribute έχει διπλότυπη τιμή.',
    'doesnt_contain' => 'Το πεδίο :attribute δεν πρέπει να περιέχει κάποιο από τα εξής: :values.',
    'doesnt_end_with' => 'Το πεδίο :attribute δεν πρέπει να τελειώνει με κάποιο από τα εξής: :values.',
    'doesnt_start_with' => 'Το πεδίο :attribute δεν πρέπει να αρχίζει με κάποιο από τα εξής: :values.',
    'email' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση email.',
    'encoding' => 'Το πεδίο :attribute πρέπει να έχει κωδικοποίηση :encoding.',
    'ends_with' => 'Το πεδίο :attribute πρέπει να τελειώνει με κάποιο από τα εξής: :values.',
    'enum' => 'Η επιλογή για το :attribute δεν είναι έγκυρη.',
    'exists' => 'Η επιλογή για το :attribute δεν είναι έγκυρη.',
    'extensions' => 'Το αρχείο :attribute πρέπει να έχει μία από τις εξής επεκτάσεις: :values.',
    'file' => 'Το πεδίο :attribute πρέπει να είναι αρχείο.',
    'filled' => 'Το πεδίο :attribute πρέπει να έχει τιμή.',
    'gt' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει περισσότερα από :value στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι μεγαλύτερο από :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο από :value.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει περισσότερους από :value χαρακτήρες.',
    ],
    'gte' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει :value στοιχεία ή περισσότερα.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι :value kilobytes ή μεγαλύτερο.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο ή ίσο με :value.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει :value χαρακτήρες ή περισσότερους.',
    ],
    'hex_color' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο δεκαεξαδικό χρώμα.',
    'image' => 'Το πεδίο :attribute πρέπει να είναι εικόνα.',
    'in' => 'Η επιλογή για το :attribute δεν είναι έγκυρη.',
    'in_array' => 'Το πεδίο :attribute πρέπει να υπάρχει στο :other.',
    'in_array_keys' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον ένα από τα εξής κλειδιά: :values.',
    'integer' => 'Το πεδίο :attribute πρέπει να είναι ακέραιος αριθμός.',
    'ip' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IP.',
    'ipv4' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IPv4.',
    'ipv6' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IPv6.',
    'json' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο κείμενο JSON.',
    'list' => 'Το πεδίο :attribute πρέπει να είναι λίστα.',
    'lowercase' => 'Το πεδίο :attribute πρέπει να είναι με πεζά γράμματα.',
    'lt' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει λιγότερα από :value στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι μικρότερο από :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο από :value.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει λιγότερους από :value χαρακτήρες.',
    ],
    'lte' => [
        'array' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :value στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι :value kilobytes ή μικρότερο.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο ή ίσο με :value.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει :value χαρακτήρες ή λιγότερους.',
    ],
    'mac_address' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση MAC.',
    'max' => [
        'array' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :max στοιχεία.',
        'file' => 'Το αρχείο :attribute δεν πρέπει να ξεπερνά τα :max kilobytes.',
        'numeric' => 'Το πεδίο :attribute δεν πρέπει να είναι μεγαλύτερο από :max.',
        'string' => 'Το πεδίο :attribute δεν πρέπει να ξεπερνά τους :max χαρακτήρες.',
    ],
    'max_digits' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :max ψηφία.',
    'mimes' => 'Το αρχείο :attribute πρέπει να είναι τύπου: :values.',
    'mimetypes' => 'Το αρχείο :attribute πρέπει να είναι τύπου: :values.',
    'min' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει τουλάχιστον :min στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι τουλάχιστον :min kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι τουλάχιστον :min.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει τουλάχιστον :min χαρακτήρες.',
    ],
    'min_digits' => 'Το πεδίο :attribute πρέπει να έχει τουλάχιστον :min ψηφία.',
    'missing' => 'Το πεδίο :attribute δεν πρέπει να υπάρχει.',
    'missing_if' => 'Το πεδίο :attribute δεν πρέπει να υπάρχει όταν το :other είναι :value.',
    'missing_unless' => 'Το πεδίο :attribute δεν πρέπει να υπάρχει εκτός αν το :other είναι :value.',
    'missing_with' => 'Το πεδίο :attribute δεν πρέπει να υπάρχει όταν υπάρχει το :values.',
    'missing_with_all' => 'Το πεδίο :attribute δεν πρέπει να υπάρχει όταν υπάρχουν τα :values.',
    'multiple_of' => 'Το πεδίο :attribute πρέπει να είναι πολλαπλάσιο του :value.',
    'not_in' => 'Η επιλογή για το :attribute δεν είναι έγκυρη.',
    'not_regex' => 'Η μορφή του πεδίου :attribute δεν είναι έγκυρη.',
    'numeric' => 'Το πεδίο :attribute πρέπει να είναι αριθμός.',
    'password' => [
        'letters' => 'Ο κωδικός :attribute πρέπει να περιέχει τουλάχιστον ένα γράμμα.',
        'mixed' => 'Ο κωδικός :attribute πρέπει να περιέχει τουλάχιστον ένα κεφαλαίο και ένα πεζό γράμμα.',
        'numbers' => 'Ο κωδικός :attribute πρέπει να περιέχει τουλάχιστον έναν αριθμό.',
        'symbols' => 'Ο κωδικός :attribute πρέπει να περιέχει τουλάχιστον ένα σύμβολο.',
        'uncompromised' => 'Ο κωδικός :attribute έχει εμφανιστεί σε διαρροή δεδομένων. Παρακαλούμε επιλέξτε διαφορετικό.',
    ],
    'present' => 'Το πεδίο :attribute πρέπει να υπάρχει.',
    'present_if' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν το :other είναι :value.',
    'present_unless' => 'Το πεδίο :attribute πρέπει να υπάρχει εκτός αν το :other είναι :value.',
    'present_with' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν υπάρχει το :values.',
    'present_with_all' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν υπάρχουν τα :values.',
    'prohibited' => 'Το πεδίο :attribute δεν επιτρέπεται.',
    'prohibited_if' => 'Το πεδίο :attribute δεν επιτρέπεται όταν το :other είναι :value.',
    'prohibited_if_accepted' => 'Το πεδίο :attribute δεν επιτρέπεται όταν το :other είναι αποδεκτό.',
    'prohibited_if_declined' => 'Το πεδίο :attribute δεν επιτρέπεται όταν το :other έχει απορριφθεί.',
    'prohibited_unless' => 'Το πεδίο :attribute δεν επιτρέπεται εκτός αν το :other είναι ένα από τα :values.',
    'prohibits' => 'Το πεδίο :attribute δεν επιτρέπει να υπάρχει το :other.',
    'regex' => 'Η μορφή του πεδίου :attribute δεν είναι έγκυρη.',
    'required' => 'Το πεδίο :attribute είναι υποχρεωτικό.',
    'required_array_keys' => 'Το πεδίο :attribute πρέπει να περιέχει καταχωρίσεις για: :values.',
    'required_if' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other είναι :value.',
    'required_if_accepted' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other είναι αποδεκτό.',
    'required_if_declined' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other έχει απορριφθεί.',
    'required_unless' => 'Το πεδίο :attribute είναι υποχρεωτικό εκτός αν το :other είναι ένα από τα :values.',
    'required_with' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν υπάρχει το :values.',
    'required_with_all' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν υπάρχουν τα :values.',
    'required_without' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν δεν υπάρχει το :values.',
    'required_without_all' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν δεν υπάρχει κανένα από τα :values.',
    'same' => 'Το πεδίο :attribute πρέπει να ταιριάζει με το :other.',
    'size' => [
        'array' => 'Το πεδίο :attribute πρέπει να περιέχει :size στοιχεία.',
        'file' => 'Το αρχείο :attribute πρέπει να είναι :size kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι :size.',
        'string' => 'Το πεδίο :attribute πρέπει να έχει :size χαρακτήρες.',
    ],
    'starts_with' => 'Το πεδίο :attribute πρέπει να αρχίζει με κάποιο από τα εξής: :values.',
    'string' => 'Το πεδίο :attribute πρέπει να είναι κείμενο.',
    'timezone' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη ζώνη ώρας.',

    /*
     * Δεν είναι κανόνας του Laravel — App\Rules\TranslatableRequired (§1.6).
     */
    'translatable_required' => 'Το πεδίο :attribute πρέπει να συμπληρωθεί στα: :locales.',
    'unique' => 'Το :attribute χρησιμοποιείται ήδη.',
    'uploaded' => 'Η μεταφόρτωση του :attribute απέτυχε.',
    'uppercase' => 'Το πεδίο :attribute πρέπει να είναι με κεφαλαία γράμματα.',
    'url' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση URL.',
    'ulid' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο ULID.',
    'uuid' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο UUID.',

    /*
    | Ανά πεδίο υπερβάσεις, με τη σύμβαση "attribute.rule". Κενό μέχρι να το
    | χρειαστεί κάποια φόρμα — ένα δείγμα εδώ θα ήταν ένα κλειδί που δεν
    | μεταφράζεται ποτέ και που ο έλεγχος ισοτιμίας θα φρουρούσε για πάντα.
    */
    'custom' => [],

    /*
    | Φιλικά ονόματα πεδίων. Συμπληρώνονται από τις φόρμες του M1 και μετά.
    */
    'attributes' => [],

];
