<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The i18n lint allow-list (I18N-2)
|--------------------------------------------------------------------------
|
| One file, so that "why is this string exempt" has a single answer with a
| reason next to it. Both checks read from here:
|
|   identical_translations  a lang key whose Greek and English values are
|                           legitimately the same string
|   literals                a source location allowed to hold a literal that
|                           the hardcoded-string scanner would otherwise flag
|
| **Every entry needs a reason, and the reason is the point.** An allow-list
| without them becomes the place strings go to be forgotten, and after a dozen
| silent additions the lint enforces nothing. If an entry cannot be justified in
| one sentence, it is a missing translation rather than an exception.
|
*/

return [

    'identical_translations' => [
        // Proper nouns and product names are not translated. "Solo" is the name
        // of a plan, the same word on a Greek invoice as on an English one.
        'enums.plan.solo.label' => 'Plan name, a proper noun in both locales.',

        // Each language is written in its own language, in both files — someone
        // reading the English panel and hunting for Greek must recognise
        // "Ελληνικά", not the word "Greek". That is the whole point of a
        // language switcher, so the two files agree here on purpose.
        'enums.locale.el.label' => 'A language names itself, identically in every locale.',
        'enums.locale.en.label' => 'A language names itself, identically in every locale.',
        'enums.locale.el.short' => 'Two-letter language code shown on the switcher.',
        'enums.locale.en.short' => 'Two-letter language code shown on the switcher.',

        // A URL used as a form placeholder. Translating the example domain
        // would suggest the address itself changes with the interface language.
        'api_keys.form.allowed_origins.placeholder' => 'An example URL, not prose.',

        // The four social networks are companies, and an operator looks for
        // the wordmark they know. "Instagram" is "Instagram" on a Greek phone.
        'branding.form.social.instagram' => 'Company name, identical in both locales.',
        'branding.form.social.facebook' => 'Company name, identical in both locales.',
        'branding.form.social.tripadvisor' => 'Company name, identical in both locales.',
        'branding.form.social.whatsapp' => 'Company name, identical in both locales.',

        // The name of Google's service, as it appears in their own Greek
        // interface. Translating it would leave an operator searching for a
        // product that is not called that anywhere they can look it up.
        'enums.font_source.google.label' => 'Product name, identical in both locales.',
    ],

    'literals' => [
        // Filled as the scanner finds genuine exceptions. Empty is the goal.
    ],

];
