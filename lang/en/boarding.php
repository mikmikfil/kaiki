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

    'online' => 'Online',
    'offline' => 'No signal',

    'placeholder' => 'Ticket code',
    'scan' => 'Scan',

    // The camera inside the page. It stays open from one passenger to the next.
    'camera' => [
        'open' => 'Scan with the camera',
        'close' => 'Close camera',
        'hint' => 'Point the camera at the ticket\'s QR code.',
        'starting' => 'Opening the camera…',
        'or_type' => 'or type the code:',
        // Over http:// on a network address the browser offers no camera at all.
        'insecure' => 'The camera only opens over a secure connection (https). Type the code below.',
        'unsupported' => 'This browser cannot open a camera. Type the code below.',
        'denied' => 'Camera permission was not given. Allow it in the browser settings and tap again.',
        'nocamera' => 'No camera was found on this device.',
        'busy' => 'Another app is using the camera. Close it and tap again.',
        'failed' => 'The camera did not open. Tap again or type the code.',
        'not_ticket' => 'This QR code is not a ticket',
    ],

    'checked_in' => 'Aboard',
    'queued' => 'Recorded — will send when there is a signal',
    'already' => 'Already aboard',
    'unknown' => 'Unknown code',

    // "Boardings" rather than "scans": a tapped name queues the same way.
    'queue_one' => '1 boarding waiting for a signal.',
    'queue_count' => ':count boardings waiting for a signal.',
    'synced' => 'All sent.',

    'aboard' => 'Aboard',
    'waiting' => 'Expected',
    'board' => 'Board',

    'unnamed' => 'No name',

    'loaded_at' => 'List loaded at :time.',
    'full_page' => 'Full boarding page',

];
