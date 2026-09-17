{{--
    Menu 1 as boxes on a phone (mobile direction A, product owner 2026-09-17).

    A full-screen layer over the page, opened by the «Μενού» button in the top
    bar and closed by «Κλείσιμο», by Escape, or by choosing a screen. Grouped
    as the sidebar is, three boxes to a row, each with its icon *and* its word,
    and the sidebar's counts on the boxes that have them. The entries come from
    `BoxMenu`, which reads Filament's own navigation.

    Below `lg` only. Filament's slide-in sidebar and its three-line button are
    hidden at the same width, so a phone has exactly one way round the panel.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<div
    class="ka-menu"
    x-data="{ open: false }"
    x-on:ka-menu-open.window="open = true; $nextTick(() => $refs.close.focus())"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    x-transition.opacity.duration.150ms
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('panel.mobile_menu.open') }}"
>
    <div class="ka-menu-head">
        <div>
            <div class="ka-menu-title">{{ __('panel.mobile_menu.open') }}</div>
            <div class="ka-menu-sub">{{ $tenant }}</div>
        </div>

        <button type="button" class="ka-menu-close" x-ref="close" x-on:click="open = false">
            <x-filament::icon icon="heroicon-o-x-mark" class="ka-menu-ic" />
            <span>{{ __('panel.mobile_menu.close') }}</span>
        </button>
    </div>

    <nav class="ka-menu-body">
        @foreach ($groups as $group)
            @if ($group['label'])
                <div class="ka-menu-group">{{ $group['label'] }}</div>
            @endif

            <div @class(['ka-menu-boxes', 'is-foot' => $group['label'] === null])>
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" @class(['ka-menu-box', 'is-active' => $item['active']]) x-on:click="open = false">
                        @if ($item['badge'])
                            <span class="ka-menu-badge">{{ $item['badge'] }}</span>
                        @endif
                        @if ($item['icon'])
                            <x-filament::icon :icon="$item['icon']" class="ka-menu-ic" />
                        @endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</div>

<style>
    [x-cloak].ka-menu { display: none !important; }

    .ka-mtop { display: none; }

    @media (max-width: 1023.98px) {
        /* One way round the panel on a phone: this menu, not Filament's drawer. */
        .fi-topbar-open-sidebar-btn,
        .fi-topbar-close-sidebar-btn { display: none !important; }

        /* And the drawer itself: a browser that once opened it has `isOpen`
           stored, and would slide it in with no button left to close it. */
        .fi-sidebar,
        .fi-sidebar-close-overlay { display: none !important; }

        .ka-mtop { display: flex; align-items: center; gap: .75rem; flex: 1; min-width: 0; }
        .ka-mobile-menu-btn {
            order: -1;
            display: inline-flex; align-items: center; gap: .4rem;
            min-height: 2.75rem; padding: 0 .85rem; border-radius: .7rem;
            background: #0F2E57; color: #fff; font-weight: 600; font-size: .95rem;
        }
        .ka-mtop-ic { width: 1.2rem; height: 1.2rem; }

        .ka-menu {
            position: fixed; inset: 0; z-index: 60; overflow-y: auto;
            background: #0F2E57; color: #fff;
            padding: max(1rem, env(safe-area-inset-top)) 1rem 2rem;
        }
        .ka-menu-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .75rem; }
        .ka-menu-title { font-size: 1.5rem; font-weight: 800; }
        .ka-menu-sub { font-size: .875rem; color: #8FAAD0; }
        .ka-menu-close {
            display: inline-flex; align-items: center; gap: .4rem;
            min-height: 2.75rem; padding: 0 .9rem; border-radius: .7rem;
            background: #1C4378; color: #fff; font-weight: 600;
        }
        .ka-menu-ic { width: 1.5rem; height: 1.5rem; }
        .ka-menu-group { margin: 1.1rem 0 .5rem; font-size: .85rem; font-weight: 600; color: #8FAAD0; }
        .ka-menu-boxes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; }
        .ka-menu-boxes.is-foot { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 1.1rem; }
        .ka-menu-box {
            position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .45rem;
            min-height: 5.5rem; padding: .6rem .35rem; border-radius: .9rem;
            background: #17396A; color: #E4ECF7; text-decoration: none;
            font-size: .85rem; font-weight: 600; text-align: center; line-height: 1.2;
        }
        .ka-menu-box.is-active { background: #fff; color: #0F2E57; }
        .ka-menu-badge {
            position: absolute; top: .4rem; right: .45rem; min-width: 1.3rem; padding: 0 .35rem;
            border-radius: 99px; background: #F2B8B5; color: #7A1C17;
            font-size: .72rem; font-weight: 700; line-height: 1.3rem;
        }

        @media (min-width: 640px) {
            .ka-menu-boxes { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
    }

    @media (min-width: 1024px) {
        .ka-menu { display: none !important; }
    }
</style>
