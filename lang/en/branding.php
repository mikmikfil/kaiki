<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Branding — colours, logos, typography (BRD-1 … BRD-7, I18N-1)
|--------------------------------------------------------------------------
|
| Every label, helper text, warning and error message on the branding screen.
| Enum labels are NOT here — they live in `enums.php`, which is their one home
| (CNV-11).
|
| `LangKeyParityTest` checks that this file and `lang/el/branding.php` hold
| exactly the same keys, in both directions.
|
*/

return [

    'nav' => 'Branding',
    'title' => 'Your brand',
    'subtitle' => 'Colours, logos and typography for your widget, booking pages and emails.',

    'sections' => [
        'colors' => 'Colours',
        'typography' => 'Typography and shape',
        'logos' => 'Logos and images',
        'contact' => 'Links and email footer',
        'advanced' => 'Advanced',
    ],

    'form' => [
        'color_primary' => [
            'label' => 'Primary colour',
            'help' => 'The colour of the booking buttons. It is the first thing a guest sees.',
        ],
        'color_secondary' => [
            'label' => 'Secondary colour',
            'help' => 'For second-tier buttons and accents.',
        ],
        'color_accent' => [
            'label' => 'Accent colour',
            'help' => 'For badges, offers and anything that has to stand out.',
        ],
        'color_background' => [
            'label' => 'Background',
            'help' => 'The background of the widget and your booking pages.',
        ],
        'color_text' => [
            'label' => 'Text colour',
            'help' => 'Body text on the background.',
        ],
        'font_family' => [
            'label' => 'Font',
            'help' => 'A font family name, for example Inter or Lora.',
        ],
        'font_source' => [
            'label' => 'Font source',
            'help' => 'Google Fonts loads the font from Google’s servers — your guest’s computer contacts them, which your privacy policy has to mention.',
        ],
        'button_radius_px' => [
            'label' => 'Button roundness',
            'help' => 'From 0 (square) to 32 (fully rounded).',
        ],
        'widget_theme' => [
            'label' => 'Widget theme',
            'help' => 'Automatic follows the guest’s own device setting, not yours.',
        ],
        'logo_light' => [
            'label' => 'Logo for light backgrounds',
            'help' => 'SVG, PNG or WebP up to 2 MB.',
        ],
        'logo_dark' => [
            'label' => 'Logo for dark backgrounds',
            'help' => 'A dark logo on a dark background is invisible; upload the light version of it here.',
        ],
        'favicon' => [
            'label' => 'Favicon',
            'help' => 'The small icon on the browser tab.',
        ],
        'email_header' => [
            'label' => 'Email header image',
            'help' => 'Shown at the top of your confirmation emails.',
        ],
        'email_footer_text' => [
            'label' => 'Email footer text',
            'help' => 'Optional. Usually an address, a VAT number or a sign-off. If you fill it in, it is needed in both languages.',
        ],
        'social' => [
            'website' => 'Website',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'tripadvisor' => 'TripAdvisor',
            'whatsapp' => 'WhatsApp',
            'help' => 'Full addresses (https://…). For WhatsApp, the number in international format, for example +306912345678.',
        ],
        'custom_css' => [
            'label' => 'Custom CSS',
            'help' => 'Applies only to the booking pages we host for you — never to the widget you embed on someone else’s site. Anything that could execute is stripped automatically.',
        ],
    ],

    'contrast' => [
        'heading' => 'Contrast check (WCAG AA)',
        'body_text' => 'Body text on the background',
        'button_text' => 'Button text on the primary colour',
        'pass' => 'Passes — :ratio:1 (minimum :threshold:1)',
        'fail' => 'Low contrast — :ratio:1, below the :threshold:1 minimum',
        'warning' => 'Some of these colour pairs are hard to read. Saving is not blocked — your colours are your decision.',
        'unchecked' => 'Not checked yet. This runs on your first save.',
    ],

    'actions' => [
        'save' => 'Save',
        'saved' => 'Branding saved.',
        'reset' => 'Reset to defaults',
        'reset_heading' => 'Reset to the default colours?',
        'reset_description' => 'Colours, font, shape and your uploaded files go back to the platform defaults. Your links and email footer are kept.',
        'reset_confirm' => 'Yes, reset',
        'reset_done' => 'Branding reset to the defaults.',
    ],

    'upload' => [
        'refused' => [
            'too_large' => 'That file is :size KB. The limit is :limit KB.',
            'unsupported_type' => 'Only PNG, WebP and SVG files are accepted. We check the file’s own contents, not its extension.',
            'unsafe_svg' => 'This SVG cannot be used safely. Export it again from your design tool, without scripts or external references.',
            'undecodable' => 'The file looks like an image but cannot be read. It may have uploaded only half way — please try again.',
        ],
    ],

    'validation' => [
        'hex_color' => 'Enter a colour in #RRGGBB form, for example #0F62FE.',
        'social_links' => [
            'shape' => 'These links are not in a valid form.',
            'unknown_key' => '“:key” is not an accepted link. Allowed: :allowed.',
            'url' => 'Enter a full address for :key, for example https://example.gr.',
            'phone' => 'Enter the number in international format, for example +306912345678.',
        ],
    ],
];
