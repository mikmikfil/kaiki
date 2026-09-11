<?php

declare(strict_types=1);

// «Δημιουργία Έμπορος» → «Νέα εγγραφή: Έμπορος», and the page's buttons said
// «Δημιούργησε & Δημιούργησε ακόμα ένα». See lang/vendor/filament-actions/el/create.php.
return [

    'title' => 'Νέα εγγραφή: :label',

    'breadcrumb' => 'Νέα εγγραφή',

    'form' => [
        'actions' => [
            'create' => [
                'label' => 'Αποθήκευση',
            ],
            'create_another' => [
                'label' => 'Αποθήκευση και νέα εγγραφή',
            ],
        ],
    ],

];
