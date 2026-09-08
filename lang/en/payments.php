<?php

declare(strict_types=1);

/*
 * Gateway messages (spec PAY-12, CNV-11, I18N-1).
 *
 * **Two audiences, and the split is the requirement.** A guest whose card was
 * declined needs to know to try another card; they do not need
 * `card_declined: insufficient_funds`, which tells a stranger about their bank
 * balance and gives them nothing to do. An operator looking at the same failure
 * in the panel needs exactly that detail, because they are the one deciding
 * whether to chase it.
 *
 * So `guest.*` is short, calm and never blames the guest for something that is
 * not theirs — an operator's expired API key produces "something went wrong",
 * not "your card was declined". And `operator.*` names the thing.
 *
 * Raw gateway text appears in neither.
 */

return [
    /*
     * The guest's half. Deliberately few: three or four sentences cover every
     * code either gateway can produce, because the distinctions the gateways
     * draw are distinctions a guest cannot act on differently.
     */
    'guest' => [
        'generic' => 'Something went wrong with the payment and you have not been charged. Please try again.',
        'declined' => 'Your card was declined. Please try a different card, or check with your bank.',
        'expired_card' => 'That card has expired. Please try a different one.',
        'card_details' => 'Some of the card details were not accepted. Please check them and try again.',
        'temporary' => 'We could not take the payment just now and you have not been charged. Please try again in a few minutes.',
        'authentication' => 'Your bank asked for extra confirmation and it was not completed. Please try again and follow your bank’s prompts.',
        'session_expired' => 'The payment page timed out. Your seats are still held for a short while — please start the payment again.',
    ],

    /*
     * The operator's half. Specific, and each one says what to *do* — a message
     * that names a problem without a remedy is a support ticket.
     */
    'operator' => [
        'unmapped' => 'The payment provider returned an error code we do not recognise (:code). The guest was shown a general message. Please send us this code if it keeps happening.',
        'unreachable' => 'We could not reach :gateway at all. This is usually temporary; if it continues, check their status page before changing anything.',

        'viva' => [
            'success_with_error' => 'Viva: the order completed but reported an error. Check the transaction in your Viva dashboard before treating it as paid.',
            'declined' => 'Viva: the bank declined the card. The guest needs to use another card.',
            'invalid_card' => 'Viva: the card details were not accepted.',
            'expired_card' => 'Viva: the card has expired.',
            'issuer_unavailable' => 'Viva: the card issuer could not be reached. Usually temporary.',
            'order_expired' => 'Viva: the payment order expired before the guest paid.',
            'unauthorised' => 'Viva: your client ID or secret was rejected. Check the Integrations page — and make sure the credentials match the environment (demo credentials do not work on live).',
            'order_not_found' => 'Viva: the payment order could not be found. It may have been cancelled in your Viva dashboard.',
        ],
    ],

    /*
     * What a guest sees on the gateway's own page. It carries the booking
     * reference and nothing else — a gateway line item is rendered by a third
     * party in a layout we do not control, and a guest name or a trip title
     * there is personal data on somebody else's screen.
     */
    'checkout' => [
        'line_item' => 'Booking :reference',
    ],

    'sandbox' => [
        'banner' => 'Sandbox mode: bookings are marked as tests and no real money moves.',
        'switch_requires_credentials' => 'Switching between live and test needs the credentials for the environment you are switching to, entered again. This is deliberate — it is what stops sandbox being turned on by accident on a live account.',
    ],
];
