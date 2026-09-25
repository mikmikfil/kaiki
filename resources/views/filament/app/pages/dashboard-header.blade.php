{{--
    The home page's header: the greeting, and the day's two actions,
    «Σάρωση εισιτηρίων» and «Πώληση τώρα» (Mike, 2026-09-25, direction Β of
    docs/mockups/dashboard-quick-actions.html).

    - From a tablet up, the actions sit in the greeting's row, top right, where
      every other Filament page keeps its buttons: white «Πώληση τώρα · 08:30»,
      then navy «Σάρωση εισιτηρίων». The day and time go under the greeting to
      make room.
    - On a phone, a bar fixed to the bottom of the screen, the same two side by
      side — «Πώληση τώρα» left, «Σάρωση» under the right thumb — so they stay
      there however far down the boats go. One action takes the whole bar; none,
      and there is no bar.

    Which actions, and their words, come from `DayByBoat::quickActions()`: the
    same rules as the card's old buttons (who may sell, the boarding switches).
    Drawn here and not in the widget because the header is the page's, and a
    bar inside a polled widget would flicker with every refresh.

    The bar sits above the top bar (z 20) and under Filament's sidebar and its
    overlay (30), modals (40), notifications (50) and the phone menu (60).

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
@php
    // Sell first, then scan: left to right in the header and in the bar, so
    // the one used most is last, at the right edge and under the right thumb.
    $ordered = array_reverse($actions);
@endphp

<header @class(['fi-header ka-dash-header', 'has-actions' => $actions !== []])>
    <div class="ka-dash-heading">
        <h1 class="fi-header-heading text-2xl font-bold tracking-tight text-gray-950 dark:text-white sm:text-3xl">
            {{ $heading }}
        </h1>
    </div>

    @if ($actions !== [])
        <div class="ka-hbtns">
            @foreach ($ordered as $action)
                <a
                    @class(['ka-hbtn', 'is-pri' => $action['key'] === 'scan', 'is-sec' => $action['key'] !== 'scan'])
                    data-action="{{ $action['key'] }}"
                    href="{{ $action['url'] }}"
                    title="{{ $action['hint'] }}"
                >
                    <x-filament::icon :icon="$action['icon']" class="ka-hbtn-ic" />
                    <span>{{ $action['label'] }}</span>
                    @if ($action['time'] !== null)
                        <span class="ka-hbtn-dim">· {{ $action['time'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        <nav @class(['ka-bar', 'is-one' => count($actions) === 1]) aria-label="{{ __('dashboard.home.actions.bar_label') }}">
            @foreach ($ordered as $action)
                <a
                    @class(['ka-bbtn', 'is-pri' => $action['key'] === 'scan', 'is-sec' => $action['key'] !== 'scan'])
                    data-action="{{ $action['key'] }}"
                    href="{{ $action['url'] }}"
                    aria-label="{{ $action['label'] }} · {{ $action['hint'] }}"
                >
                    <x-filament::icon :icon="$action['icon']" class="ka-bbtn-ic" />
                    <span>{{ $action['short'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif
</header>

<style>
    .ka-dash-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem 1.5rem; }
    .ka-dash-heading { flex: 1 1 auto; min-width: 0; }

    .ka-hbtns, .ka-bar { --ka-deep: #0F2E57; --ka-line-hover: #B9CBE3; --ka-muted: #5B6B82; }
    .ka-hbtn, .ka-bbtn { text-decoration: none; -webkit-tap-highlight-color: transparent; }
    .ka-hbtn:hover, .ka-bbtn:hover, .ka-hbtn:focus, .ka-bbtn:focus { text-decoration: none; }
    .ka-hbtn:focus-visible, .ka-bbtn:focus-visible { outline: 2px solid #1E5AA8; outline-offset: 2px; }
    .ka-hbtn.is-pri, .ka-bbtn.is-pri { background: var(--ka-deep); color: #fff; }
    .ka-hbtn.is-pri:hover, .ka-bbtn.is-pri:hover { background: #1C4378; }
    .ka-hbtn.is-sec, .ka-bbtn.is-sec { background: #fff; color: var(--ka-deep); border: 1.5px solid var(--ka-line-hover); }
    .ka-hbtn.is-sec:hover, .ka-bbtn.is-sec:hover { border-color: #1C4378; background: #F5F8FC; }

    /* From a tablet up: two buttons in the greeting's row, 44px high. */
    .ka-hbtns { display: none; }
    .ka-hbtn {
        display: inline-flex; align-items: center; gap: .5rem; height: 2.75rem; padding: 0 1.125rem;
        border-radius: .625rem; font-weight: 700; font-size: .9375rem; white-space: nowrap;
    }
    .ka-hbtn-ic { width: 1.25rem; height: 1.25rem; flex: none; }
    .ka-hbtn-dim { font-weight: 500; color: var(--ka-muted); font-variant-numeric: tabular-nums; }

    /* On a phone: a bar fixed to the bottom, clear of the home indicator. */
    .ka-bar {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 25;
        display: grid; grid-template-columns: 1fr 1fr; gap: .625rem;
        padding: .625rem 1rem max(.625rem, env(safe-area-inset-bottom));
        background: rgba(255, 255, 255, .96); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
        border-top: 1px solid #E1E8F2; box-shadow: 0 -8px 24px -18px rgba(15, 46, 87, .5);
    }
    .ka-bar.is-one { grid-template-columns: minmax(0, 1fr); }
    .ka-bbtn {
        display: flex; align-items: center; justify-content: center; gap: .5rem; min-width: 0; height: 3.375rem;
        border-radius: .875rem; font-weight: 700; font-size: .9375rem; white-space: nowrap;
    }
    .ka-bbtn span { overflow: hidden; text-overflow: ellipsis; }
    .ka-bbtn-ic { width: 1.25rem; height: 1.25rem; flex: none; }
    /* So the last boat is not hidden under the bar: its height, plus air. */
    @media (max-width: 767.98px) {
        .fi-body:has(.ka-bar) .fi-main { padding-bottom: calc(6rem + env(safe-area-inset-bottom)); }
    }

    .dark .ka-bar { background: rgba(17, 24, 39, .94); border-top-color: rgba(255, 255, 255, .1); }
    .dark .ka-hbtn.is-sec, .dark .ka-bbtn.is-sec { background: rgb(var(--gray-900)); color: #fff; border-color: rgba(255, 255, 255, .22); }
    .dark .ka-hbtn-dim { color: rgb(var(--gray-400)); }

    @media (min-width: 768px) {
        .ka-hbtns { display: flex; gap: .625rem; flex-wrap: wrap; }
        .ka-bar { display: none; }
    }
</style>
