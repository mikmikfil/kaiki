<?php

declare(strict_types=1);

/*
| «Διαγραφή Εκδρομή» → «Διαγραφή: Εκδρομή», the same fix as create.php and
| edit-record.php: after «Διαγραφή» a Greek noun needs the genitive, and one
| template cannot inflect every model label, so the label goes after a colon
| where the nominative is right. Only the keys that change are here.
*/

return [

    'single' => [

        'modal' => [
            'heading' => 'Διαγραφή: :label',
        ],

    ],

    'multiple' => [

        'modal' => [
            'heading' => 'Διαγραφή επιλεγμένων: :label',
        ],

    ],

];
