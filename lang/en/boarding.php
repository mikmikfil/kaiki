<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Boarding without a signal (OPS-12)
|--------------------------------------------------------------------------
|
| The person using this is standing on a pier with the sun on the screen and
| somebody waiting in front of them. Every phrase is as short as it can be, and
| the most important one says whether the phone has a signal — without it they
| cannot tell whether a tick means "the office knows" or "this phone knows".
*/

return [

    'title' => 'Boarding',

    'online' => 'ONLINE',
    'offline' => 'NO SIGNAL',

    'placeholder' => 'Ticket code',
    'scan' => 'Scan',

    'checked_in' => 'Aboard',
    'queued' => 'Recorded — will send when there is a signal',
    'already' => 'Already aboard',
    'unknown' => 'Unknown code',

    'queue_one' => '1 scan waiting for a signal.',
    'queue_count' => ':count scans waiting for a signal.',
    'synced' => 'All sent.',

    'aboard' => 'Aboard',
    'waiting' => 'Expected',

    'unnamed' => 'No name',

    'loaded_at' => 'List loaded at :time.',
    'full_page' => 'Full boarding page',

];
