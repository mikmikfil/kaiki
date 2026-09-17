{{--
    Tabs between screens that share one sidebar entry (Menu 1, 2026-09-16).
    See {@see \App\Filament\App\Navigation\SiblingScreens}.

    Plain links styled like Filament's own tabs, with a scoped `<style>`,
    because Filament's CSS is precompiled and a utility class it never used
    does not exist at runtime.
--}}
@if ($tabs !== [])
    <nav class="ka-sibling-tabs" aria-label="{{ __('panel.nav.related') }}">
        @foreach ($tabs as $tab)
            <a
                href="{{ $tab['url'] }}"
                @class(['ka-sibling-tab', 'is-active' => $tab['active']])
                @if ($tab['active']) aria-current="page" @endif
            >{{ $tab['label'] }}</a>
        @endforeach
    </nav>

    <style>
        .ka-sibling-tabs {
            display: inline-flex;
            flex-wrap: wrap;
            gap: .25rem;
            padding: .25rem;
            border-radius: .75rem;
            background: #fff;
            box-shadow: 0 0 0 1px rgba(15, 46, 87, .08);
        }

        .ka-sibling-tab {
            padding: .375rem .875rem;
            border-radius: .5rem;
            font-size: .875rem;
            font-weight: 500;
            color: rgb(82 82 91);
            text-decoration: none;
        }

        .ka-sibling-tab:hover {
            background: #EAF1FA;
            color: #0F2E57;
        }

        .ka-sibling-tab.is-active {
            background: #0F2E57;
            color: #fff;
        }

        .ka-sibling-tab:focus-visible {
            outline: 2px solid #1E5AA8;
            outline-offset: 2px;
        }

        .dark .ka-sibling-tabs {
            background: rgb(24 24 27);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, .1);
        }

        .dark .ka-sibling-tab {
            color: rgb(212 212 216);
        }

        .dark .ka-sibling-tab:hover {
            background: rgb(39 39 42);
            color: #fff;
        }

        .dark .ka-sibling-tab.is-active {
            background: #1C4378;
            color: #fff;
        }
    </style>
@endif
