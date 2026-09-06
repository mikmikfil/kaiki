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

        // The integration providers are vendor wordmarks. An operator setting up
        // Viva is looking for the word printed on their own Viva dashboard, and
        // a transliterated «Biba» would send them hunting for a company that
        // calls itself nothing of the sort. `mydata` is absent on purpose: it
        // carries the issuing authority in brackets, and that part *is*
        // translated — AADE in English, ΑΑΔΕ in Greek.
        'enums.integration_provider.viva.label' => 'Vendor wordmark, identical in both locales.',
        'enums.integration_provider.stripe.label' => 'Vendor wordmark, identical in both locales.',
        'enums.integration_provider.apifon.label' => 'Vendor wordmark, identical in both locales.',
        'enums.integration_provider.yuboto.label' => 'Vendor wordmark, identical in both locales.',
        'enums.integration_provider.twilio.label' => 'Vendor wordmark, identical in both locales.',
        'enums.integration_provider.postmark.label' => 'Vendor wordmark, identical in both locales.',

        // The same two vendors again, under the `payments.gateway` column's own
        // enum. Two enums naming one company is not duplication to remove: the
        // credential store asks *whose keys are these* and the payment row asks
        // *who took the money*, and a `cash` payment answers the second with no
        // provider at all.

        // The CMS, not a word. A Greek operator whose site runs on WordPress
        // calls it WordPress, and «Γουόρντπρες» would be a transliteration
        // nobody has ever written down.
        'enums.booking_source.wordpress.label' => 'Product name, identical in both locales.',
        'enums.payment_gateway_name.viva.label' => 'Vendor wordmark, identical in both locales.',
        'enums.payment_gateway_name.stripe.label' => 'Vendor wordmark, identical in both locales.',

        // The three carriers again, under `notification_provider`. A third enum
        // naming the same companies is not duplication either: the credential
        // store asks *whose keys are these*, and a log row asks *who carried
        // this message* — and a `null_gateway` row answers the second with no
        // company at all, which is the case NTF-2 calls the platform fallback.
        'enums.notification_provider.postmark.label' => 'Vendor wordmark, identical in both locales.',
        'enums.notification_provider.apifon.label' => 'Vendor wordmark, identical in both locales.',
        'enums.notification_provider.twilio.label' => 'Vendor wordmark, identical in both locales.',

        // «Email» is the word Greek operators actually use — «ηλεκτρονικό
        // ταχυδρομείο» is a phrase nobody types into a form label, and a
        // panel that used it would read as a translation exercise. Added by
        // #89 with the booking panel.
        'bookings.view.email' => 'The word Greek operators use; the formal Greek phrase appears on no real form.',
        'bookings.form.guest.email' => 'The word Greek operators use; the formal Greek phrase appears on no real form.',

        // «Webhook» has no Greek word an operator would recognise. Every Greek
        // developer and every Greek integration guide says webhook, and a
        // translation would be a word this audience has to translate back.
        'enums.notification_channel.webhook.label' => 'Technical term with no Greek equivalent in use.',
    ],

    'literals' => [
        // Filled as the scanner finds genuine exceptions. Empty is the goal.
    ],

];
