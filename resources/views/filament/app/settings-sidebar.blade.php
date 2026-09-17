{{--
    The foot of the sidebar: «Στατιστικά», then «Ρυθμίσεις» at the very bottom
    (product owner, 2026-09-11; «Στατιστικά» joined it with Menu 1, 2026-09-16).

    Rendered in `SIDEBAR_FOOTER`, which sits outside the scrolling menu, so it
    stays at the bottom however long the menu above it is. Filament's own item
    component, so the badge, the active state and the collapsed-sidebar tooltip
    are the same as every other entry's.

    «Στατιστικά» is here rather than in a group because Filament puts an
    ungrouped entry at the top of the menu, and the menu reads top to bottom
    from today's work to the things you look up.

    The settings list keeps its own class, which `SettingsHubTest` uses to find
    it; the style is keyed on the wrapper so that class appears only when the
    entry does.

    A scoped `<style>` rather than utility classes, because Filament's CSS is
    precompiled and the spacing here is not guaranteed to be in it.
--}}
<div class="ka-sidebar-foot">
    @if ($analytics !== null)
        <ul role="list">
            <x-filament-panels::sidebar.item
                :url="$analytics['url']"
                :icon="$analytics['icon']"
                :active="$analytics['active']"
            >
                {{ $analytics['label'] }}
            </x-filament-panels::sidebar.item>
        </ul>
    @endif

    @if ($settings)
        <ul role="list" class="ka-sidebar-settings">
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
    @endif
</div>

<style>
    .ka-sidebar-foot {
        display: grid;
        gap: .25rem;
        padding: .75rem 1rem;
        /* The blue sidebar's rule colour (`filament.app.sea`), in both modes. */
        border-top: 1px solid var(--ka-sea-line, rgba(var(--gray-950), .06));
    }

    .ka-sidebar-foot ul { list-style: none; margin: 0; padding: 0; }

    .dark .ka-sidebar-foot { border-top-color: var(--ka-sea-line, rgba(255, 255, 255, .1)); }
</style>
