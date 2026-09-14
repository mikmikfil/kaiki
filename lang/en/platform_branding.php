<?php

declare(strict_types=1);

/*
 * The "Appearance" screen on /admin — the platform's own logo and colours,
 * not an operator's.
 */
return [

    'nav' => 'Appearance',
    'title' => 'How the platform looks',
    'subtitle' => 'The logo and colours everyone sees — in both admin panels and on the sign-in screen.',

    'sections' => [
        'logo' => [
            'heading' => 'Logo',
            'description' => 'PNG, WebP or SVG, up to 2 MB. With nothing uploaded, the name is shown as text.',
        ],
        'colors' => [
            'heading' => 'Colours',
            'description' => 'The primary colour paints buttons and links. The accent is for the details that have to stand out against it.',
        ],
    ],

    'form' => [
        'logo_light' => [
            'label' => 'Logo, light theme',
            'help' => 'A dark logo, for a light background.',
        ],
        'logo_dark' => [
            'label' => 'Logo, dark theme',
            'help' => 'A light logo, for a dark background. Without one, the dark theme shows the logo above.',
        ],
        'favicon' => [
            'label' => 'Tab icon',
            'help' => 'Square and small. What shows in the browser tab.',
        ],
        'primary_color' => [
            'label' => 'Primary colour',
            'help' => 'Buttons, links, anything active.',
        ],
        'accent_color' => [
            'label' => 'Accent colour',
            'help' => 'For whatever has to stand out against the primary.',
        ],
    ],

    'contrast' => [
        'heading' => 'Readability',
    ],

    'contrast_warning' => 'These two colours are :ratio to 1 apart, below the :threshold to 1 they need to be readable against each other. You can save them anyway — they will simply be hard going at small sizes.',

    'actions' => [
        'save' => 'Save',
        'reset' => 'Reset colours',
        'reset_heading' => 'Reset to the original colours?',
        'reset_description' => 'Both colours go back to the platform defaults. The logo is left alone — to remove that, use the button on the file itself.',
        'reset_confirm' => 'Reset',
    ],

    'saved' => 'Appearance saved',
    'reset_done' => 'Colours are back to the defaults',

];
