<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Personal data (GDR-5, GDR-6, GDR-10, GDR-11)
|--------------------------------------------------------------------------
|
| `LangKeyParityTest` asserts this file and `lang/el/gdpr.php` hold exactly the
| same keys, in both directions.
|
*/

return [

    'export' => [
        'notes' => [
            'documents' => 'Identity and passport numbers are not included in this file, even where they exist. It records only whether a document was held and whether it has since been destroyed.',
            'retention' => 'Document details are destroyed automatically after the retention period the operator has set, counted from the day of the trip.',
        ],

        'document' => [
            'none' => 'No document was recorded.',
            'held' => 'A document was recorded and is retained.',
            'purged' => 'A document was recorded and was destroyed on :date.',
        ],
    ],

    'erasure' => [
        'pseudonym' => 'Erased on request · :date',
    ],

];
