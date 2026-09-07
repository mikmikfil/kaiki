<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The sandbox checkout page (SAA-9, PAY-11)
|--------------------------------------------------------------------------
|
| The wording has one job before any other: nobody who lands here should be in
| any doubt that no money is moving. An operator arrives from onboarding, a
| developer from the widget, and both got here through a flow that looks exactly
| like buying a boat trip.
|
*/

return [
    'title' => 'Test payment',
    'banner' => 'Test mode — no money will move and no card is needed.',
    'heading' => 'Finish the test payment',
    'body' => 'This stands in for your payment provider so you can see the whole booking work before you have a merchant account.',
    'reference' => 'Booking',
    'amount' => 'Amount',
    'note' => 'A real payment page would ask for a card here. This one does not, on purpose.',

    'actions' => [
        'pay' => 'Pay',
        'decline' => 'Decline the card',
    ],
];
