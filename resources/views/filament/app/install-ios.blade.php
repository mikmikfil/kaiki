{{--
    The two steps for an iPhone, which cannot be asked to install a web app
    and has to be told where Apple put the button (PWA, 2026-09-23). Opened by
    «Εγκατάσταση εφαρμογής» in the user menu.

    The words are the iPhone's own, «Κοινή χρήση» and «Προσθήκη στην οθόνη
    Αφετηρίας», so they can be found as written. The share icon is drawn as
    Safari draws it.
--}}
<x-filament::modal id="kaiki-install-ios" width="sm" :heading="__('pwa.ios.heading')">
    <ol class="kaiki-install-steps">
        <li>
            <span class="kaiki-install-step-icon">
                <x-filament::icon icon="heroicon-o-arrow-up-on-square" class="h-5 w-5" />
            </span>
            <span>{{ __('pwa.ios.step_share') }}</span>
        </li>
        <li>
            <span class="kaiki-install-step-icon">
                <x-filament::icon icon="heroicon-o-plus-circle" class="h-5 w-5" />
            </span>
            <span>{{ __('pwa.ios.step_add') }}</span>
        </li>
    </ol>

    <x-slot name="footerActions">
        <x-filament::button color="primary" x-on:click="close()" class="w-full">
            {{ __('pwa.ios.done') }}
        </x-filament::button>
    </x-slot>
</x-filament::modal>

<style>
    .kaiki-install-steps { display: grid; gap: .75rem; margin: 0; padding: 0; list-style: none; }
    .kaiki-install-steps li { display: flex; align-items: center; gap: .75rem; font-size: .9375rem; line-height: 1.4; }
    .kaiki-install-step-icon {
        display: inline-flex; flex: none; align-items: center; justify-content: center;
        inline-size: 2.25rem; block-size: 2.25rem; border-radius: .625rem;
        background: #EAF0F8; color: #0F2E57;
    }
    .dark .kaiki-install-step-icon { background: rgb(255 255 255 / .08); color: #C4D3E8; }
</style>
