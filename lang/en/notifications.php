<?php

declare(strict_types=1);

/*
 * The operator's view of what we sent (spec NTF-3, BKG-14, CNV-11).
 *
 * The `errors` block is the one that matters. CNV-11: every message an operator
 * reads exists in Greek and English and comes from a lang file — and PAY-12's
 * rule about gateway text applies here for the same reason. "SMTP 535" is
 * written for a developer by a company the operator has never heard of; what
 * they need is a sentence that says what to do next.
 */

return [

    'nav' => 'Messages',
    'model' => [
        'singular' => 'Message',
        'plural' => 'Messages',
    ],

    'table' => [
        'when' => 'When',
        'booking' => 'Booking',
        'message' => 'Message',
        'channel' => 'Sent by',
        'to' => 'To',
        'status' => 'Status',
        'provider' => 'Carrier',
        'why' => 'What went wrong',
    ],

    'filters' => [
        'needs_attention' => 'Only the ones that need me',
    ],

    'actions' => [
        'retry' => [
            'label' => 'Send again',
            'help' => 'The message is rebuilt from the booking as it stands now, so any corrections you have made are included.',
            'done' => 'Sending again.',
            'nothing' => 'There is no booking left to send this against.',
        ],
    ],

    'errors' => [
        'unknown' => 'Something went wrong when we tried to send this. Try again, and contact us if it keeps failing.',
        'apifon_not_configured' => 'Your Apifon account is not set up yet. Add your details under Integrations and try again.',
        'twilio_not_configured' => 'Your Twilio account is not set up yet. Add your details under Integrations and try again.',
        'apifon_unreachable' => 'Apifon did not answer. This is usually temporary — try again in a few minutes.',
        'twilio_unreachable' => 'Twilio did not answer. This is usually temporary — try again in a few minutes.',
        'quiet_hours_would_deliver_after_the_event' => 'This reminder came due overnight, and sending it at 08:00 would have been after the trip had already left. It was not sent.',
        'RuntimeException' => 'The mail service refused the message. Check the guest’s email address, then try again.',
    ],

];
