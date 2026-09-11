{{--
    «Ρυθμίσεις», as cards (product owner, 2026-09-11).

    The whole card is the link, so on a phone the tap target is the size of the
    card rather than the size of its title.

    **A scoped `<style>` rather than utility classes.** Filament ships
    precompiled CSS, and the grid steps this page needs are not in it. The same
    reasoning as `locale-switcher.blade.php`.

    **Container queries, not viewport ones.** The column count depends on how
    wide the content area is, and that depends on whether the sidebar is open,
    which the viewport knows nothing about. The cards are **squares**, four to
    a row from a laptop up, three on a tablet and two on a phone (product
    owner, 2026-09-11 — after trying three wide cards to a row).

    No `text-transform: uppercase` anywhere, because uppercasing Greek strips
    the accents (I18N-2). No hardcoded strings: `NoHardcodedStringsTest` scans
    this directory.
--}}
<x-filament-panels::page>
    <div class="ka-hub">
        @foreach ($this->sections() as $section)
            <section class="ka-hub-section" aria-labelledby="ka-hub-{{ $section['key'] }}">
                <h2 id="ka-hub-{{ $section['key'] }}" class="ka-hub-heading">{{ $section['heading'] }}</h2>

                <ul class="ka-hub-grid" role="list">
                    @foreach ($section['cards'] as $card)
                        <li>
                            <a href="{{ $card['url'] }}" class="ka-hub-card" data-card="{{ $card['key'] }}">
                                <span class="ka-hub-icon" aria-hidden="true">
                                    @if ($card['icon'])
                                        <x-filament::icon :icon="$card['icon']" class="ka-hub-glyph" />
                                    @endif
                                </span>

                                <span class="ka-hub-text">
                                    <span class="ka-hub-title">
                                        <span>{{ $card['title'] }}</span>

                                        @if (filled($card['badge']))
                                            <x-filament::badge :color="$card['badge_color'] ?? 'gray'" size="sm">
                                                {{ $card['badge'] }}
                                            </x-filament::badge>
                                        @endif
                                    </span>

                                    <span class="ka-hub-description">{{ $card['description'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <style>
        .ka-hub { container-type: inline-size; }

        .ka-hub-section + .ka-hub-section { margin-top: 2.25rem; }

        .ka-hub-heading {
            font-size: .9375rem; font-weight: 600;
            color: rgb(var(--gray-500));
            margin: 0 0 .85rem;
        }

        .ka-hub-grid {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: 1.1rem;
            grid-template-columns: minmax(0, 1fr);
        }

        /* Squares, so two to a row even on a phone: one square a screen wide
           would be a poster. Three on a tablet, four from a laptop up. */
        /* `--ka-square` is one column's width, in container units, so a card's
           minimum height equals its width. */
        .ka-hub-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            --ka-square: calc((100cqi - 1.1rem) / 2);
        }

        @container (min-width: 36rem) {
            .ka-hub-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); --ka-square: calc((100cqi - 2.2rem) / 3); }
        }

        @container (min-width: 50rem) {
            .ka-hub-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); --ka-square: calc((100cqi - 3.3rem) / 4); }
        }

        .ka-hub-grid > li { display: block; min-width: 0; }

        /* Square by **minimum height**, not by `aspect-ratio`.
           `aspect-ratio` was tried twice and failed both ways on a phone: in a
           stretched flex item a taller neighbour's height became this card's
           width and pushed it off the screen; with the height pinned instead,
           a long Greek description ran out of the bottom. A minimum height of
           one column's width is square when the text fits, grows when it does
           not, and `height: 100%` of the stretched `li` keeps every card in a
           row the same height. */
        .ka-hub-card {
            box-sizing: border-box;
            width: 100%; height: 100%; min-width: 0;
            min-height: var(--ka-square);
            overflow-wrap: break-word;
            display: flex; flex-direction: column; justify-content: space-between; gap: 1rem;
            padding: 1.35rem;
            border-radius: .9rem;
            background: #fff;
            box-shadow: 0 0 0 1px rgba(var(--gray-950), .06), 0 1px 2px rgba(0, 0, 0, .04);
            color: inherit; text-decoration: none;
            transition: box-shadow .15s ease, transform .15s ease;
        }

        .ka-hub-card:hover {
            box-shadow: 0 0 0 1px rgba(var(--primary-500), .45), 0 4px 12px rgba(var(--gray-950), .06);
        }

        .ka-hub-card:focus-visible {
            outline: 2px solid rgb(var(--primary-600));
            outline-offset: 2px;
        }

        .ka-hub-icon {
            flex: none;
            display: inline-flex; align-items: center; justify-content: center;
            width: 3.25rem; height: 3.25rem;
            border-radius: .8rem;
            background: rgba(var(--primary-500), .1);
            color: rgb(var(--primary-600));
        }

        .ka-hub-glyph { width: 1.75rem; height: 1.75rem; }

        .ka-hub-text { display: flex; flex-direction: column; gap: .35rem; min-width: 0; }

        @container (max-width: 36rem) {
            .ka-hub-card { padding: 1rem; }
            .ka-hub-icon { width: 2.75rem; height: 2.75rem; }
            .ka-hub-title { font-size: .975rem; }
            .ka-hub-description { font-size: .8125rem; }
        }

        .ka-hub-title {
            display: flex; align-items: center; flex-wrap: wrap; gap: .45rem;
            font-size: 1.0625rem; font-weight: 600; line-height: 1.3;
            color: rgb(var(--gray-950));
        }

        .ka-hub-description {
            font-size: .9rem; line-height: 1.45;
            color: rgb(var(--gray-500));
        }

        /* The panel still offers a dark theme; the cards follow it rather than
           sitting white on a dark page. */
        .dark .ka-hub-card {
            background: rgb(var(--gray-900));
            box-shadow: 0 0 0 1px rgba(255, 255, 255, .1);
        }

        .dark .ka-hub-card:hover { box-shadow: 0 0 0 1px rgba(var(--primary-400), .5); }
        .dark .ka-hub-title { color: #fff; }
        .dark .ka-hub-description, .dark .ka-hub-heading { color: rgb(var(--gray-400)); }
        .dark .ka-hub-icon { color: rgb(var(--primary-400)); }

        @media (prefers-reduced-motion: reduce) { .ka-hub-card { transition: none; } }
    </style>
</x-filament-panels::page>
