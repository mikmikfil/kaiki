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
    $boarding = $this->getBoarding();
    $boxes = $this->getBoxes();
    $day = $this->getDay();
    $now = $day->nowFraction();
    $calendarUrl = $this->getCalendarUrl();
@endphp

<x-filament-widgets::widget>
    <div class="kd">
        <div class="kd-top">
            {{-- 1. The next departure --}}
            <div class="kd-next">
                @if ($next)
                    <div class="kd-next-when">{{ __('dashboard.home.next.label') }} · {{ $next['when'] }}</div>

                    <div class="kd-next-row">
                        <div>
                            <div class="kd-next-time">{{ $next['time'] }}</div>
                            @if ($next['url'])
                                <a class="kd-next-trip" href="{{ $next['url'] }}">{{ $next['trip'] }}</a>
                            @else
                                <div class="kd-next-trip">{{ $next['trip'] }}</div>
                            @endif
                            <div class="kd-next-where">{{ $next['where'] }}</div>
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

                @if ($boarding)
                    <a class="kd-bigbtn" href="{{ $boarding['url'] }}">
                        <x-filament::icon :icon="$boarding['icon']" class="kd-ic" />
                        <span>{{ $boarding['label'] }}</span>
                    </a>
                @endif
            </div>

            {{-- 2. Four boxes, one question each --}}
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
                {{-- A box per boat: the phone --}}
                <div class="kd-boatboxes">
                    @foreach ($day->rows as $row)
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
                                    <div class="kd-dep is-muted"><span class="kd-dep-name">{{ __('dashboard.home.boats.free') }}</span></div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
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
                                    <{{ $url ? 'a' : 'div' }} @if ($url) href="{{ $url }}" @endif
                                        @class(['kd-ev', 'is-block' => $bar['kind'] === 'block', 'is-cancelled' => $bar['cancelled']])
                                        style="left: {{ $bar['start'] * 100 }}%; width: calc({{ max(($bar['end'] - $bar['start']) * 100, 3) }}% - 3px)"
                                        title="{{ $bar['label'] }}">
                                        <b>{{ $bar['label'] }}</b>
                                        <span>{{ $bar['detail'] ? substr((string) $bar['detail'], 0, 5) : '' }}{{ $bar['pax'] !== null ? ' · ' . $bar['pax'] . '/' . $bar['capacity'] : '' }}</span>
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
        .kd { display: grid; gap: 1.25rem; }
        .kd a { text-decoration: none; }

        .kd-top { display: grid; gap: .875rem; }

        .kd-next {
            display: grid; gap: .5rem; padding: 1.1rem 1.15rem 1.15rem;
            border-radius: 1rem; background: #0F2E57; color: #fff;
        }
        .kd-next-when { font-size: .8125rem; font-weight: 600; color: #9FBBE0; }
        .kd-next-row { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; }
        .kd-next-time { font-size: 2.25rem; line-height: 1; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
        .kd-next-trip { display: block; margin-top: .3rem; font-size: 1.0625rem; font-weight: 700; color: #fff; }
        .kd-next-where { font-size: .8125rem; color: #C4D3E8; }
        .kd-next-aboard { text-align: right; flex: none; }
        .kd-next-count { font-size: 1.35rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .kd-progress { height: .4rem; border-radius: 99px; background: #23497D; overflow: hidden; }
        .kd-progress i { display: block; height: 100%; border-radius: inherit; background: #7FB0EE; }

        .kd-bigbtn {
            display: flex; align-items: center; justify-content: center; gap: .55rem;
            min-height: 3.125rem; margin-top: .35rem; border-radius: .75rem;
            background: #fff; color: #0F2E57; font-weight: 700; font-size: 1rem;
        }
        .kd-bigbtn:hover { background: #E8F0FB; }
        .kd-ic { width: 1.25rem; height: 1.25rem; }

        .kd-boxes { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .kd-box {
            position: relative; display: flex; flex-direction: column; gap: .6rem;
            min-height: 6.5rem; padding: .9rem; border-radius: 1rem;
            background: #fff; border: 1px solid #E1E8F2; color: #15233A;
        }
        .kd-box:hover { border-color: #B9CBE3; }
        .kd-box-ic {
            display: grid; place-items: center; width: 2.25rem; height: 2.25rem;
            border-radius: .6rem; background: #EAF1FA; color: #0F2E57;
        }
        .kd-box-ic.is-alert { background: #FDECEC; color: #B42318; }
        .kd-box-text { display: grid; gap: .1rem; }
        .kd-box-text b { font-size: .975rem; }
        .kd-box-text small { font-size: .8125rem; color: #5B6B82; }
        .kd-count {
            position: absolute; top: .7rem; right: .7rem; min-width: 1.4rem; padding: 0 .4rem;
            border-radius: 99px; background: #EAF1FA; color: #0F2E57;
            font-size: .75rem; font-weight: 700; line-height: 1.4rem; text-align: center;
        }
        .kd-count.is-alert { background: #FDECEC; color: #B42318; }

        .kd-boats { display: grid; gap: .75rem; }
        .kd-section-title { display: flex; align-items: baseline; justify-content: space-between; }
        .kd-section-title h3 { font-size: 1.0625rem; font-weight: 700; color: #15233A; }
        .kd-section-title a { font-size: .875rem; font-weight: 600; color: #1C4378; }
        .kd-empty { font-size: .9rem; color: #5B6B82; }

        .kd-boatboxes { display: grid; gap: .75rem; }
        .kd-boat { display: grid; gap: .6rem; padding: .9rem; border-radius: 1rem; background: #fff; border: 1px solid #E1E8F2; }
        .kd-boat-head { display: flex; align-items: baseline; gap: .5rem; }
        .kd-boat-head b { font-size: 1rem; color: #15233A; }
        .kd-boat-head span { font-size: .8125rem; color: #5B6B82; }
        .kd-mini { position: relative; height: .6rem; border-radius: 99px; background: #EEF3F9; }
        .kd-mini i { position: absolute; top: 0; bottom: 0; border-radius: 99px; background: #1C4378; }
        .kd-mini i.is-block { background: #9AA8BA; }
        .kd-mini i.is-cancelled { background: #CBD5E1; }
        .kd-now { position: absolute; top: -.2rem; bottom: -.2rem; width: 2px; margin-left: -1px; background: #D97706; z-index: 1; }
        .kd-deps { display: grid; }
        .kd-dep {
            display: grid; grid-template-columns: 3.2rem 1fr auto; align-items: center; gap: .5rem;
            min-height: 2.75rem; border-top: 1px solid #EEF3F9; color: #15233A; font-size: .925rem;
        }
        .kd-dep-time { font-variant-numeric: tabular-nums; font-weight: 700; }
        .kd-dep-pax { font-variant-numeric: tabular-nums; color: #5B6B82; font-size: .85rem; }
        .kd-dep.is-muted { color: #7B8BA1; }

        .kd-lanes { display: none; }

        @media (min-width: 768px) {
            .kd-boxes { grid-template-columns: repeat(4, 1fr); }
            .kd-boatboxes { display: none; }
            .kd-lanes {
                position: relative; display: grid; gap: .45rem; padding: 1rem;
                border-radius: 1rem; background: #fff; border: 1px solid #E1E8F2; overflow-x: auto;
            }
            .kd-lane-row { display: grid; grid-template-columns: 7.5rem minmax(34rem, 1fr); gap: .75rem; align-items: center; }
            .kd-lane-name { display: grid; }
            .kd-lane-name b { font-size: .925rem; color: #15233A; }
            .kd-lane-name span { font-size: .775rem; color: #5B6B82; }
            .kd-lane { position: relative; height: 3rem; border-radius: .6rem; background: #F3F6FA; }
            .kd-ruler .kd-lane { height: 1rem; background: transparent; }
            .kd-tick { position: absolute; top: 0; font-size: .72rem; color: #7B8BA1; transform: translateX(-50%); }
            .kd-ev {
                position: absolute; top: .25rem; bottom: .25rem; display: grid; align-content: center;
                padding: 0 .5rem; border-radius: .45rem; overflow: hidden; white-space: nowrap;
                background: #1C4378; color: #fff; font-size: .75rem;
            }
            .kd-ev b { overflow: hidden; text-overflow: ellipsis; font-size: .8rem; }
            .kd-ev span { opacity: .85; font-variant-numeric: tabular-nums; }
            .kd-ev.is-block { background: #9AA8BA; }
            .kd-ev.is-cancelled { background: #CBD5E1; color: #475569; }
            .kd-lane .kd-now { top: -.3rem; bottom: -.3rem; }
        }

        @media (min-width: 1024px) {
            .kd-top { grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); align-items: stretch; }
            .kd-boxes { grid-template-columns: 1fr 1fr; }
        }
    </style>
</x-filament-widgets::widget>
