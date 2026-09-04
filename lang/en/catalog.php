<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalogue — vessels and ports (spec CAT-1, CAT-2, CAT-3, I18N-1)
|--------------------------------------------------------------------------
|
| Every label, helper text and error the vessel and port resources show. Enum
| labels are NOT here — they live in `enums.php`, which is the one home for
| them (CNV-11).
|
| `LangKeyParityTest` asserts this file and `lang/el/catalog.php` have identical
| key sets in both directions, so an English string added without its Greek
| twin fails CI rather than reaching an operator.
|
*/

return [

    'vessel' => [
        'nav' => 'Boats',

        'model' => [
            'singular' => 'Boat',
            'plural' => 'Boats',
        ],

        'sections' => [
            'identity' => 'The boat',
            'capacity' => 'Capacity and crew',
            'operations' => 'Operations',
            'specs' => 'Specification',
            'media' => 'Photos',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'The boat’s own name, as it appears on the hull. It is not translated.',
                'taken' => 'You already have a boat called “:name”.',
            ],
            'type' => ['label' => 'Type'],
            'status' => [
                'label' => 'Status',
                'help' => 'Only active boats can be sold. Maintenance hides the boat without retiring it.',
            ],
            'registration_number' => [
                'label' => 'Registration number',
                'help' => 'The ΑΛΣ registration. It is printed on the manifest and the charter agreement.',
            ],
            'length_cm' => [
                'label' => 'Length',
                'help' => 'In metres, to one decimal place.',
            ],
            'capacity_max' => [
                'label' => 'Maximum passengers',
                'help' => 'The legal limit for this boat. No trip and no departure may ever exceed it.',
            ],
            'crew_count' => ['label' => 'Crew'],
            'captain_name' => [
                'label' => 'Captain',
                'help' => 'Shown on the passenger manifest.',
            ],
            'home_port' => [
                'label' => 'Home port',
                'help' => 'Where the boat is based. Deleting a port never deletes the boat.',
                'none' => 'No home port',
            ],
            'turnaround_buffer_minutes' => [
                'label' => 'Turnaround time',
                'help' => 'Minutes needed between two trips on this boat. Leave empty to use your account setting (:default minutes).',
                'inherited' => 'Inheriting :minutes minutes from your account settings.',
            ],
            'description' => [
                'label' => 'Description',
                'help' => 'Shown to guests on the trip page.',
            ],
            'specs' => [
                'beam_m' => ['label' => 'Beam (m)'],
                'year_built' => ['label' => 'Year built'],
                'engine' => [
                    'label' => 'Engine',
                    'placeholder' => '2 × 180 hp',
                ],
                'cruising_speed_kn' => ['label' => 'Cruising speed (knots)'],
                'cabins' => ['label' => 'Cabins'],
                'wc' => ['label' => 'Toilets'],
                'amenities' => [
                    'label' => 'On board',
                    'help' => 'Shown as icons on the trip page.',
                ],
            ],
            'images' => [
                'label' => 'Photos',
                'help' => 'The first photo is the one guests see first. Drag to reorder.',
                'add' => 'Add a photo',
                'file' => ['label' => 'File'],
                'alt' => [
                    'label' => 'Description of the photo',
                    'help' => 'Read aloud by screen readers and shown if the photo fails to load.',
                ],
            ],
        ],

        'table' => [
            'name' => 'Name',
            'type' => 'Type',
            'capacity_max' => 'Passengers',
            'home_port' => 'Home port',
            'status' => 'Status',
            'turnaround' => 'Turnaround',
            'turnaround_inherited' => ':minutes min (account)',
            'turnaround_own' => ':minutes min',
        ],

        'capacity' => [
            /*
             * The `capacity_max` guard (data-model §2.3). One string for both
             * the form field error and the exception an import raises, so an
             * operator and a log line say the same thing.
             */
            'refused' => 'This boat cannot carry fewer than :capacity passengers yet — :count record(s) already promise more: :records.',
        ],
    ],

    'port' => [
        'nav' => 'Ports & meeting points',

        'model' => [
            'singular' => 'Port',
            'plural' => 'Ports & meeting points',
        ],

        'sections' => [
            'identity' => 'The place',
            'location' => 'Finding it',
            'media' => 'Photo',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'How guests and your crew refer to it — “Marina Zeas”, “Old harbour”.',
            ],
            'address' => [
                'label' => 'Address',
                'help' => 'One line. This is what the “open in maps” link searches for.',
            ],
            'lat' => ['label' => 'Latitude'],
            'lng' => ['label' => 'Longitude'],
            'coordinates' => [
                'help' => 'Optional, but far more precise than an address. Copy them from the map pin.',
            ],
            'instructions' => [
                'label' => 'How to find us',
                'help' => 'Shown on the booking confirmation — “meet at the blue kiosk, 15 minutes before departure”.',
            ],
            'maps_url' => [
                'label' => 'Map link',
                'help' => 'Optional. Use it when the address alone puts the pin in the wrong place.',
            ],
            'photo_path' => [
                'label' => 'Photo',
                'help' => 'A picture of the meeting point helps guests recognise it.',
            ],
            'is_active' => [
                'label' => 'Active',
                'help' => 'Inactive places stay on existing trips but cannot be chosen for new ones.',
            ],
            'sort_order' => [
                'label' => 'Order',
                'help' => 'Lower numbers appear first in lists.',
            ],
        ],

        'table' => [
            'name' => 'Name',
            'address' => 'Address',
            'vessels_count' => 'Boats based here',
            'is_active' => 'Active',
            'has_coordinates' => 'Pinned',
        ],
    ],

    'shared' => [
        'search_placeholder' => 'Search by name',
        'sort_order' => [
            'label' => 'Order',
            'help' => 'Lower numbers appear first in lists.',
        ],
    ],

    'product' => [
        'nav' => 'Trips',

        'model' => [
            'singular' => 'Trip',
            'plural' => 'Trips',
        ],

        'validation' => [
            'max_pax_over_capacity' => 'You asked for :max_pax passengers, but “:vessel” is licensed for :capacity. The vessel limit is a legal one rather than a preference — lower the number or choose another boat.',
            'flexible_start_mode' => 'A flexible start time applies only to whole-boat private charters. This trip is “:mode”, where the departure time is shared by everyone on board.',
            'mode_locked' => '“:title” already has :count bookings, so the selling mode cannot change from “:from” to “:to”. Every departure and every stored price was worked out under the old one. Create a new trip instead.',

            'itinerary' => [
                'shape' => 'The itinerary is not in a valid form.',
                'missing_locale' => 'The itinerary is missing for “:locale”. Stops are needed in both languages.',
                'key' => 'Each stop needs a short identifier of up to :max characters.',
                'name' => 'Each stop needs a name of up to :max characters, in “:locale” too.',
                'description' => 'A stop description cannot be longer than :max characters.',
                'duration' => 'A stop duration must be a positive number of minutes.',
                'key_mismatch' => 'The stops differ between languages: :keys. Every stop must exist in both, under the same identifier.',
                'geo_unknown' => 'There are coordinates for stops that do not exist: :keys.',
            ],
        ],
    ],

    'age_band' => [
        'nav' => 'Age bands',

        'model' => [
            'singular' => 'Age band',
            'plural' => 'Age bands',
        ],

        'validation' => [
            'empty' => 'Every trip needs at least one age band — otherwise there is nothing to say who may board or what they pay.',
            'overlap' => '“:first” and “:second” cover the same ages. Every age must belong to exactly one band.',
            'no_base' => 'One band has to be the base — usually the adult. Every other price is worked out as a share of it.',
            'many_base' => 'Only one band can be the base. These are marked: :bands.',
            'none_counted' => 'At least one band must take up a seat. Otherwise the trip would accept unlimited passengers on a boat with a limit.',
            'multiplier_required' => '“:band” is priced as a share of the base band, so it needs a percentage. For a price of its own, change how it is priced.',
            'pricing_mode' => 'The pricing mode for “:band” is not recognised.',
            'label_locale' => '“:band” needs a name in “:locale” too — this is the list a guest chooses from.',
            'duplicate_code' => 'A code appears twice: :codes. Each band needs its own.',
        ],
    ],

    'extra' => [
        'nav' => 'Extras',

        'model' => [
            'singular' => 'Extra',
            'plural' => 'Extras',
        ],

        'on_request' => 'On request',

        'validation' => [
            'on_request_has_price' => 'On-request extras carry no price — you agree it with the guest. Either clear the price or change how it is charged.',
            'price_required' => 'Give it a price, or change how it is charged to on request.',
            'max_qty_exceeded' => '“:extra” can be added at most :max times per booking.',
        ],
    ],

];
