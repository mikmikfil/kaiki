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

        'cta' => [
            'label' => 'Button',
            'help' => 'One button under the heading.',
            'options' => [
                'trips' => 'Go to the trips',
                'contact' => 'Go to the contact details',
                'none' => 'No button',
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
    ],

    // Read beside the field, by somebody who has pasted a link that looked
    // perfectly good to them. So it names the two providers rather than saying
    // the address is invalid — which would send them to check their typing.
    'validation' => [
        'video_url' => 'The video link must be a YouTube or Vimeo address.',
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
