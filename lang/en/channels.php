<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sales channels (EXT-1, ADR-0034)
|--------------------------------------------------------------------------
|
| What an operator is told when a channel — the iCal calendar today,
| GetYourGuide once it opens — could not do what was asked of it.
|
| The channels' own labels are not here; they live in `enums.php` with every
| other enum label, as the integration providers do.
|
| Everything written here is read in the failure feed (OPS-21), often hours
| after the fact and by somebody who did not press anything. So each sentence
| says what did not happen and what that means for the seats — never which
| method failed.
*/

return [

    'result' => [
        // Not an error, and not retried: the channel is not connected at all.
        // Said plainly so it cannot be mistaken for an outage.
        'not_configured' => 'This channel is not connected, so nothing was sent.',
    ],

    'ical' => [
        // Partial success is still a failure worth reporting: the blocks that
        // did not arrive are exactly the ones that would have stopped a sale.
        'sources_failed' => ':count calendars could not be read. While they cannot, a boat may look free when it is not.',
    ],

];
