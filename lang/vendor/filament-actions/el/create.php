<?php

declare(strict_types=1);

/*
| Filament's Greek, corrected where it glues a noun on uninflected.
|
| «Προσθήκη :label» and «Δημιουργία :label» read «Προσθήκη Ανακοίνωση» and
| «Δημιουργία Έμπορος» — a Greek noun after these needs the genitive, and one
| template cannot inflect every model label. So the button says «Προσθήκη» on
| its own (the page it sits on already names the thing) and the heading puts
| the label after a colon, where the nominative is correct. The modal's own
| buttons match the create page's. Only the keys that change are here;
| Laravel merges the rest from the package.
*/

return [

    'single' => [

        'label' => 'Προσθήκη',

        'modal' => [
            'heading' => 'Νέα εγγραφή: :label',

            'actions' => [
                'create' => [
                    'label' => 'Αποθήκευση',
                ],
                'create_another' => [
                    'label' => 'Αποθήκευση και νέα εγγραφή',
                ],
            ],
        ],

    ],

];
