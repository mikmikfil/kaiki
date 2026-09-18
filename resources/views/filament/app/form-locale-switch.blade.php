{{--
    One language switch for the whole form (product owner, 2026-09-17).

    Every translatable field used to carry its own pair of language tabs, so a
    trip page with eight of them asked the same question eight times and let an
    operator write the summary in Greek and the description in English without
    ever noticing. This asks once, at the top, and every Greek field on the form
    shows or hides together with it.

    ## Why it switches with CSS rather than with a round trip

    Both languages are always present in the form's state and both are always
    submitted — this only decides which of them is on screen. A Livewire
    round trip per switch would re-render the whole form to change nothing but
    visibility, and would lose focus in a field somebody is typing in.

    ## The error case, which is the reason this is not two `hidden` fields

    A required Greek title that is empty fails validation while the English tab
    is open. If the Greek fields were removed from the DOM the operator would be
    told the form is invalid and shown nothing wrong. So the fields stay in the
    page, and on a validation error the switch moves itself to the language that
    has one.
--}}
<div
    x-data="{
        locale: (() => {
            try {
                return localStorage.getItem('ka-form-locale') || '{{ \App\Support\Locale\LocaleResolver::installed()[0] ?? 'el' }}';
            } catch (e) {
                return '{{ \App\Support\Locale\LocaleResolver::installed()[0] ?? 'el' }}';
            }
        })(),

        pick(locale) {
            this.locale = locale;

            try {
                localStorage.setItem('ka-form-locale', locale);
            } catch (e) {
                // A private window refuses storage; the switch still works for
                // this page, which is all it has to do.
            }
        },

        /** Move to whichever language is being complained about. */
        followError() {
            this.$nextTick(() => {
                const form = this.$root.closest('form') ?? document;

                for (const locale of @js(\App\Support\Locale\LocaleResolver::installed())) {
                    const block = form.querySelector('.ka-locale--' + locale + ' [data-validation-error], .ka-locale--' + locale + ' .fi-fo-field-wrp-error-message');

                    if (block) {
                        this.pick(locale);

                        return;
                    }
                }
            });
        },
    }"
    x-init="$el.closest('form')?.setAttribute('data-ka-locale', locale);
            $watch('locale', (value) => $el.closest('form')?.setAttribute('data-ka-locale', value))"
    x-on:form-validation-error.window="followError()"
    class="ka-locale-switch"
>
    <span class="ka-locale-switch-label">{{ __('catalog.product.form.locale_switch') }}</span>

    <div class="ka-locale-switch-buttons" role="group" aria-label="{{ __('catalog.product.form.locale_switch') }}">
        @foreach (\App\Support\Locale\LocaleResolver::installed() as $locale)
            <button
                type="button"
                x-on:click="pick(@js($locale))"
                x-bind:aria-pressed="locale === @js($locale) ? 'true' : 'false'"
                x-bind:class="locale === @js($locale) ? 'ka-locale-on' : ''"
            >{{ __("enums.locale.{$locale}.label") }}</button>
        @endforeach
    </div>
</div>
