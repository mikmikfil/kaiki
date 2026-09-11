{{--
    «Ρυθμίσεις», at the very bottom of the sidebar (product owner, 2026-09-11).

    Rendered in `SIDEBAR_FOOTER`, which sits outside the scrolling menu, so it
    stays at the bottom however long the menu above it is. Filament's own item
    component, so the badge, the active state and the collapsed-sidebar tooltip
    are the same as every other entry's.

    A scoped `<style>` rather than utility classes, because Filament's CSS is
    precompiled and the spacing here is not guaranteed to be in it.
--}}
<div class="ka-sidebar-settings">
    <ul role="list">
        <x-filament-panels::sidebar.item
            :url="$url"
            :icon="$icon"
            :active="$active"
            :badge="$badge"
            badge-color="danger"
        >
            {{ $label }}
        </x-filament-panels::sidebar.item>
    </ul>
</div>

<style>
    .ka-sidebar-settings {
        padding: .75rem 1rem;
        border-top: 1px solid rgba(var(--gray-950), .06);
    }

    .ka-sidebar-settings ul { list-style: none; margin: 0; padding: 0; }

    .dark .ka-sidebar-settings { border-top-color: rgba(255, 255, 255, .1); }
</style>
