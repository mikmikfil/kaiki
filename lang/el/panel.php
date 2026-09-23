<?php

declare(strict_types=1);

return [
    'admin' => [
        'brand' => 'Πλατφόρμα Kaiki',
    ],

    'view_frontend' => [
        'label' => 'Η σελίδα σας',
        'title' => 'Ανοίγει τη δημόσια σελίδα σας σε νέα καρτέλα',
        'live' => 'Σε λειτουργία',
    ],

    'groups' => [
        'today' => 'Σήμερα',
        'sales' => 'Πωλήσεις',
        'catalogue' => 'Κατάλογος',
        'fleet' => 'Στόλος',
        'settings' => 'Ρυθμίσεις',
    ],

    // Sidebar labels shorter than the screen's own title (Menu 1, 2026-09-16).
    'nav' => [
        'home' => 'Αρχική',
        'prices' => 'Τιμές',
        'ports' => 'Λιμάνια',
        'related' => 'Σχετικές οθόνες',
    ],

    // «Το προφίλ μου» (2026-09-17).
    'profile' => [
        'salutation' => [
            'label' => 'Πώς να σας αποκαλεί η πλατφόρμα',
            'help' => 'Προαιρετικό. Έτσι σας χαιρετά η αρχική σελίδα, π.χ. «Καλημέρα, Μαρία». Αν μείνει κενό, χρησιμοποιείται μόνο το μικρό σας όνομα.',
        ],
    ],

    // Το μενού σε κουτιά, στο κινητό (κατεύθυνση A, 2026-09-17).
    'mobile_menu' => [
        'open' => 'Μενού',
        'close' => 'Κλείσιμο',
    ],

    'roles' => [
        'heading' => 'Ομάδα',
        'assign' => 'Ανάθεση ρόλου',
        'revoke' => 'Αφαίρεση ρόλου',
    ],

    'errors' => [
        'no_panel_access' => 'Αυτός ο λογαριασμός δεν έχει πρόσβαση σε αυτό το μέρος του Kaiki.',
    ],

    'locale' => [
        'switcher' => 'Γλώσσα',
    ],

    // The two buttons either side of a number field. Read by a screen reader
    // and shown as the tooltip; the button itself is the sign.
    'number' => [
        'decrease' => 'Μείωση',
        'increase' => 'Αύξηση',
    ],

    // «⋯» — the row's and the page's less-used actions, behind one button.
    'more_actions' => 'Περισσότερα',

    // A file field on a touch screen, which has nothing to drag from.
    'upload_pick' => 'Επιλέξτε αρχείο',

];
