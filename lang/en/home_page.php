<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The home-page editor (#102, I18N-1)
|--------------------------------------------------------------------------
|
| Read by an operator, not a developer. "Block" is the one piece of jargon and
| it is unavoidable — everything else is named for what it does on the page.
|
| The help text on each field says what the visitor will see, not what the
| field stores. An operator deciding whether to fill something in is asking
| about their page, not about ours.
|
*/

return [

    'nav' => 'Home page',
    'title' => 'Your home page',
    'subtitle' => 'The page a visitor lands on. Add sections, drag them into the order you want, and save.',

    'form' => [
        'blocks' => [
            'label' => 'Sections',
            'help' => 'Drag to reorder. The first section is what a visitor sees before they scroll.',
            'add' => 'Add a section',
            'untitled' => 'New section',
        ],

        'type' => [
            'label' => 'Kind of section',
        ],

        'is_visible' => [
            'label' => 'Show this section',
            'help' => 'Turn it off to take it down without losing what you wrote.',
        ],

        'heading' => [
            'label' => 'Heading',
            'help' => 'Optional. Leave it empty for a section that needs no title.',
        ],

        'body' => [
            'label' => 'Text',
            'help' => 'Plain text. Leave a blank line between paragraphs. Formatting and links are not supported here — this keeps your page fast and impossible to break.',
        ],

        'video' => [
            'label' => 'Video (optional)',
            'help' => 'A short clip for the top of the page — a few seconds, no sound. MP4 or WebM, up to 20 MB. Keep the photograph above as well: it is what a visitor sees until the video loads, and what they see instead if they have asked for less motion.',
        ],

        'video_url' => [
            'label' => 'Or a link to a video',
            'help' => 'A YouTube or Vimeo address. It plays in the background of the masthead, silently and on a loop — paste the ordinary link to the video, the one from the address bar. If you have uploaded a file above, that one plays and this is ignored.',
        ],

        'image' => [
            'label' => 'Image',
            'help' => 'JPEG, PNG or WebP, up to 4 MB. Wide photographs work best.',
        ],

        'images' => [
            'label' => 'Photographs',
            'help' => 'Shown as a grid. Describe each one — visitors using a screen reader, and search engines, read the description.',
            'add' => 'Add a photograph',
            'alt' => [
                'label' => 'Description',
                'help' => 'What is in the photograph, in a few words. Different for each image.',
            ],
        ],

        'source' => [
            'label' => 'Which trips',
            'options' => [
                'all' => 'All of them',
                'featured' => 'Only the ones marked featured',
                'category' => 'One category',
            ],
        ],

        'category' => [
            'label' => 'Category',
        ],

        'show_featured' => [
            'label' => 'A row of highlights first',
            'help' => 'The trips you marked as featured, in a row a visitor can swipe, with the rest underneath. Turn it off to show every trip in one grid — which reads better with a handful of trips.',
        ],

        'limit' => [
            'label' => 'How many to show',
            'help' => 'Zero shows all of them.',
        ],

        'image_side' => [
            'label' => 'Image position',
            'options' => [
                'left' => 'Left of the text',
                'right' => 'Right of the text',
            ],
        ],

        // The FAQ block shows the entries from the FAQ screen (#103); it holds
        // no text of its own beyond the heading, so the editor says where the
        // questions are written.
        'faq' => [
            'label' => 'The questions',
            'help' => 'This block shows the questions from the FAQ screen — the ones that apply to every trip. Write and reorder them there.',
        ],

        'show_phone' => [
            'label' => 'Show the phone number',
        ],

        'show_email' => [
            'label' => 'Show the email address',
        ],

        'show_address' => [
            'label' => 'Show the address',
        ],

        'meeting_point' => [
            'label' => 'Meeting point',
            'help' => 'One of your saved meeting points, so this section always agrees with the trip pages.',
        ],

        // The sections of 16 September. Every word a guest reads is written
        // here, in both languages.

        'eyebrow' => [
            'label' => 'Small title',
            'help' => 'The short line above the heading, e.g. "How it works". Optional.',
        ],

        'image_alt' => [
            'label' => 'Photo description',
            'help' => 'What the photo shows, in a few words. Read by visitors using a screen reader and by search engines.',
        ],

        'buttons' => [
            'label' => 'Buttons',
            'help' => 'Up to two. The first is the main one. Write the text and choose where it leads — always a page of your own site.',
            'add' => 'Add a button',
            'button_label' => [
                'label' => 'Button text',
            ],
            'target' => [
                'label' => 'Where it leads',
                'options' => [
                    'trips' => 'The trips on your home page',
                    'search' => 'The trip search',
                    'contact' => 'The contact page',
                    'trip' => 'One trip',
                    'page' => 'Another page of your site',
                ],
            ],
            'product' => [
                'label' => 'Trip',
            ],
            'path' => [
                'label' => 'Page',
                'help' => 'The end of the address, e.g. legal. Pages of your own site only.',
            ],
        ],

        'badges' => [
            'label' => 'Small badges',
            'help' => 'Up to three short phrases under the buttons, e.g. "Weather guarantee". Optional.',
            'add' => 'Add a badge',
            'text' => [
                'label' => 'Text',
            ],
        ],

        'icon' => [
            'label' => 'Icon',
            'options' => [
                'users' => 'Group',
                'anchor' => 'Anchor',
                'shield' => 'Shield',
                'lock' => 'Lock',
                'star' => 'Star',
                'heart' => 'Heart',
                'sun' => 'Sun',
                'boat' => 'Boat',
                'clock' => 'Clock',
                'pin' => 'Map pin',
                'check' => 'Tick',
                'support' => 'Headset',
            ],
        ],

        'stats' => [
            'label' => 'Figures',
            'help' => 'Up to four, in one white card, wherever you place the section on the page.',
            'add' => 'Add a figure',
            'value' => [
                'label' => 'Figure',
                'help' => 'Short, e.g. "30+" or "4.9★".',
            ],
            'caption' => [
                'label' => 'What it counts',
            ],
        ],

        'steps' => [
            'label' => 'Steps',
            'help' => 'Up to three, in the order a guest takes them. The number is added for you.',
            'add' => 'Add a step',
        ],

        'features' => [
            'label' => 'Reasons',
            'help' => 'Up to four, each with its own icon.',
            'add' => 'Add a reason',
        ],

        'item_title' => [
            'label' => 'Title',
        ],

        'item_text' => [
            'label' => 'Text',
            'help' => 'One or two sentences.',
        ],

        'dark' => [
            'label' => 'Dark background',
            'help' => 'The section in a deep shade of your main colour, with light text.',
        ],

        'testimonials' => [
            'label' => 'Reviews',
            'help' => 'Up to three. Only reviews you actually received.',
            'add' => 'Add a review',
            'quote' => [
                'label' => 'The review',
                'help' => 'As the guest wrote it. You may shorten it.',
            ],
            'name' => [
                'label' => 'Name',
                'help' => 'As they want it shown, e.g. "Helen P.". The same in both languages.',
            ],
            'trip' => [
                'label' => 'Trip',
            ],
            'rating' => [
                'label' => 'Stars',
            ],
            'avatar' => [
                'label' => 'Small photo (optional)',
                'help' => 'Without one, the first letter of the name is shown.',
            ],
        ],
    ],

    // Read beside the field, by somebody who has pasted a link that looked
    // perfectly good to them. So it names the two providers rather than saying
    // the address is invalid — which would send them to check their typing.
    'validation' => [
        'video_url' => 'The video link must be a YouTube or Vimeo address.',
        'path' => 'Write only the end of an address on your own site, e.g. legal — no https:// and no other site.',
    ],

    'actions' => [
        'save' => 'Save',
        'view' => 'See your page',
    ],

    'saved' => [
        'title' => 'Home page saved',
        'body' => '{0}Your page has no sections yet.|{1}One section is live.|[2,*]:count sections are live.',
    ],

];
