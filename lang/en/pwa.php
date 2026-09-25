<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The panel as an app on a phone (PWA)
|--------------------------------------------------------------------------
|
| «Share» and «Add to Home Screen» are the iPhone's own words, so they can be
| found on screen as written.
*/

return [

    'description' => 'Bookings, departures and boarding for your business.',

    'shortcuts' => [
        'boarding' => 'Boarding',
        'calendar' => 'Calendar',
    ],

    'install' => [
        'menu' => 'Install app',
    ],

    'ios' => [
        'heading' => 'Install app',
        'step_share' => 'Tap Share',
        'step_add' => 'Tap Add to Home Screen',
        'done' => 'OK',
    ],

    'offline' => [
        'title' => 'No connection',
        'body' => 'The page will open as soon as there is a signal.',
        'retry' => 'Try again',
        'boarding' => 'Boarding without signal',
    ],

];
