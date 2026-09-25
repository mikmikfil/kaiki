{{--
    The home page: the day, by boat («version 3», product owner 2026-09-17).

    Built from boxes, phone first: one column on a phone, the next departure
    beside the four boxes from a laptop up, and the boats as lanes on a
    timeline once there is room for one. Numbers are few and every one says
    what it counts in words. Every bar is positioned by `CalendarDay`.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
@php
    $next = $this->getNext();
    $boxes = $this->getBoxes();
    $day = $this->getDay();
    $now = $day->nowFraction();
    $calendarUrl = $this->getCalendarUrl();
    $crew = $this->isCrew();
@endphp

<x-filament-widgets::widget>
    <div @class(['kd', 'is-crew' => $crew])>
        {{-- «Σάρωση εισιτηρίων» and «Πώληση τώρα» are not on this widget any
             more: they sit in the page header on a tablet or computer and in a
             bar fixed to the bottom of a phone (Mike, 2026-09-25, direction Β
             of docs/mockups/dashboard-quick-actions.html), both drawn by
             `pages/dashboard-header.blade.php`. The card only says what is
             leaving. --}}
        <div class="kd-top">
            {{-- 1. The next departure: information only --}}
            <div class="kd-next">
                {{-- A ship's helm, barely there, turning very slowly (Mike chose
                     it from docs/mockups/nautical-icons.html, 2026-09-23: «ακόμα
                     πιο αχνό, να γυρίζει πολύ αργά»). Only on this card — the one
                     blue card, the one about the boat that is leaving. --}}
                <img class="kd-helm" src="{{ asset('images/nautical/helm.svg') }}" alt="" aria-hidden="true">
                @if ($next)
                    <div class="kd-next-when">{{ $next['mine'] ? __('dashboard.home.next.mine') : __('dashboard.home.next.label') }} · {{ $next['when'] }}</div>

                    <div class="kd-next-row">
                        <div>
                            <div class="kd-next-time">{{ $next['time'] }}</div>
                            @if ($next['url'])
                                <a class="kd-next-trip" href="{{ $next['url'] }}">{{ $next['trip'] }}</a>
                            @else
                                <div class="kd-next-trip">{{ $next['trip'] }}</div>
                            @endif
                            <div class="kd-next-where">{{ $next['where'] }}</div>
                            @if ($next['role'] !== null)
                                <span class="kd-role">{{ $next['role'] }}</span>
                            @endif
                        </div>

                        {{-- Nobody booked yet: "0/0 aboard" would be a number
                             that says nothing, so the count waits for people.
                             Boarding switched off: how full it is, instead. --}}
                        @if (! $next['boards'])
                            <div class="kd-next-aboard">
                                <div class="kd-next-count">{{ $next['booked'] }}/{{ $next['capacity'] }}</div>
                                <div class="kd-next-where">{{ __('dashboard.home.next.booked') }}</div>
                            </div>
                        @elseif ($next['expected'] > 0)
                            <div class="kd-next-aboard">
                                <div class="kd-next-count">{{ $next['aboard'] }}/{{ $next['expected'] }}</div>
                                <div class="kd-next-where">{{ __('dashboard.home.next.aboard') }}</div>
                            </div>
                        @endif
                    </div>

                    @if ($next['boards'] && $next['expected'] > 0)
                        <div class="kd-progress" role="progressbar" aria-valuenow="{{ $next['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                            <i style="width: {{ $next['percent'] }}%"></i>
                        </div>
                    @endif
                @else
                    <div class="kd-next-when">{{ __('dashboard.home.next.label') }}</div>
                    <div class="kd-next-trip">{{ __('dashboard.home.next.none') }}</div>
                @endif
            </div>

            {{-- 2. Four boxes, one question each --}}
            @if ($boxes !== [])
            <div class="kd-boxes">
                @foreach ($boxes as $box)
                    <a class="kd-box" href="{{ $box['url'] }}">
                        @if ($box['count'] !== null)
                            <span @class(['kd-count', 'is-alert' => $box['alert']])>{{ $box['count'] }}</span>
                        @endif
                        <span @class(['kd-box-ic', 'is-alert' => $box['alert']])>
                            <x-filament::icon :icon="$box['icon']" class="kd-ic" />
                        </span>
                        <span class="kd-box-text">
                            <b>{{ $box['label'] }}</b>
                            <small>{{ $box['detail'] }}</small>
                        </span>
                    </a>
                @endforeach
            </div>
            @endif
        </div>

        {{-- 3. Today, by boat --}}
        <section class="kd-boats">
            <div class="kd-section-title">
                <h3>{{ __('dashboard.home.boats.heading') }}</h3>
                @if ($calendarUrl)
                    <a href="{{ $calendarUrl }}">{{ __('dashboard.home.boats.calendar') }}</a>
                @endif
            </div>

            @if ($day->rows === [])
                <p class="kd-empty">{{ __('attention.today.no_vessels') }}</p>
            @elseif ($day->isEmpty())
                <p class="kd-empty">{{ __('attention.today.nothing_out') }}</p>
            @else
                {{-- A box per boat: the phone. A boat with nothing on today
                     gets one line in a shared box at the end instead of a card
                     of its own — ten cards ran to ~1,900px on a phone, and the
                     idle ones said the least (phone audit, 2026-09-23). --}}
                @php($busy = array_values(array_filter($day->rows, fn ($row) => $row['bars'] !== [])))
                @php($idle = array_values(array_filter($day->rows, fn ($row) => $row['bars'] === [])))
                <div class="kd-boatboxes">
                    @foreach ($busy as $row)
                        <div class="kd-boat">
                            <div class="kd-boat-head">
                                <b>{{ $row['vessel']->name }}</b>
                                <span>{{ trans_choice('dashboard.home.boats.seats', (int) $row['vessel']->capacity_max, ['count' => (int) $row['vessel']->capacity_max]) }}</span>
                            </div>

                            <div class="kd-mini">
                                @foreach ($row['bars'] as $bar)
                                    <i @class(['is-block' => $bar['kind'] === 'block', 'is-cancelled' => $bar['cancelled']])
                                       style="left: {{ $bar['start'] * 100 }}%; width: {{ max(($bar['end'] - $bar['start']) * 100, 1.5) }}%"></i>
                                @endforeach
                                @if ($now !== null)
                                    <span class="kd-now" style="left: {{ $now * 100 }}%"></span>
                                @endif
                            </div>

                            <div class="kd-deps">
                                @forelse ($row['bars'] as $bar)
                                    @php($url = $bar['kind'] === 'departure' ? $this->getDepartureUrl($bar['uuid']) : null)
                                    <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif @class(['kd-dep', 'is-muted' => $bar['kind'] === 'block' || $bar['cancelled']])>
                                        <span class="kd-dep-time">{{ $bar['detail'] ? substr((string) $bar['detail'], 0, 5) : '—' }}</span>
                                        <span class="kd-dep-name">{{ $bar['label'] }}</span>
                                        <span class="kd-dep-pax">{{ $bar['pax'] !== null ? $bar['pax'] . '/' . $bar['capacity'] : '' }}</span>
                                    </{{ $url ? 'a' : 'div' }}>
                                @empty
                                    <div class="kd-dep is-muted is-free"><span class="kd-dep-name">{{ __('dashboard.home.boats.free') }}</span></div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach

                    @if ($idle !== [])
                        <div class="kd-idle">
                            <b class="kd-idle-title">{{ trans_choice('dashboard.home.boats.idle', count($idle)) }}</b>
                            @foreach ($idle as $row)
                                <div class="kd-idle-row">
                                    <span>{{ $row['vessel']->name }}</span>
                                    <small>{{ trans_choice('dashboard.home.boats.seats', (int) $row['vessel']->capacity_max, ['count' => (int) $row['vessel']->capacity_max]) }}</small>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Lanes on a timeline: a tablet and up --}}
                <div class="kd-lanes">
                    <div class="kd-lane-row kd-ruler">
                        <div></div>
                        <div class="kd-lane">
                            @foreach ($day->hours() as $mark)
                                @if ($loop->index % 3 === 0)
                                    <span class="kd-tick" style="left: {{ $mark['at'] * 100 }}%">{{ $mark['label'] }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    @foreach ($day->rows as $row)
                        <div class="kd-lane-row">
                            <div class="kd-lane-name">
                                <b>{{ $row['vessel']->name }}</b>
                                <span>{{ trans_choice('dashboard.home.boats.seats', (int) $row['vessel']->capacity_max, ['count' => (int) $row['vessel']->capacity_max]) }}</span>
                            </div>
                            <div class="kd-lane">
                                @if ($now !== null)
                                    <span class="kd-now" style="left: {{ $now * 100 }}%"></span>
                                @endif
                                @foreach ($row['bars'] as $bar)
                                    @php($url = $bar['kind'] === 'departure' ? $this->getDepartureUrl($bar['uuid']) : null)
                                    @php($time = $bar['detail'] ? substr((string) $bar['detail'], 0, 5) : '')
                                    @php($pax = $bar['pax'] !== null ? $bar['pax'] . '/' . $bar['capacity'] : '')
                                    {{-- A narrow bar (a tablet, a short trip) drops
                                         the name for the time and seats, which
                                         fit; the full line stays in the title. --}}
                                    <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif
                                        @class(['kd-ev', 'is-block' => $bar['kind'] === 'block', 'is-cancelled' => $bar['cancelled']])
                                        style="left: {{ $bar['start'] * 100 }}%; width: calc({{ max(($bar['end'] - $bar['start']) * 100, 3) }}% - 3px)"
                                        title="{{ collect([$time, $bar['label'], $pax])->filter()->implode(' · ') }}">
                                        <b class="kd-ev-name">{{ $bar['label'] }}</b>
                                        <span class="kd-ev-meta"><span class="kd-ev-time">{{ $time }}</span>@if ($pax !== '')<span class="kd-ev-pax">{{ $time !== '' ? ' · ' : '' }}{{ $pax }}</span>@endif</span>
                                    </{{ $url ? 'a' : 'div' }}>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    <style>
        /*
         * Every colour is a variable on `.kd`, redefined once under `.dark .kd`
         * (phone audit, 2026-09-23: fifty light-only colours, white boxes on
         * the dark page and a section title at 1.26:1). The dark values lean on
         * Filament's own scales, so they follow the panel's grey and the dark
         * primary from `DarkPrimary`.
         */
        .kd {
            --kd-card: #fff;
            --kd-line: #E1E8F2;
            --kd-line-hover: #B9CBE3;
            --kd-rule: #EEF3F9;
            --kd-ink: #15233A;
            --kd-muted: #5B6B82;
            --kd-faint: #64748B;
            --kd-link: #1C4378;
            --kd-soft: #EAF1FA;
            --kd-soft-ink: #0F2E57;
            --kd-alert: #FDECEC;
            --kd-alert-ink: #B42318;
            --kd-track: #EEF3F9;
            --kd-lane: #F3F6FA;
            --kd-bar: #1C4378;
            --kd-bar-ink: #fff;
            --kd-block: #64748B;
            --kd-cancel: #CBD5E1;
            --kd-cancel-ink: #475569;
            --kd-now: #D97706;
            /* The one blue card, in both themes. */
            --kd-next: #0F2E57;
            --kd-next-line: transparent;

            display: grid; gap: 1.25rem;
        }
        .dark .kd {
            --kd-card: rgb(var(--gray-900));
            --kd-line: rgba(255, 255, 255, .1);
            --kd-line-hover: rgba(255, 255, 255, .22);
            --kd-rule: rgba(255, 255, 255, .07);
            --kd-ink: rgb(var(--gray-100));
            --kd-muted: rgb(var(--gray-400));
            --kd-faint: rgb(var(--gray-400));
            --kd-link: rgb(var(--primary-400));
            --kd-soft: rgba(var(--primary-400), .14);
            --kd-soft-ink: rgb(var(--primary-300));
            --kd-alert: rgba(var(--danger-500), .16);
            --kd-alert-ink: rgb(var(--danger-400));
            --kd-track: rgba(255, 255, 255, .08);
            --kd-lane: rgba(255, 255, 255, .04);
            --kd-bar: rgb(var(--primary-500));
            --kd-bar-ink: rgb(var(--primary-950));
            --kd-block: rgb(var(--gray-500));
            --kd-cancel: rgb(var(--gray-700));
            --kd-cancel-ink: rgb(var(--gray-200));
            --kd-now: #F59E0B;
            /* The card stays navy; a hairline keeps it off the black page. */
            --kd-next-line: rgba(255, 255, 255, .1);
        }
        .kd a { text-decoration: none; }

        .kd-top { display: grid; gap: .875rem; }

        /* Taller since the buttons left it (Mike, 25/9: «είναι μικρό το height
           τώρα»): more air, a bigger time, the «when» line at the top and the
           trip at the foot, and on a computer the height of the four boxes. */
        .kd-next {
            display: grid; gap: .75rem; padding: 1.4rem 1.4rem 1.5rem;
            min-height: 12.5rem; align-content: space-between;
            border-radius: 1rem; background: var(--kd-next); color: #fff;
            border: 1px solid var(--kd-next-line);
            position: relative; overflow: hidden; isolation: isolate;
        }
        /* The helm: white line art at 5%, cut by the top-right corner, one
           turn every four minutes. Still for anybody who asked for less motion. */
        .kd-helm {
            position: absolute; z-index: -1; pointer-events: none; user-select: none;
            /* A little bigger than first drawn (Mike, 2026-09-23: «κάνε πιο
               μεγάλο το τιμόνι» — and then «όχι τόσο» at 32rem). */
            width: min(26rem, 95%); height: auto; max-width: none;
            top: -8.75rem; right: -7.75rem;
            filter: brightness(0) invert(1); opacity: .05;
            animation: kd-helm-turn 240s linear infinite;
        }
        @keyframes kd-helm-turn { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .kd-helm { animation: none; } }
        .kd-next-when { font-size: .8125rem; font-weight: 600; color: #9FBBE0; }
        .kd-next-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; }
        .kd-next-time { font-size: 3rem; line-height: 1; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
        .kd-next-trip { display: block; margin-top: .45rem; font-size: 1.1875rem; font-weight: 700; color: #fff; }
        .kd-next-where { font-size: .8125rem; color: #C4D3E8; }
        .kd-role { display: inline-block; margin-top: .45rem; padding: .1rem .6rem; border-radius: 999px; background: #23497D; color: #fff; font-size: .75rem; font-weight: 700; }
        .kd-next-aboard { text-align: right; flex: none; }
        .kd-next-count { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .kd-progress { height: .4rem; border-radius: 99px; background: #23497D; overflow: hidden; }
        .kd-progress i { display: block; height: 100%; border-radius: inherit; background: #7FB0EE; }

        .kd-ic { width: 1.25rem; height: 1.25rem; }

        /* No boxes beside it for crew, so the card takes the whole row. */
        .kd.is-crew .kd-top { grid-template-columns: minmax(0, 1fr); }

        .kd-boxes { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .kd-box {
            position: relative; display: flex; flex-direction: column; gap: .6rem;
            min-height: 6.5rem; padding: .9rem; border-radius: 1rem;
            background: var(--kd-card); border: 1px solid var(--kd-line); color: var(--kd-ink);
        }
        .kd-box:hover { border-color: var(--kd-line-hover); }
        .kd-box-ic {
            display: grid; place-items: center; width: 2.25rem; height: 2.25rem;
            border-radius: .6rem; background: var(--kd-soft); color: var(--kd-soft-ink);
        }
        .kd-box-ic.is-alert { background: var(--kd-alert); color: var(--kd-alert-ink); }
        .kd-box-text { display: grid; gap: .1rem; }
        .kd-box-text b { font-size: .975rem; }
        .kd-box-text small { font-size: .8125rem; color: var(--kd-muted); }
        .kd-count {
            position: absolute; top: .7rem; right: .7rem; min-width: 1.4rem; padding: 0 .4rem;
            border-radius: 99px; background: var(--kd-soft); color: var(--kd-soft-ink);
            font-size: .75rem; font-weight: 700; line-height: 1.4rem; text-align: center;
        }
        .kd-count.is-alert { background: var(--kd-alert); color: var(--kd-alert-ink); }

        .kd-boats { display: grid; gap: .75rem; }
        .kd-section-title { display: flex; align-items: baseline; justify-content: space-between; }
        .kd-section-title h3 { font-size: 1.0625rem; font-weight: 700; color: var(--kd-ink); }
        .kd-section-title a { font-size: .875rem; font-weight: 600; color: var(--kd-link); }
        .kd-empty { font-size: .9rem; color: var(--kd-muted); }

        .kd-boatboxes { display: grid; gap: .75rem; }
        .kd-boat { display: grid; gap: .5rem; padding: .85rem .9rem .3rem; border-radius: 1rem; background: var(--kd-card); border: 1px solid var(--kd-line); }
        .kd-boat-head { display: flex; align-items: baseline; gap: .5rem; }
        .kd-boat-head b { font-size: 1rem; color: var(--kd-ink); }
        .kd-boat-head span { font-size: .8125rem; color: var(--kd-muted); }
        .kd-mini { position: relative; height: .6rem; border-radius: 99px; background: var(--kd-track); }
        .kd-mini i { position: absolute; top: 0; bottom: 0; border-radius: 99px; background: var(--kd-bar); }
        .kd-mini i.is-block { background: var(--kd-block); }
        .kd-mini i.is-cancelled { background: var(--kd-cancel); }
        .kd-now { position: absolute; top: -.2rem; bottom: -.2rem; width: 2px; margin-left: -1px; background: var(--kd-now); z-index: 1; }
        .kd-deps { display: grid; }
        .kd-dep {
            display: grid; grid-template-columns: 3.2rem 1fr auto; align-items: center; gap: .5rem;
            min-height: 2.6rem; border-top: 1px solid var(--kd-rule); color: var(--kd-ink); font-size: .925rem;
        }
        .kd-dep-time { font-variant-numeric: tabular-nums; font-weight: 700; }
        .kd-dep-pax { font-variant-numeric: tabular-nums; color: var(--kd-muted); font-size: .85rem; }
        .kd-dep.is-muted { color: var(--kd-faint); }
        /* «Ελεύθερο σήμερα» is a sentence, not a trip name: the whole row. */
        .kd-dep.is-free .kd-dep-name { grid-column: 1 / -1; }

        /* The boats with nothing on today: one line each, in one box. */
        .kd-idle { display: grid; padding: .75rem .9rem .35rem; border-radius: 1rem; background: var(--kd-card); border: 1px solid var(--kd-line); }
        .kd-idle-title { font-size: .875rem; font-weight: 600; color: var(--kd-muted); padding-bottom: .35rem; }
        .kd-idle-row {
            display: flex; align-items: baseline; gap: .5rem; min-height: 2.5rem; align-content: center; flex-wrap: wrap;
            padding: .55rem 0; border-top: 1px solid var(--kd-rule); color: var(--kd-ink); font-size: .95rem; font-weight: 600;
        }
        .kd-idle-row small { font-size: .8125rem; font-weight: 400; color: var(--kd-muted); }

        .kd-lanes { display: none; }

        @media (min-width: 768px) {
            .kd-boxes { grid-template-columns: repeat(4, 1fr); }
            .kd-boatboxes { display: none; }
            .kd-lanes {
                position: relative; display: grid; gap: .45rem; padding: 1rem;
                border-radius: 1rem; background: var(--kd-card); border: 1px solid var(--kd-line); overflow-x: auto;
            }
            .kd-lane-row { display: grid; grid-template-columns: 7.5rem minmax(34rem, 1fr); gap: .75rem; align-items: center; }
            .kd-lane-name { display: grid; }
            .kd-lane-name b { font-size: .925rem; color: var(--kd-ink); }
            .kd-lane-name span { font-size: .775rem; color: var(--kd-muted); }
            .kd-lane { position: relative; height: 3rem; border-radius: .6rem; background: var(--kd-lane); }
            .kd-ruler .kd-lane { height: 1rem; background: transparent; }
            .kd-tick { position: absolute; top: 0; font-size: .72rem; color: var(--kd-faint); transform: translateX(-50%); }
            .kd-ev {
                position: absolute; top: .25rem; bottom: .25rem; display: grid; align-content: center;
                padding: 0 .5rem; border-radius: .45rem; overflow: hidden; white-space: nowrap;
                background: var(--kd-bar); color: var(--kd-bar-ink); font-size: .75rem;
                container-type: inline-size;
            }
            .kd-ev-name { overflow: hidden; text-overflow: ellipsis; font-size: .8rem; }
            .kd-ev-meta { overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
            .kd-ev.is-block { background: var(--kd-block); color: #fff; }
            .kd-ev.is-cancelled { background: var(--kd-cancel); color: var(--kd-cancel-ink); }
            .kd-lane .kd-now { top: -.3rem; bottom: -.3rem; }

            /* A bar too narrow for its name («Πρωινό κ…» at 768px): the time
               and seats on one line instead, then the time alone. The name is
               in the bar's title. */
            @container (max-width: 7.5rem) {
                .kd-ev-name { display: none; }
                .kd-ev-meta { font-size: .8rem; font-weight: 700; }
            }
            @container (max-width: 4.25rem) {
                .kd-ev-pax { display: none; }
            }
            /* Too narrow even for «07:30»: a plain bar, rather than «0…». */
            @container (max-width: 2.5rem) {
                .kd-ev-meta { display: none; }
            }
        }

        @media (min-width: 1024px) {
            .kd-top { grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); align-items: stretch; }
            .kd-boxes { grid-template-columns: 1fr 1fr; }
        }
    </style>
</x-filament-widgets::widget>
