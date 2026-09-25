{{--
    «Εγκατάσταση εφαρμογής» in the user menu (PWA, 2026-09-23).

    Quiet on purpose: one item, under the profile, and only when there is
    something to do. Chrome shows it once it has offered the install
    (`beforeinstallprompt`); an iPhone shows it always, since it has no such
    offer, and there it opens the two steps instead. Running as the app
    already, it is not there at all. The logic is in `pwa-head.blade.php`,
    which catches Chrome's offer before this menu exists.
--}}
<div x-data="kaikiInstall" x-show="available" x-cloak>
    <x-filament::dropdown.list>
        <x-filament::dropdown.list.item
            icon="heroicon-m-arrow-down-tray"
            tag="button"
            x-on:click="close(); install()"
        >
            {{ __('pwa.install.menu') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</div>
