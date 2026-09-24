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
            {{-- Below `sm` the arrows are icons with their names read out: as
                 words, «← Προηγούμενη μέρα» wrapped onto two lines on a phone.
                 The date is the page heading, so it is not said twice. --}}
            <div class="cal-nav">
                <x-filament::button color="gray" size="sm" wire:click="shiftDays(-1)" :aria-label="__('calendar.previous')" class="cal-arrow">
                    <span aria-hidden="true" class="sm:hidden">&lsaquo;</span>
                    <span class="hidden sm:inline">&larr; {{ __('calendar.previous') }}</span>
                </x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="today">
                    {{ __('calendar.today') }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="shiftDays(1)" :aria-label="__('calendar.next')" class="cal-arrow">
                    <span aria-hidden="true" class="sm:hidden">&rsaquo;</span>
                    <span class="hidden sm:inline">{{ __('calendar.next') }} &rarr;</span>
                </x-filament::button>
            </div>

            <p class="cal-hint cal-hint-track">{{ __('calendar.drag_hint') }}</p>
            <p class="cal-hint cal-hint-list">{{ __('calendar.list_hint') }}</p>
        </div>

        @if ($day->rows === [])
            <p class="cal-empty">{{ __('calendar.no_vessels') }}</p>
        @else
            {{--
                The phone: a list per boat (Mike, 2026-09-23). Twenty-four hours
                in the width of a phone was a strip of grey with the trips cut to
                three letters at its right-hand edge; what a crew member wants to
                know is which boat leaves when, how full, and whether it is on.
                Each departure opens the same passenger list its bar opens. The
                timeline and the drag-to-block are from a tablet up.
            --}}
            @php($statuses = $this->departureStatuses($day))
            @php($opens = $this->canOpenPax())
            @php($canBlock = $this->blockAction->isVisible())
            @php($canAssign = \App\Filament\App\Pages\Calendar::canAssign())
            <div class="cal-list">
                @foreach ($day->rows as $row)
                    <section @class(['cal-boat', 'is-idle' => $row['bars'] === []])>
                        <header class="cal-boat-head">
                            <b>{{ $row['vessel']->name }}</b>
                            @if ($row['bars'] === [])
                                <span class="cal-boat-free">{{ __('calendar.free') }}</span>
                            @endif
                            {{-- The phone has no track to drag on, so each boat
                                 carries the button instead (Mike, 2026-09-24). --}}
                            @if ($canBlock)
                                <button type="button" class="cal-boat-block"
                                    wire:click="mountAction('block', { vessel: '{{ $row['vessel']->uuid }}' })">
                                    {{ __('calendar.block.title') }}
                                </button>
                            @endif
                        </header>

                        @foreach ($row['bars'] as $bar)
                            @php($status = $bar['kind'] === 'departure' ? ($statuses[$bar['uuid']] ?? null) : null)
                            @php($tappable = $bar['kind'] === 'departure' && $opens)
                            <{{ $tappable ? 'button' : 'div' }}
                                @if ($tappable)
                                    type="button"
                                    wire:click="mountAction('pax', { departure: '{{ $bar['uuid'] }}' })"
                                @endif
                                @class(['cal-dep', 'is-block' => $bar['kind'] === 'block', 'is-cancelled' => $bar['cancelled']])>
                                <span class="cal-dep-time">{{ $bar['detail'] ? substr((string) $bar['detail'], 0, 5) : __('calendar.all_day') }}</span>
                                <span class="cal-dep-main">
                                    <span class="cal-dep-name">{{ $bar['label'] }}</span>
                                    <span @class([
                                        'cal-dep-status',
                                        'is-good' => $status === \App\Enums\DepartureStatus::Guaranteed,
                                        'is-off' => $status === \App\Enums\DepartureStatus::Cancelled,
                                    ])>
                                        @if ($status !== null)
                                            {{ $status->label() }}
                                            @if (($bar['captain'] ?? null) !== null)
                                                · {{ $bar['captain'] }}
                                            @elseif ($bar['kind'] === 'departure' && ! $bar['cancelled'])
                                                · <span class="cal-no-captain">{{ __('availability.departure.table.no_captain') }}</span>
                                            @endif
                                        @elseif ($bar['reason'] === 'external_ical')
                                            {{ __('calendar.key.external') }}
                                        @else
                                            {{ __('calendar.key.block') }}
                                        @endif
                                    </span>
                                </span>
                                @if ($bar['pax'] !== null)
                                    <span class="cal-dep-pax">{{ $bar['pax'] }}/{{ $bar['capacity'] }}</span>
                                @endif
                            </{{ $tappable ? 'button' : 'div' }}>
                            {{-- Captain and crew in two taps (owner, manager). --}}
                            @if ($canAssign && $bar['kind'] === 'departure' && ! $bar['cancelled'])
                                <button type="button" class="cal-assign"
                                    wire:click="mountAction('assign', { departure: '{{ $bar['uuid'] }}' })">
                                    {{ __('calendar.assign.title') }}
                                </button>
                            @endif
                        @endforeach
                    </section>
                @endforeach
            </div>

            <div class="cal-timeline">
            <div class="cal-grid">
                {{-- The ruler. Its marks come from the day's real length, so a
                     25-hour day in October gets 25 of them. --}}
                <div class="cal-ruler">
                    <div class="cal-label"></div>
                    <div class="cal-track">
                        @foreach ($day->hours() as $mark)
                            @if ($loop->index % 3 === 0)
                                <span @class(['tick', 'is-first' => $loop->first, 'is-last' => $loop->last]) style="left: {{ $mark['at'] * 100 }}%">{{ $mark['label'] }}</span>
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
                             x-on:pointerdown="start($event)"
                             x-on:pointermove.window="move($event)"
                             x-on:pointerup.window="end($event, @js($row['vessel']->uuid))">
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
                                     title="{{ $bar['label'] }}{{ $bar['detail'] ? ' · ' . $bar['detail'] : '' }}{{ ($bar['captain'] ?? null) !== null ? ' · ' . __('availability.departure.crew.captain.label') . ': ' . $bar['captain'] : '' }}">
                                    {{-- Two lines: the name gets the bar's whole width,
                                         and time and seats go underneath. On one line
                                         beside the count a two-hour trip on a tablet
                                         was three letters and an ellipsis. --}}
                                    <span class="bar-label">{{ $bar['label'] }}</span>
                                    <span class="bar-pax">{{ $bar['detail'] ? substr((string) $bar['detail'], 0, 5) : '' }}@if ($bar['pax'] !== null){{ $bar['detail'] ? ' · ' : '' }}{{ $bar['pax'] }}/{{ $bar['capacity'] }}@endif @if (($bar['captain'] ?? null) !== null)· {{ $bar['captain'] }}@endif</span>
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
        .kaiki-calendar {
            /* The list's colours, named once and redefined under `.dark` below. */
            --cal-card: #fff;
            --cal-line: rgb(var(--gray-200));
            --cal-rule: rgb(var(--gray-100));
            --cal-ink: rgb(var(--gray-950));
            --cal-muted: rgb(var(--gray-500));
            --cal-hover: rgb(var(--gray-50));
            --cal-good: rgb(var(--success-700));
            --cal-off: rgb(var(--danger-700));
            display: grid; gap: 1rem;
        }
        .cal-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1.25rem; }
        .cal-nav { display: flex; gap: .4rem; }
        .cal-hint, .cal-empty { font-size: .82rem; color: rgb(var(--gray-500)); margin: 0; }

        /* --- the phone: a list per boat -------------------------------------- */
        /* `min-width: 0`: a grid item is as wide as its widest line by default,
           and a boat name next to its button pushed every card 23px past the
           right-hand edge of a phone. */
        .cal-list { display: grid; gap: .75rem; min-width: 0; }
        .cal-boat { min-width: 0; }
        .cal-boat {
            display: grid; padding: .35rem .9rem; border-radius: 1rem;
            background: var(--cal-card); border: 1px solid var(--cal-line); color: var(--cal-ink);
        }
        .cal-boat-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; min-height: 2.5rem; padding-top: .55rem; }
        .cal-boat-head b { font-size: 1rem; }
        .cal-boat.is-idle .cal-boat-head { padding-bottom: .55rem; }
        .cal-boat-free { font-size: .85rem; color: var(--cal-muted); margin-left: auto; }
        .cal-boat-block {
            min-height: 2.25rem; padding: 0 .75rem; border-radius: .6rem; white-space: nowrap;
            font-size: .8125rem; font-weight: 600; color: rgb(var(--primary-600));
            border: 1px solid var(--cal-line); background: transparent;
        }
        .cal-boat-block:hover { background: var(--cal-hover); }
        .dark .cal-boat-block { color: rgb(var(--primary-400)); }
        .cal-assign {
            display: block; margin: -.2rem 0 .4rem 4rem; min-height: 2rem; padding: 0 .7rem; border-radius: .55rem;
            font-size: .8125rem; font-weight: 600; color: rgb(var(--primary-600));
            border: 1px solid var(--cal-line); background: transparent;
        }
        .cal-assign:hover { background: var(--cal-hover); }
        .dark .cal-assign { color: rgb(var(--primary-400)); }
        .cal-dep {
            display: grid; grid-template-columns: 3.4rem minmax(0, 1fr) auto; align-items: center; gap: .6rem;
            width: 100%; min-height: 3.25rem; padding: .45rem 0; text-align: left;
            border-top: 1px solid var(--cal-rule); color: inherit; font-size: .925rem;
        }
        button.cal-dep { cursor: pointer; border-radius: .5rem; }
        button.cal-dep:hover { background: var(--cal-hover); }
        button.cal-dep:focus-visible { outline: 2px solid rgb(var(--primary-500)); outline-offset: 2px; }
        .cal-dep-time { font-weight: 700; font-variant-numeric: tabular-nums; }
        .cal-dep-main { display: grid; min-width: 0; }
        .cal-dep-name { font-weight: 600; overflow-wrap: anywhere; }
        .cal-dep-status { font-size: .8125rem; color: var(--cal-muted); }
        .cal-dep-status.is-good { color: var(--cal-good); }
        .cal-dep-status.is-off { color: var(--cal-off); }
        .cal-no-captain { color: rgb(var(--danger-600)); font-weight: 600; }
        .cal-dep-pax { font-variant-numeric: tabular-nums; font-size: .875rem; color: var(--cal-muted); }
        .cal-dep.is-cancelled .cal-dep-name { text-decoration: line-through; color: var(--cal-muted); }
        .cal-dep.is-block .cal-dep-name { color: var(--cal-muted); }

        .cal-timeline { display: grid; gap: 1rem; }

        @media (max-width: 767.98px) {
            .cal-timeline, .cal-hint-track { display: none; }
            /* 44px for a thumb; the arrows square. */
            .cal-nav .fi-btn { min-height: 2.75rem; }
            .cal-nav .cal-arrow { min-width: 2.75rem; }
            .cal-nav .cal-arrow [aria-hidden="true"] { font-size: 1.5rem; line-height: 1; }
        }
        @media (min-width: 768px) {
            .cal-list, .cal-hint-list { display: none; }
        }

        .cal-grid { display: grid; gap: .35rem; overflow-x: auto; }
        .cal-ruler, .cal-row { display: grid; grid-template-columns: 11rem minmax(40rem, 1fr); gap: .75rem; align-items: stretch; }
        .cal-label { display: grid; align-content: center; gap: .1rem; font-size: .85rem; min-width: 0; }
        .cal-label span { font-size: .72rem; color: rgb(var(--gray-500)); }

        .cal-track {
            position: relative; height: 2.75rem; border-radius: 8px;
            background: rgb(var(--gray-50)); border: 1px solid rgb(var(--gray-200));
            cursor: crosshair; user-select: none; overflow: hidden;
        }
        .cal-ruler .cal-track { height: 1.25rem; background: transparent; border: 0; cursor: default; overflow: visible; }
        .cal-ruler .tick { position: absolute; top: 0; transform: translateX(-50%); font-size: .68rem; color: rgb(var(--gray-500)); white-space: nowrap; }
        /* The first and last marks sit inside the track rather than half off it:
           centred on the edge, «00:00» was cut to «:00» and «00:». */
        .cal-ruler .tick.is-first { transform: none; }
        .cal-ruler .tick.is-last { transform: translateX(-100%); }
        /* A touch on a track is a drag on a tablet; scrolling up and down stays the page's. */
        .cal-row .cal-track { touch-action: pan-y; }

        .grid-line { position: absolute; top: 0; bottom: 0; width: 1px; background: rgb(var(--gray-200)); }

        .bar {
            position: absolute; top: .3rem; bottom: .3rem; border-radius: 6px;
            padding: 0 .45rem; display: grid; align-content: center;
            font-size: .74rem; line-height: 1.15; color: #fff; overflow: hidden; white-space: nowrap;
            background: rgb(var(--primary-600)); cursor: pointer;
        }
        .bar-label { overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
        .bar-pax { overflow: hidden; text-overflow: ellipsis; opacity: .9; font-size: .68rem; font-variant-numeric: tabular-nums; }

        /* A block is a different thing from a sailing and looks like it. */
        .bar.is-block { background: rgb(var(--gray-600)); }
        /* And one an external calendar pushed in looks different again — an
           operator who deletes one because they do not recognise it will have it
           back in fifteen minutes and will file a bug. */
        /* Pale stripes and dark type: white on mid-grey stripes read at 2.5:1. */
        .bar.is-external {
            background: repeating-linear-gradient(45deg,
                rgb(var(--gray-300)), rgb(var(--gray-300)) 6px,
                rgb(var(--gray-200)) 6px, rgb(var(--gray-200)) 12px);
            color: rgb(var(--gray-900));
        }
        .bar.is-cancelled { background: rgb(var(--gray-300)); color: rgb(var(--gray-700)); text-decoration: line-through; cursor: default; }

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
        .swatch.is-external { background: repeating-linear-gradient(45deg, rgb(var(--gray-400)), rgb(var(--gray-400)) 4px, rgb(var(--gray-200)) 4px, rgb(var(--gray-200)) 8px); }
        .swatch.is-buffer { background: repeating-linear-gradient(90deg, rgb(var(--warning-300)), rgb(var(--warning-300)) 3px, transparent 3px, transparent 6px); }

        /* A tablet, and a small laptop with the menu open beside the page: a
           narrower name column, so the day fits the width without scrolling
           sideways (the phone has its list instead). */
        @media (max-width: 1279.98px) {
            .cal-ruler, .cal-row { grid-template-columns: 7.5rem minmax(28rem, 1fr); gap: .6rem; }
        }

        /* --- dark ------------------------------------------------------------
           Filament's palette variables do **not** change between the themes:
           `--gray-50` is the same near-white in both, and the `dark:` utilities
           in its own markup work by naming a *different shade*, not by the shade
           meaning something different. Everything above therefore painted a
           light calendar onto a dark page — a white track with white grid lines,
           which is what an operator reported.

           So these rules name the other end of the same ramp. They are not a
           second design: every hue, every weight and every relationship is the
           one above, read against a dark ground instead of a light one. */
        .dark .kaiki-calendar {
            --cal-card: rgb(var(--gray-900));
            --cal-line: rgba(255, 255, 255, .1);
            --cal-rule: rgba(255, 255, 255, .06);
            --cal-ink: #fff;
            --cal-muted: rgb(var(--gray-400));
            --cal-hover: rgba(255, 255, 255, .04);
            --cal-good: rgb(var(--success-400));
            --cal-off: rgb(var(--danger-400));
        }

        /* A sailing in dark mode is the lighter primary, and its label the dark
           end of the same scale — white on the dark 600 is 3.1:1. */
        .dark .bar { background: rgb(var(--primary-400)); color: rgb(var(--primary-950)); }
        .dark .swatch.is-departure { background: rgb(var(--primary-400)); }
        .dark .drag { background: rgb(var(--primary-300)); }

        .dark .cal-track {
            background: rgb(var(--gray-900));
            border-color: rgb(var(--gray-700));
        }

        .dark .cal-ruler .cal-track { background: transparent; }

        /* The lines that divide the hours have to stay *quieter* than the track's
           own border, or the grid reads as a table. On white that is a light
           grey; on black it is a white at eight per cent, which is the same
           instruction. */
        .dark .grid-line { background: rgba(255, 255, 255, .08); }

        .dark .cal-hint,
        .dark .cal-empty,
        .dark .cal-key,
        .dark .cal-label span { color: rgb(var(--gray-400)); }

        .dark .cal-ruler .tick { color: rgb(var(--gray-400)); }

        /* A block and an external booking are greys against the sailings, and a
           grey chosen to sit below white now has to sit above black. Both move
           up the ramp by exactly as much as the ground moved down. */
        .dark .bar.is-block,
        .dark .swatch.is-block { background: rgb(var(--gray-500)); }
        .dark .bar.is-block { color: #fff; }

        .dark .bar.is-external,
        .dark .swatch.is-external {
            background: repeating-linear-gradient(45deg,
                rgb(var(--gray-400)), rgb(var(--gray-400)) 6px,
                rgb(var(--gray-300)) 6px, rgb(var(--gray-300)) 12px);
        }
        .dark .bar.is-external { color: rgb(var(--gray-950)); }

        /* Cancelled is the one that inverts rather than shifts: on white it is a
           pale bar with dark type, so on black it is a dark bar with pale type.
           Struck through either way — the strike is the meaning, the colour is
           only how quiet it is. */
        .dark .bar.is-cancelled {
            background: rgb(var(--gray-700));
            color: rgb(var(--gray-400));
        }
    </style>
</x-filament-panels::page>
