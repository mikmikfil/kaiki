{{--
    Today's fleet, on the dashboard (#130, OPS-1, OPS-3, OPS-4).

    **Every number here was computed in `CalendarDay`** — the same class the full
    calendar page uses. Nothing in this file positions a bar by its own
    arithmetic, because that arithmetic is wrong by an hour twice a year if it
    assumes a day is 24 hours long, and a dashboard that disagreed with the
    calendar page by one bar-width would be impossible to explain.

    What is *not* shared is the CSS: this strip is denser, has no drag, no
    toolbar and no legend, and forcing one stylesheet to serve both would make
    each of them worse. Presentation drift is cosmetic; arithmetic drift is a
    double booking, and that is the one this file refuses to risk.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('attention.today.heading') }}</x-slot>

        <x-slot name="description">{{ $this->getHeadingDate() }}</x-slot>

        <x-slot name="headerEnd">
            <x-filament::link :href="$this->getCalendarUrl()" size="sm">
                {{ __('attention.today.open_calendar') }}
            </x-filament::link>
        </x-slot>

        @php($day = $this->getDay())

        @if ($day->rows === [])
            <p class="kt-empty">{{ __('attention.today.no_vessels') }}</p>
        @elseif ($day->isEmpty())
            {{-- A fleet with nothing on it today is an answer, and a good one.
                 Drawing three empty tracks says the same thing in more space. --}}
            <p class="kt-empty">{{ __('attention.today.nothing_out') }}</p>
        @else
            <div class="kaiki-today">
                <div class="kt-ruler">
                    <div class="kt-label"></div>
                    <div class="kt-track">
                        @foreach ($day->hours() as $mark)
                            @if ($loop->index % 4 === 0)
                                <span class="kt-tick" style="left: {{ $mark['at'] * 100 }}%">{{ $mark['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                @foreach ($day->rows as $row)
                    <div class="kt-row">
                        <div class="kt-label" title="{{ $row['vessel']->name }}">{{ $row['vessel']->name }}</div>

                        <div class="kt-track">
                            @foreach ($day->hours() as $mark)
                                <span class="kt-line" style="left: {{ $mark['at'] * 100 }}%"></span>
                            @endforeach

                            @foreach ($row['bars'] as $bar)
                                <div
                                    @class([
                                        'kt-bar',
                                        'is-block' => $bar['kind'] === 'block',
                                        'is-external' => $bar['reason'] === 'external_ical',
                                        'is-cancelled' => $bar['cancelled'],
                                    ])
                                    style="left: {{ $bar['start'] * 100 }}%; width: {{ max(($bar['end'] - $bar['start']) * 100, 1.5) }}%"
                                    title="{{ $bar['label'] }}{{ $bar['detail'] ? ' · ' . $bar['detail'] : '' }}"
                                >
                                    <span class="kt-bar-label">{{ $bar['label'] }}</span>
                                    @if ($bar['pax'] !== null)
                                        <span class="kt-bar-pax">{{ $bar['pax'] }}/{{ $bar['capacity'] }}</span>
                                    @endif
                                </div>

                                {{-- OPS-4. The turnaround is drawn and never stored,
                                     so lowering it shortens every margin at once. --}}
                                @if ($bar['buffer'] > 0 && ! $bar['cancelled'])
                                    <div class="kt-buffer"
                                         style="left: {{ $bar['end'] * 100 }}%; width: {{ $bar['buffer'] * 100 }}%"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <style>
        .kt-empty { font-size: .875rem; color: rgb(var(--gray-500)); }

        .kaiki-today { display: grid; gap: .3rem; overflow-x: auto; }

        .kt-ruler,
        .kt-row { display: grid; grid-template-columns: 8rem minmax(24rem, 1fr); align-items: center; gap: .5rem; }

        .kt-label {
            font-size: .8rem; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            color: rgb(var(--gray-700));
        }

        .kt-track { position: relative; height: 1.9rem; border-radius: 6px; background: rgb(var(--gray-100)); }

        .kt-tick { position: absolute; top: -.1rem; font-size: .68rem; color: rgb(var(--gray-400)); transform: translateX(-50%); }
        .kt-ruler .kt-track { background: transparent; height: 1rem; }

        .kt-line { position: absolute; top: 0; bottom: 0; width: 1px; background: rgb(var(--gray-200)); }

        .kt-bar {
            position: absolute; top: .22rem; bottom: .22rem;
            display: flex; align-items: center; gap: .35rem;
            padding: 0 .4rem; border-radius: 5px; overflow: hidden;
            background: rgb(var(--primary-600)); color: white;
            font-size: .7rem; white-space: nowrap;
        }

        .kt-bar-label { overflow: hidden; text-overflow: ellipsis; }
        .kt-bar-pax { margin-left: auto; opacity: .85; font-variant-numeric: tabular-nums; }

        .kt-bar.is-block { background: rgb(var(--gray-600)); }

        /* An external block looks different again, so an operator does not
           delete one they do not recognise — it would be back in fifteen
           minutes and they would file a bug. */
        .kt-bar.is-external {
            background: repeating-linear-gradient(45deg,
                rgb(var(--gray-500)), rgb(var(--gray-500)) 5px,
                rgb(var(--gray-400)) 5px, rgb(var(--gray-400)) 10px);
        }

        .kt-bar.is-cancelled { background: rgb(var(--gray-300)); color: rgb(var(--gray-600)); text-decoration: line-through; }

        .kt-buffer {
            position: absolute; top: .22rem; bottom: .22rem;
            background: repeating-linear-gradient(90deg,
                rgb(var(--warning-300)), rgb(var(--warning-300)) 3px,
                transparent 3px, transparent 6px);
            border-radius: 0 5px 5px 0;
        }

        /* OOS-6: the strip scrolls sideways on a phone rather than squashing
           into something nobody can read. */
        @media (max-width: 48rem) {
            .kt-ruler, .kt-row { grid-template-columns: 5.5rem minmax(20rem, 1fr); }
        }
    </style>
</x-filament-widgets::widget>
