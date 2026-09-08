<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What went wrong (OPS-21, NFR-8)
|--------------------------------------------------------------------------
|
| NFR-8: every failure an operator can see is explained in their own language.
| The provider's code stays underneath for whoever needs it; the sentence beside
| it says what it means and what to do.
|
| No "system error". If we do not know what broke, we say so plainly.
*/

return [

    'nav' => 'What went wrong',
    'title' => 'What went wrong',

    'heading' => ':count things did not work',
    'subheading' => 'Everything the system tried and could not do, over the last month. Where it can try again, there is a button.',

    'empty' => [
        'heading' => 'Nothing went wrong',
        'description' => 'Nothing has failed in the last month. If something does, you will find it here.',
    ],

    'retry' => [
        'label' => 'Try again',
        'done' => 'Queued.',
        'nothing' => 'There was nothing to try again.',
    ],

    'notification' => [
        'title' => ':channel to :to did not arrive',
    ],

    'payment' => [
        'title' => 'The :gateway payment was refused',
        'explanation' => "The guest's bank did not approve the charge. You cannot try it again yourself — the guest has to pay again from their own booking link.",
    ],

    'gateway_webhook' => [
        'title' => 'A message from :provider we could not read',
        'failed' => 'The bank told us about a payment and something broke while we were recording it. The money may well have been taken — check the booking before you do anything.',
        'orphaned' => 'The bank told us about a payment that matches no booking. Somebody has been charged and we do not know who. This needs a person.',
    ],

    'ical' => [
        'title' => 'The calendar ":name" (:vessel) cannot be read',
        'explanation' => 'It has failed :count times in a row. Usually the other service changed the address. While it cannot be read, the boat may look free when somebody else has already sold it. It tries again by itself every quarter of an hour.',
    ],

    'export' => [
        'title' => 'The ":type" export did not finish',
        'explanation' => 'The file was not built. Your choices were kept — press "Try again" and it starts over with the same dates.',
    ],

    'outbound_webhook' => [
        'title' => '":endpoint" never received ":event"',
        'explanation' => 'We tried eight times over a day and the receiver answered :status. If it is fixed, send it again — it is exactly the same message, so if they already had it they will ignore the repeat.',
        'no_answer' => 'nothing',
    ],

];
