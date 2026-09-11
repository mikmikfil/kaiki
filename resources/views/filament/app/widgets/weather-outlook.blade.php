{{--
    «Καιρός» (#131, ADR-0027, OPS-6).

    Four days of wind per boat, with the ones over that boat's limit marked —
    and, beside them, what is booked on those days. The join is the feature: an
    operator already has the forecast on their phone and does not have
    "Thursday and Friday are over Nefeli's limit, three departures, 27 people".

    Nothing here decides anything. The button goes to the calendar, and the
    cancellation itself still runs through #121's preview where each guest's
    entitlement is computed from their own policy snapshot.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('weather.heading') }}</x-slot>

        <x-slot name="description">{{ __('weather.subheading') }}</x-slot>

        <div class="kw-list">
            @foreach ($this->getRows() as $row)
                <div class="kw-row">
                    <div class="kw-vessel">
                        <p class="kw-name">{{ $row['vessel']->name }}</p>
                        <p class="kw-limit">{{ __('weather.limit', ['bft' => $row['vessel']->max_wind_bft]) }}</p>
                    </div>

                    <div class="kw-days">
                        @foreach ($row['days'] as $day)
                            @php($over = $day->exceeds($row['vessel']->max_wind_bft))

                            <div @class(['kw-day', 'is-over' => $over])
                                 title="{{ $day->drivenByGust() ? __('weather.by_gust') : __('weather.by_wind') }}">
                                <span class="kw-dow">{{ \Illuminate\Support\Carbon::parse($day->date)->translatedFormat('D') }}</span>
                                <b class="kw-bft">{{ $day->force() }}</b>
                                {{-- Which number decided it. An operator told "7"
                                     wants to know whether that is the wind or a
                                     gust before they cancel a charter. --}}
                                <span class="kw-src">{{ $day->drivenByGust() ? __('weather.gust_short') : __('weather.wind_short') }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="kw-impact">
                        @if ($row['departures'] > 0)
                            <p class="kw-affected">
                                {{ __('weather.affected', [
                                    'departures' => $row['departures'],
                                    'passengers' => $row['passengers'],
                                ]) }}
                            </p>
                        @else
                            {{-- Rough, and nothing booked. Worth saying rather
                                 than leaving blank: it is the difference between
                                 a decision and a note. --}}
                            <p class="kw-clear">{{ __('weather.nothing_booked') }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <x-slot name="footer">
            <x-filament::link :href="$this->getCalendarUrl()" size="sm">
                {{ __('weather.open_calendar') }}
            </x-filament::link>
        </x-slot>
        {{-- Where the numbers came from.

             The name is asked of the provider rather than written here, so it
             cannot go on crediting Open-Meteo after the binding is swapped for
             a paid endpoint — and for Open-Meteo it is a condition of the
             CC BY 4.0 licence rather than a courtesy. --}}
        <p class="kw-source">
            <a href="{{ $this->getSource()->url }}" target="_blank" rel="noopener noreferrer">
                {{ __('weather.source', ['name' => $this->getSource()->name]) }}
            </a>
        </p>
    </x-filament::section>

    <style>
        .kw-list { display: grid; gap: .1rem; }

        .kw-row {
            display: grid; grid-template-columns: 10rem auto minmax(0, 1fr);
            align-items: center; gap: 1rem;
            padding: .65rem 0;
            border-bottom: 1px solid rgb(var(--gray-100));
        }

        .kw-row:last-child { border-bottom: 0; }

        .kw-name { font-size: .875rem; font-weight: 600; color: rgb(var(--gray-800)); }
        .kw-limit { font-size: .75rem; color: rgb(var(--gray-500)); }

        .kw-days { display: flex; gap: .4rem; }

        .kw-day {
            min-width: 3.1rem; padding: .3rem .35rem;
            border-radius: 8px; text-align: center;
            background: rgb(var(--gray-50));
            border: 1px solid rgb(var(--gray-200));
        }

        /* Over the limit. The warning hue rather than danger: this is a
           forecast, and a red block for something that has not happened yet
           reads as an incident. */
        .kw-day.is-over {
            background: rgb(var(--warning-50));
            border-color: rgb(var(--warning-300));
        }

        .kw-dow { display: block; font-size: .66rem; color: rgb(var(--gray-500)); }
        .kw-bft { display: block; font-size: 1rem; line-height: 1.2; font-variant-numeric: tabular-nums; }
        .kw-day.is-over .kw-bft { color: rgb(var(--warning-700)); }
        .kw-src { display: block; font-size: .6rem; color: rgb(var(--gray-400)); }

        /* Quiet on purpose: a credit line, not a row of the panel. */
        .kw-source { margin-top: .9rem; font-size: .68rem; color: rgb(var(--gray-400)); }
        .kw-source a { color: inherit; font-weight: 600; text-decoration: none; }

        .kw-affected { font-size: .8rem; color: rgb(var(--gray-700)); }
        .kw-clear { font-size: .8rem; color: rgb(var(--gray-400)); }

        @media (max-width: 52rem) {
            .kw-row { grid-template-columns: 1fr; gap: .5rem; }
            .kw-days { flex-wrap: wrap; }
        }
    </style>
</x-filament-widgets::widget>
