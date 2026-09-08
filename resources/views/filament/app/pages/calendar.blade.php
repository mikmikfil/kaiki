{{--
    The fleet's day, as a timeline (spec OPS-3, OPS-4).

    Every number on this page was computed in `CalendarDay`. The template
    positions things and nothing else: interval arithmetic in a Blade file is
    arithmetic nobody tests, and this is arithmetic that is wrong by an hour
    twice a year if it assumes a day is 24 hours long.

    **The drag is Alpine and the write is a Filament action.** Dragging gives a
    start and an end accurate to about ten minutes on a track this wide, so it
    opens the block form pre-filled rather than saving — an operator who meant
    09:00 should not have to drag again to get it.
--}}
<x-filament-panels::page>
    @php($day = $this->getDay())
    @php($now = $day->nowFraction())

    <div class="kaiki-calendar">
        <div class="cal-toolbar">
            <div class="cal-nav">
                <x-filament::button color="gray" size="sm" wire:click="shiftDays(-1)">
                    &larr; {{ __('calendar.previous') }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="today">
                    {{ __('calendar.today') }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="shiftDays(1)">
                    {{ __('calendar.next') }} &rarr;
                </x-filament::button>
            </div>

            <h2 class="cal-date">{{ $this->getHeading() }}</h2>

            <p class="cal-hint">{{ __('calendar.drag_hint') }}</p>
        </div>

        @if ($day->rows === [])
            <p class="cal-empty">{{ __('calendar.no_vessels') }}</p>
        @else
            <div class="cal-grid">
                {{-- The ruler. Its marks come from the day's real length, so a
                     25-hour day in October gets 25 of them. --}}
                <div class="cal-ruler">
                    <div class="cal-label"></div>
                    <div class="cal-track">
                        @foreach ($day->hours() as $mark)
                            @if ($loop->index % 3 === 0)
                                <span class="tick" style="left: {{ $mark['at'] * 100 }}%">{{ $mark['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                @foreach ($day->rows as $row)
                    <div class="cal-row">
                        <div class="cal-label">
                            <strong>{{ $row['vessel']->name }}</strong>
                            <span>{{ __('calendar.turnaround', ['minutes' => $row['vessel']->effectiveTurnaroundBufferMinutes()]) }}</span>
                        </div>

                        <div class="cal-track"
                             x-data="kaikiDrag(@js($this->date))"
                             x-on:mousedown="start($event)"
                             x-on:mousemove.window="move($event)"
                             x-on:mouseup.window="end($event, @js($row['vessel']->uuid))">
                            @foreach ($day->hours() as $mark)
                                <span class="grid-line" style="left: {{ $mark['at'] * 100 }}%"></span>
                            @endforeach

                            {{-- Where we are in the day. Absent on any day but
                                 today, because a line pinned to an edge reads as
                                 an occupation rather than as a clock. --}}
                            @if ($now !== null)
                                <span class="now-line" style="left: {{ $now * 100 }}%"></span>
                            @endif

                            @foreach ($row['bars'] as $bar)
                                @php($left = $bar['start'] * 100)
                                @php($width = max(0.4, ($bar['end'] - $bar['start']) * 100))

                                <div @class([
                                        'bar',
                                        'is-block' => $bar['kind'] === 'block',
                                        'is-external' => $bar['reason'] === 'external_ical',
                                        'is-cancelled' => $bar['cancelled'],
                                    ])
                                     style="left: {{ $left }}%; width: {{ $width }}%"
                                     @if ($bar['kind'] === 'departure')
                                         wire:click="mountAction('pax', { departure: '{{ $bar['uuid'] }}' })"
                                         role="button"
                                         tabindex="0"
                                     @endif
                                     title="{{ $bar['label'] }}{{ $bar['detail'] ? ' · ' . $bar['detail'] : '' }}">
                                    <span class="bar-label">{{ $bar['label'] }}</span>
                                    @if ($bar['pax'] !== null)
                                        <span class="bar-pax">{{ $bar['pax'] }}/{{ $bar['capacity'] }}</span>
                                    @endif
                                </div>

                                {{-- OPS-4. The turnaround, drawn as its own margin
                                     immediately after the occupation. This is the
                                     answer to "why can't I book this, the boat is
                                     free" without anybody reading documentation. --}}
                                @if ($bar['buffer'] > 0 && ! $bar['cancelled'])
                                    <div class="buffer"
                                         style="left: {{ $bar['end'] * 100 }}%; width: {{ $bar['buffer'] * 100 }}%"
                                         title="{{ __('calendar.buffer_hint') }}"></div>
                                @endif
                            @endforeach

                            <div class="drag" x-show="dragging" x-cloak
                                 :style="`left: ${leftPercent}%; width: ${widthPercent}%`"></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="cal-key">
                <span><i class="swatch is-departure"></i>{{ __('calendar.key.departure') }}</span>
                <span><i class="swatch is-block"></i>{{ __('calendar.key.block') }}</span>
                <span><i class="swatch is-external"></i>{{ __('calendar.key.external') }}</span>
                <span><i class="swatch is-buffer"></i>{{ __('calendar.key.buffer') }}</span>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />

    @script
        <script>
            // A drag on a track, snapped to a quarter of an hour. The times it
            // produces are a starting point for the form rather than the saved
            // value: a two-hundred-pixel track resolves to about ten minutes,
            // and an operator who meant 09:00 should not have to drag again.
            Alpine.data('kaikiDrag', (date) => ({
                dragging: false,
                from: 0,
                to: 0,

                get leftPercent() { return Math.min(this.from, this.to) * 100 },
                get widthPercent() { return Math.abs(this.to - this.from) * 100 },

                fractionOf(event) {
                    const box = this.$el.getBoundingClientRect()

                    return Math.max(0, Math.min(1, (event.clientX - box.left) / box.width))
                },

                toTime(fraction) {
                    // Capped at 23:45. A drag to the right-hand edge is 24:00,
                    // which as a time is 00:00 — and a block from 09:00 to 00:00
                    // is an inverted window the action refuses. Somebody
                    // blocking the whole evening means "to the end of the day".
                    const minutes = Math.min(1425, Math.round((fraction * 24 * 60) / 15) * 15)
                    const hh = String(Math.floor(minutes / 60) % 24).padStart(2, '0')
                    const mm = String(minutes % 60).padStart(2, '0')

                    return `${hh}:${mm}`
                },

                start(event) {
                    // Only on empty track. A drag that began on a bar is the
                    // operator trying to click it.
                    if (event.target.closest('.bar')) return

                    this.dragging = true
                    this.from = this.fractionOf(event)
                    this.to = this.from
                },

                move(event) {
                    if (this.dragging) this.to = this.fractionOf(event)
                },

                end(event, vessel) {
                    if (!this.dragging) return

                    this.dragging = false

                    const from = Math.min(this.from, this.to)
                    const to = Math.max(this.from, this.to)

                    // A click rather than a drag. Nothing to block.
                    if (to - from < 0.005) return

                    $wire.mountAction('block', {
                        vessel: vessel,
                        starts_at: this.toTime(from),
                        ends_at: this.toTime(to),
                    })
                },
            }))
        </script>
    @endscript

    <style>
        .kaiki-calendar { display: grid; gap: 1rem; }
        .cal-toolbar { display: flex; flex-wrap: wrap; align-items: baseline; gap: .75rem 1.25rem; }
        .cal-nav { display: flex; gap: .4rem; }
        .cal-date { font-size: 1.05rem; font-weight: 600; margin: 0; }
        .cal-hint, .cal-empty { font-size: .82rem; color: rgb(var(--gray-500)); margin: 0; }

        .cal-grid { display: grid; gap: .35rem; overflow-x: auto; }
        .cal-ruler, .cal-row { display: grid; grid-template-columns: 11rem minmax(40rem, 1fr); gap: .75rem; align-items: stretch; }
        .cal-label { display: grid; align-content: center; gap: .1rem; font-size: .85rem; min-width: 0; }
        .cal-label span { font-size: .72rem; color: rgb(var(--gray-500)); }

        .cal-track {
            position: relative; height: 2.75rem; border-radius: 8px;
            background: rgb(var(--gray-50)); border: 1px solid rgb(var(--gray-200));
            cursor: crosshair; user-select: none; overflow: hidden;
        }
        .cal-ruler .cal-track { height: 1.25rem; background: transparent; border: 0; cursor: default; }
        .cal-ruler .tick { position: absolute; top: 0; transform: translateX(-50%); font-size: .68rem; color: rgb(var(--gray-400)); }

        .grid-line { position: absolute; top: 0; bottom: 0; width: 1px; background: rgb(var(--gray-200)); }

        .bar {
            position: absolute; top: .3rem; bottom: .3rem; border-radius: 6px;
            padding: 0 .45rem; display: flex; align-items: center; gap: .4rem;
            font-size: .74rem; color: #fff; overflow: hidden; white-space: nowrap;
            background: rgb(var(--primary-600)); cursor: pointer;
        }
        .bar-label { overflow: hidden; text-overflow: ellipsis; }
        .bar-pax { margin-left: auto; opacity: .85; font-variant-numeric: tabular-nums; }

        /* A block is a different thing from a sailing and looks like it. */
        .bar.is-block { background: rgb(var(--gray-600)); }
        /* And one an external calendar pushed in looks different again — an
           operator who deletes one because they do not recognise it will have it
           back in fifteen minutes and will file a bug. */
        .bar.is-external {
            background: repeating-linear-gradient(45deg,
                rgb(var(--gray-500)), rgb(var(--gray-500)) 6px,
                rgb(var(--gray-400)) 6px, rgb(var(--gray-400)) 12px);
        }
        .bar.is-cancelled { background: rgb(var(--gray-300)); color: rgb(var(--gray-600)); text-decoration: line-through; cursor: default; }

        .buffer {
            position: absolute; top: .3rem; bottom: .3rem;
            background: repeating-linear-gradient(90deg,
                rgb(var(--warning-300)), rgb(var(--warning-300)) 3px,
                transparent 3px, transparent 6px);
            border-radius: 0 6px 6px 0;
        }

        /* Now. One pixel and a small cap, in the warning hue: findable at a
           glance, and never competing with the bars it is read against. */
        .now-line { position: absolute; top: -.2rem; bottom: -.2rem; width: 1px; background: rgb(var(--warning-500)); opacity: .85; z-index: 2; pointer-events: none; }
        .now-line::before {
            content: ''; position: absolute; top: -.15rem; left: 50%;
            width: .35rem; height: .35rem; margin-left: -.175rem;
            border-radius: 50%; background: rgb(var(--warning-500));
        }

        .drag { position: absolute; top: .3rem; bottom: .3rem; background: rgb(var(--primary-400)); opacity: .45; border-radius: 6px; pointer-events: none; }

        .cal-key { display: flex; flex-wrap: wrap; gap: 1rem; font-size: .76rem; color: rgb(var(--gray-500)); }
        .cal-key span { display: inline-flex; align-items: center; gap: .35rem; }
        .swatch { width: .9rem; height: .9rem; border-radius: 3px; display: inline-block; }
        .swatch.is-departure { background: rgb(var(--primary-600)); }
        .swatch.is-block { background: rgb(var(--gray-600)); }
        .swatch.is-external { background: repeating-linear-gradient(45deg, rgb(var(--gray-500)), rgb(var(--gray-500)) 4px, rgb(var(--gray-400)) 4px, rgb(var(--gray-400)) 8px); }
        .swatch.is-buffer { background: repeating-linear-gradient(90deg, rgb(var(--warning-300)), rgb(var(--warning-300)) 3px, transparent 3px, transparent 6px); }

        /* OOS-6. One boat at a time on a phone is an acceptable answer; a grid
           nobody can read is not. The track keeps its minimum width and the
           whole grid scrolls sideways rather than squashing. */
        @media (max-width: 48rem) {
            .cal-ruler, .cal-row { grid-template-columns: 7rem minmax(32rem, 1fr); }
        }
    </style>
</x-filament-panels::page>
