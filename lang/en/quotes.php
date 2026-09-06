<?php

declare(strict_types=1);

/*
 * Quotes and enquiries (spec BKG-24 … BKG-29).
 *
 * Two flows that share a table neighbourhood and almost nothing else. A
 * **quote** becomes a booking; an **enquiry** never touches availability at all
 * and may never become anything.
 *
 * The operator-facing strings here are written for somebody pricing a charter
 * by hand, which is a job the rest of this product deliberately does not do for
 * them: `mode: quote` exists because some trips cannot be priced by a table.
 */

return [

    'quote' => [
        'nav' => 'Quotes',
        'model' => [
            'singular' => 'Quote',
            'plural' => 'Quotes',
        ],

        'sections' => [
            'offer' => 'The offer',
            'lines' => 'What is included',
            'terms' => 'Validity and terms',
        ],

        'form' => [
            'booking' => ['label' => 'Booking'],
            'valid_until' => [
                'label' => 'Valid until',
                'help' => 'After this the guest can no longer accept, and any hold on the boat is released.',
            ],
            'deposit_cents' => [
                'label' => 'Deposit',
                'help' => 'What the guest pays now. Leave at zero to ask for the whole amount.',
            ],
            'message' => [
                'label' => 'Covering note',
                'help' => 'Shown to the guest above the price, in their own language.',
            ],
            'terms' => ['label' => 'Terms'],
            'label' => ['label' => 'Description'],
            'kind' => ['label' => 'Type'],
            'qty' => ['label' => 'Quantity'],
            'unit_price_cents' => ['label' => 'Price each'],
            'total_cents' => [
                'label' => 'Line total',
                'help' => 'Filled in for you, and yours to change — what you type here is what the guest is offered.',
            ],
            'sort_order' => ['label' => 'Order'],
        ],

        'table' => [
            'version' => 'Version',
            'reference' => 'Booking',
            'status' => 'Status',
            'total' => 'Total',
            'valid_until' => 'Valid until',
            'sent_at' => 'Sent',
            'viewed_at' => 'Opened',
        ],

        'actions' => [
            'send' => [
                'label' => 'Send to the guest',
                'hold' => [
                    'label' => 'Hold the boat until the quote expires',
                    'help' => 'Off by default. A quote does not reserve anything, so the boat stays on sale unless you say otherwise here.',
                ],
                'sent' => 'The quote is on its way.',
                'refused' => 'That quote cannot be sent yet: :reason',
            ],
            'revise' => [
                'label' => 'Revise',
                'help' => 'Creates a new version. The old one is marked replaced and the guest’s old link says so.',
                'created' => 'Version :version is ready to edit.',
            ],
        ],

        'guest' => [
            'replaced' => 'This quote was replaced by a newer one. Please use the most recent link the operator sent you.',
            'expired' => 'This quote has expired. Contact the operator if you would still like to go.',
            'unavailable' => 'That date is no longer available. Contact the operator and they will find you another.',
            'request_new_message' => 'I would still like to go. Could you send me a new quote for booking :reference?',
        ],
    ],

    'enquiry' => [
        'nav' => 'Enquiries',
        'model' => [
            'singular' => 'Enquiry',
            'plural' => 'Enquiries',
        ],

        'table' => [
            'received' => 'Received',
            'name' => 'From',
            'email' => 'Email',
            'phone' => 'Phone',
            'product' => 'Trip',
            'preferred_date' => 'Preferred date',
            'pax' => 'People',
            'status' => 'Status',
        ],

        'form' => [
            'status' => ['label' => 'Status'],
            'assigned_user_id' => ['label' => 'Handled by'],
            'message' => [
                'label' => 'Their message',
                'help' => 'The guest’s own words, exactly as they wrote them.',
            ],
        ],

        'actions' => [
            'answered' => [
                'label' => 'Mark as answered',
                'done' => 'Marked as answered.',
            ],
            'spam' => [
                'label' => 'Mark as spam',
                'help' => 'Kept for thirty days and left out of every count, so nothing is thrown away by mistake.',
                'done' => 'Marked as spam.',
            ],
        ],
    ],

];
