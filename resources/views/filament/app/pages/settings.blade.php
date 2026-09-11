{{--
    «Ρυθμίσεις», as cards (product owner, 2026-09-11).

    The whole card is the link, so on a phone the tap target is the size of the
    card rather than the size of its title.

    **A scoped `<style>` rather than utility classes.** Filament ships
    precompiled CSS, and the grid steps this page needs are not in it. The same
    reasoning as `locale-switcher.blade.php`.

    **Container queries, not viewport ones.** The column count depends on how
    wide the content area is, and that depends on whether the sidebar is open,
    which the viewport knows nothing about. So it is one column on a phone, two
    on a tablet, three beside an open sidebar on a laptop, and four when there
    is room.

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

        .ka-hub-section + .ka-hub-section { margin-top: 1.75rem; }

        .ka-hub-heading {
            font-size: .875rem; font-weight: 600;
            color: rgb(var(--gray-500));
            margin: 0 0 .65rem;
        }

        .ka-hub-grid {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: .75rem;
            grid-template-columns: minmax(0, 1fr);
        }

        @container (min-width: 30rem) { .ka-hub-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @container (min-width: 46rem) { .ka-hub-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @container (min-width: 64rem) { .ka-hub-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }

        .ka-hub-grid > li { display: flex; }

        .ka-hub-card {
            flex: 1;
            display: flex; align-items: flex-start; gap: .85rem;
            padding: 1rem 1.1rem;
            border-radius: .75rem;
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
            width: 2.5rem; height: 2.5rem;
            border-radius: .6rem;
            background: rgba(var(--primary-500), .1);
            color: rgb(var(--primary-600));
        }

        .ka-hub-glyph { width: 1.35rem; height: 1.35rem; }

        .ka-hub-text { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }

        .ka-hub-title {
            display: flex; align-items: center; flex-wrap: wrap; gap: .4rem;
            font-size: .9375rem; font-weight: 600; line-height: 1.3;
            color: rgb(var(--gray-950));
        }

        .ka-hub-description {
            font-size: .8125rem; line-height: 1.4;
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
