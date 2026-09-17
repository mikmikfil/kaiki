{{--
    The top bar on a phone: a «Μενού» button (mobile direction A,
    product owner 2026-09-17). Below the desktop breakpoint only; from `lg` up
    the blue sidebar is the menu and this is not shown.

    It replaces Filament's bare three-line icon, which is hidden in
    `mobile-menu.blade.php`: an icon with no word was ruled out for this panel.
    The operator's name is in the menu's own heading, not here: next to the
    avatar and the language switch it had room for four letters. The button
    only announces that the menu should open; the menu listens.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<div class="ka-mtop">
    <button type="button" class="ka-mobile-menu-btn" x-data x-on:click="$dispatch('ka-menu-open')" aria-haspopup="dialog">
        <x-filament::icon icon="heroicon-o-squares-2x2" class="ka-mtop-ic" />
        <span>{{ __('panel.mobile_menu.open') }}</span>
    </button>
</div>
