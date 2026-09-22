{{--
    The statistics page.

    ## The chart is drawn here, in SVG, and loads nothing

    Filament ships Chart.js and it would work. It is not used, for three
    reasons that all point the same way: this page is one an operator prints or
    sends to an accountant, and a canvas prints as an empty box; a chart of
    thirty numbers is thirty rectangles and a baseline, which is less code in
    SVG than the configuration object would be; and every figure on the page is
    already server-rendered, so a single JavaScript dependency here would be the
    only thing on the screen that can fail to appear.

    ## Icons and bars (Mike, 2026-09-22)

    Every section carries the icon of the thing it counts, and every table that
    ranks rows carries a bar. The icon is how a reader finds the block they
    came for on a page of eleven; the bar is the answer to «which is the big
    one», which is otherwise a subtraction the reader does in their head. Both
    are drawn from the figures already on the page — no shape here is the only
    place a number appears, so the page still reads as a table when it is
    printed in black and white.

    ## Nothing here computes anything

    `Analytics::report()` gathers it in one pass and `AnalyticsFigures` does the
    arithmetic — a blade that divided two numbers would be a second definition
    of a figure, in the one place nobody writes a test for.

    Nothing is a hardcoded string: `NoHardcodedStringsTest` scans this directory
    (I18N-1).
--}}
@php
    $report = $this->report();
    $range = $report['range'];
    $money = fn (int $cents): string => $this->money($cents);
    $percent = fn (?float $value): ?string => $this->percent($value);
    $series = $report['series'];
    $peak = max(1, max(array_map(static fn (array $point): int => max(0, $point['revenue']), $series)) ?: 1);
@endphp

<x-filament-panels::page>

    {{-- The period. A form of three controls that reloads the page's own data,
         with the choice kept in the URL so it can be sent to somebody. --}}
    <x-filament::section icon="heroicon-o-calendar-days" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.range.label') }}</x-slot>

        <div class="flex flex-wrap items-end gap-4">
            {{-- No label of its own: the section above it is called «Διάστημα»
                 and a second copy under it reads as two different controls. --}}
            <label class="flex flex-col gap-1">
                <select
                    wire:model.live="preset"
                    class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900"
                >
                    @foreach ($this->presetOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            @if ($this->preset === \App\Filament\App\Pages\Analytics::PRESET_CUSTOM)
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('analytics.range.from') }}</span>
                    <input
                        type="date"
                        wire:model.live="from"
                        class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900"
                    >
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('analytics.range.to') }}</span>
                    <input
                        type="date"
                        wire:model.live="to"
                        class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900"
                    >
                </label>
            @endif

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $range->startLocalDate }} — {{ $range->endLocalDate }}
            </p>
        </div>

        @if ($report['has_test_bookings'])
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.test_data') }}</p>
        @endif
    </x-filament::section>

    {{-- The headline. Each figure says what it counts, because OPS-2's argument
         about the dashboard applies twice as hard to a page of reports. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @if ($this->showsMoney())
            @php $change = $this->change($report['revenue'], $report['revenue_previous']); @endphp

            <x-filament::section icon="heroicon-o-banknotes" icon-color="primary">
                <x-slot name="heading">{{ __('analytics.headline.revenue') }}</x-slot>

                <p class="text-3xl font-semibold tracking-tight">{{ $money($report['revenue']) }}</p>

                <p class="mt-1 text-sm {{ $change === null ? 'text-gray-500' : ($change >= 0 ? 'text-success-600' : 'text-danger-600') }}">
                    @if ($change === null)
                        {{ __('analytics.compare.no_basis') }}
                    @else
                        {{ $percent($change) }} {{ __('analytics.compare.previous') }}
                    @endif
                </p>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.headline.revenue_basis') }}</p>
            </x-filament::section>
        @endif

        <x-filament::section icon="heroicon-o-ticket" icon-color="primary">
            <x-slot name="heading">{{ __('analytics.headline.bookings') }}</x-slot>

            <p class="text-3xl font-semibold tracking-tight">{{ $report['sales']['bookings'] }}</p>

            @php $bookingChange = $this->change($report['sales']['bookings'], $report['sales_previous']['bookings']); @endphp

            <p class="mt-1 text-sm {{ $bookingChange === null ? 'text-gray-500' : ($bookingChange >= 0 ? 'text-success-600' : 'text-danger-600') }}">
                @if ($bookingChange === null)
                    {{ __('analytics.compare.no_basis') }}
                @else
                    {{ $percent($bookingChange) }} {{ __('analytics.compare.previous') }}
                @endif
            </p>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.headline.bookings_basis') }}</p>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-users" icon-color="primary">
            <x-slot name="heading">{{ __('analytics.headline.pax') }}</x-slot>

            <p class="text-3xl font-semibold tracking-tight">{{ $report['sales']['pax'] }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.headline.pax_basis') }}</p>
        </x-filament::section>

        @if ($this->showsMoney())
            <x-filament::section icon="heroicon-o-calculator" icon-color="primary">
                <x-slot name="heading">{{ __('analytics.headline.average') }}</x-slot>

                <p class="text-3xl font-semibold tracking-tight">
                    {{ $report['average'] === null ? '—' : $money($report['average']) }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.headline.average_basis') }}</p>
            </x-filament::section>
        @endif
    </div>

    {{-- Revenue over time. Bars rather than a line: the buckets are days, and a
         line between two days implies a value at noon that nothing measured. --}}
    @if ($this->showsMoney())
        <x-filament::section icon="heroicon-o-chart-bar" icon-color="primary">
            <x-slot name="heading">{{ __('analytics.series.heading') }}</x-slot>
            <x-slot name="description">
                @if ($report['grain'] === \App\Domain\Analytics\Support\LocalRange::GRAIN_DAY)
                    {{ __('analytics.series.help') }}
                @elseif ($report['grain'] === \App\Domain\Analytics\Support\LocalRange::GRAIN_WEEK)
                    {{ __('analytics.series.help_week') }}
                @else
                    {{ __('analytics.series.help_month') }}
                @endif
            </x-slot>

            @if ($report['revenue'] === 0)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.series.empty') }}</p>
            @else
                @php
                    $count = max(1, count($series));
                    $width = 1000;
                    $height = 220;
                    $gap = $count > 60 ? 1 : 3;
                    $bar = max(1, ($width - $gap * ($count - 1)) / $count);
                @endphp

                <svg
                    viewBox="0 0 {{ $width }} {{ $height }}"
                    preserveAspectRatio="none"
                    class="h-56 w-full"
                    role="img"
                    aria-label="{{ __('analytics.series.heading') }}"
                >
                    {{-- Three lines to read the bars against: the peak, half of
                         it, and the floor. Without them a tall bar beside a
                         short one says «more», and this page is read to find
                         out «how much more». `preserveAspectRatio="none"`
                         stretches the box, so the lines are drawn with
                         `vector-effect` to keep them one pixel however wide
                         the card is. --}}
                    @foreach ([0.0, 0.5, 1.0] as $line)
                        <line
                            x1="0"
                            x2="{{ $width }}"
                            y1="{{ round($height - ($line * ($height - 20)), 2) }}"
                            y2="{{ round($height - ($line * ($height - 20)), 2) }}"
                            vector-effect="non-scaling-stroke"
                            stroke-width="1"
                            style="stroke: rgb(var(--gray-200))"
                        />
                    @endforeach
                    @foreach ($series as $index => $point)
                        @php
                            $value = max(0, $point['revenue']);
                            $barHeight = $value === 0 ? 1 : max(2, ($value / $peak) * ($height - 20));
                            $x = $index * ($bar + $gap);
                        @endphp

                        <rect
                            x="{{ round($x, 2) }}"
                            y="{{ round($height - $barHeight, 2) }}"
                            width="{{ round($bar, 2) }}"
                            height="{{ round($barHeight, 2) }}"
                            rx="1"
                            {{-- An inline fill, not a `fill-primary-500` class:
                                 the panel's stylesheet is built and shipped
                                 inside Filament, so a Tailwind utility this
                                 project is the first to use is not in it — and
                                 a class that does not exist paints the bars
                                 black, which is exactly how this was found.
                                 `--primary-600` is Filament's own variable, so
                                 the chart follows the operator's brand. --}}
                            style="fill: rgb(var(--primary-600))"
                        >
                            <title>{{ $point['bucket'] }} — {{ $money($point['revenue']) }}</title>
                        </rect>
                    @endforeach
                </svg>

                <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>{{ $series[0]['bucket'] }}</span>
                    {{-- What the top line is worth. The scale of a chart drawn
                         to its own peak means nothing until one figure on it is
                         named. --}}
                    <span class="tabular-nums">{{ __('analytics.series.peak', ['amount' => $money($peak)]) }}</span>
                    <span>{{ $series[count($series) - 1]['bucket'] }}</span>
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Per trip and per boat. --}}
    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ([
            ['rows' => $report['products'], 'heading' => __('analytics.products.heading'), 'label' => __('analytics.products.label'), 'help' => __('analytics.products.help'), 'icon' => 'heroicon-o-map'],
            ['rows' => $report['vessels'], 'heading' => __('analytics.vessels.heading'), 'label' => __('analytics.vessels.label'), 'help' => null, 'icon' => 'heroicon-o-lifebuoy'],
        ] as $table)
            <x-filament::section :icon="$table['icon']" icon-color="primary">
                <x-slot name="heading">{{ $table['heading'] }}</x-slot>

                @if ($table['help'])
                    <x-slot name="description">{{ $table['help'] }}</x-slot>
                @endif

                @if ($table['rows'] === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.empty') }}</p>
                @else
                    {{-- The scale of the bars: the biggest row of **this**
                         table, on the figure the table is sorted by. Money when
                         the reader is allowed to see money, passengers when
                         they are not — so a manager gets bars of the thing
                         their own columns show rather than of a column that
                         is not on their screen. --}}
                    @php
                        $barKey = $this->showsMoney() ? 'revenue' : 'pax';
                        $tableTop = max(1, max(array_map(static fn (array $row): int => (int) ($row[$barKey] ?? 0), $table['rows'])) ?: 1);
                    @endphp

                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="py-2">{{ $table['label'] }}</th>
                                <th class="py-2 text-right">{{ __('analytics.columns.bookings') }}</th>
                                <th class="py-2 text-right">{{ __('analytics.columns.pax') }}</th>
                                @if ($this->showsMoney())
                                    <th class="py-2 text-right">{{ __('analytics.columns.revenue') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($table['rows'] as $row)
                                <tr>
                                    <td class="py-2 pr-2 align-top">
                                        {{ $row['label'] }}
                                        @include('filament.app.pages.analytics.bar', ['ratio' => ((int) ($row[$barKey] ?? 0)) / $tableTop])
                                    </td>
                                    <td class="py-2 text-right align-top tabular-nums">{{ $row['bookings'] }}</td>
                                    <td class="py-2 text-right align-top tabular-nums">{{ $row['pax'] }}</td>
                                    @if ($this->showsMoney())
                                        <td class="py-2 text-right align-top tabular-nums">{{ $money($row['revenue']) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    {{-- Occupancy: the number that turns into money. --}}
    <x-filament::section icon="heroicon-o-chart-pie" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.occupancy.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.occupancy.help') }}</x-slot>

        @if ($report['occupancy']['rate'] === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.occupancy.none') }}</p>
        @else
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.occupancy.rate') }}</p>
                    <p class="text-3xl font-semibold tracking-tight">{{ $percent($report['occupancy']['rate']) }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.occupancy.sold') }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ $report['occupancy']['sold'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.occupancy.capacity') }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ $report['occupancy']['capacity'] }}</p>
                </div>
                <div>
                    <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.occupancy.departures') }}</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ $report['occupancy']['departures'] }}</p>
                </div>
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-semibold">{{ __('analytics.occupancy.by_month') }}</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($report['occupancy_by_month'] as $month)
                                <tr>
                                    <td class="py-2 align-top">
                                        {{ $month['month'] }}
                                        {{-- Occupancy is already a share of
                                             something, so the bar is the figure
                                             itself rather than a share of the
                                             biggest row: a full bar is a full
                                             boat, in every row of every table
                                             on this page. --}}
                                        @include('filament.app.pages.analytics.bar', ['ratio' => $month['rate']])
                                    </td>
                                    <td class="py-2 text-right align-top tabular-nums">{{ $month['sold'] }} / {{ $month['capacity'] }}</td>
                                    <td class="py-2 text-right align-top tabular-nums font-medium">{{ $percent($month['rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold">{{ __('analytics.occupancy.by_product') }}</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($report['occupancy_by_product'] as $row)
                                <tr>
                                    <td class="py-2 align-top">
                                        {{ $row['label'] }}
                                        @include('filament.app.pages.analytics.bar', ['ratio' => $row['rate']])
                                    </td>
                                    <td class="py-2 text-right align-top tabular-nums">{{ $row['sold'] }} / {{ $row['capacity'] }}</td>
                                    <td class="py-2 text-right align-top tabular-nums font-medium">{{ $percent($row['rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>


    {{-- **Columns**, because the seven of them are a shape: a week, read left
         to right, with the tall ones where the boat goes out full. The other
         charts on this page are shares of something; this one is a comparison
         of seven things that have an order of their own, and columns are how
         that is drawn everywhere. --}}
    <x-filament::section icon="heroicon-o-calendar" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.weekdays.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.weekdays.help') }}</x-slot>

        @php
            $weekPeak = max(1, max(array_map(static fn (array $day): int => $day['pax'], $report['weekdays'])) ?: 1);
            $weekTotal = array_sum(array_map(static fn (array $day): int => $day['pax'], $report['weekdays']));
        @endphp

        @if ($weekTotal === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.weekdays.empty') }}</p>
        @else
            <div class="flex items-end gap-2" style="height: 9rem;">
                @foreach ($report['weekdays'] as $day)
                    <div class="flex flex-1 flex-col items-center justify-end gap-1" style="height: 100%;">
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $day['pax'] }}</span>
                        {{-- A column with a floor: a day with nobody on it is
                             still a day, and a bar of zero height reads as a
                             missing column rather than as an empty Tuesday. --}}
                        <span
                            class="w-full rounded-t"
                            style="height: {{ max(2, round(($day['pax'] / $weekPeak) * 100, 1)) }}%; background: color-mix(in srgb, rgb(var(--primary-600)) {{ $day['pax'] === 0 ? 18 : 100 }}%, transparent);"
                            title="{{ __('analytics.weekdays.day.' . $day['weekday']) }} — {{ $day['pax'] }}"
                        ></span>
                    </div>
                @endforeach
            </div>

            <div class="mt-2 flex gap-2">
                @foreach ($report['weekdays'] as $day)
                    <span class="flex-1 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.weekdays.short.' . $day['weekday']) }}</span>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    {{-- **One bar, in five parts**: how far ahead the bookings came in. A
         hundred per cent of something, so it is drawn as the whole of one line
         rather than as five bars that have to be added up by eye. --}}
    <x-filament::section icon="heroicon-o-clock" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.lead_time.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.lead_time.help') }}</x-slot>

        @php
            $leadTotal = array_sum(array_map(static fn (array $part): int => $part['bookings'], $report['lead_time']));
        @endphp

        @if ($leadTotal === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.lead_time.empty') }}</p>
        @else
            <div class="flex h-4 w-full overflow-hidden rounded" style="background: rgb(var(--gray-100));">
                @foreach ($report['lead_time'] as $index => $part)
                    @continue($part['bookings'] === 0)
                    <span
                        style="width: {{ round(($part['bookings'] / $leadTotal) * 100, 2) }}%; background: color-mix(in srgb, rgb(var(--primary-600)) {{ max(25, 100 - ($index * 18)) }}%, transparent);"
                        title="{{ __('analytics.lead_time.bucket.' . $part['bucket']) }} — {{ $part['bookings'] }}"
                    ></span>
                @endforeach
            </div>

            <ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($report['lead_time'] as $index => $part)
                    <li class="flex items-center gap-2">
                        <span
                            class="inline-block h-3 w-3 rounded-sm"
                            style="background: color-mix(in srgb, rgb(var(--primary-600)) {{ max(25, 100 - ($index * 18)) }}%, transparent);"
                        ></span>
                        <span>{{ __('analytics.lead_time.bucket.' . $part['bucket']) }}</span>
                        <span class="tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $part['bookings'] }} · {{ $percent($part['bookings'] / $leadTotal) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{-- The emptiest sailings: the part of the page an operator can act on. --}}
    <x-filament::section icon="heroicon-o-moon" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.quiet.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.quiet.help') }}</x-slot>

        @if ($report['quiet'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.quiet.none') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">{{ __('analytics.quiet.date') }}</th>
                        <th class="py-2">{{ __('analytics.quiet.time') }}</th>
                        <th class="py-2">{{ __('analytics.products.label') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.quiet.seats') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.occupancy.rate') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($report['quiet'] as $sailing)
                        <tr>
                            <td class="py-2 align-top">{{ $sailing['date'] }}</td>
                            <td class="py-2 align-top tabular-nums">{{ $sailing['time'] }}</td>
                            <td class="py-2 align-top">
                                {{ $sailing['label'] }}
                                @include('filament.app.pages.analytics.bar', ['ratio' => $sailing['rate']])
                            </td>
                            <td class="py-2 text-right align-top tabular-nums">{{ $sailing['sold'] }} / {{ $sailing['capacity'] }}</td>
                            <td class="py-2 text-right align-top tabular-nums font-medium">{{ $percent($sailing['rate']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    {{-- Where the bookings came from, from the bookings themselves. --}}
    <x-filament::section icon="heroicon-o-globe-alt" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.sources.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.sources.help') }}</x-slot>

        @if ($report['sources'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.sources.none') }}</p>
        @else
            {{-- The mix as a ring, beside the table that says what each slice
                 is worth (2026-09-22).

                 A pie is the wrong shape for most things and the right one for
                 this: «how much of our business comes through the website» is
                 a question about parts of a whole, and there are three or four
                 channels, not thirty.

                 Drawn with one circle per slice and a dash pattern round its
                 circumference — the trick that needs no arc arithmetic and no
                 library. Every slice is the operator's own colour at a
                 different strength rather than a palette of unrelated hues:
                 the channels are not categories of different kinds, they are
                 shares of one thing. --}}
            @php
                $mixTotal = max(1, array_sum(array_map(static fn (array $row): int => (int) $row['bookings'], $report['sources'])));
                $mixOffset = 0.0;
            @endphp

            <div class="mb-6 flex flex-wrap items-center gap-6">
                <svg viewBox="0 0 42 42" class="h-32 w-32 -rotate-90" role="img" aria-label="{{ __('analytics.sources.heading') }}">
                    <circle cx="21" cy="21" r="15.915" fill="transparent" stroke-width="6" style="stroke: rgb(var(--gray-100))"></circle>

                    @foreach ($report['sources'] as $index => $row)
                        @php
                            $share = ((int) $row['bookings']) / $mixTotal;
                            $length = round($share * 100, 2);
                            $strength = max(25, 100 - ($index * 22));
                        @endphp

                        <circle
                            cx="21" cy="21" r="15.915"
                            fill="transparent"
                            stroke-width="6"
                            stroke-dasharray="{{ $length }} {{ round(100 - $length, 2) }}"
                            stroke-dashoffset="{{ round(100 - $mixOffset, 2) }}"
                            style="stroke: color-mix(in srgb, rgb(var(--primary-600)) {{ $strength }}%, transparent)"
                        >
                            <title>{{ $row['label'] }} — {{ $row['bookings'] }}</title>
                        </circle>

                        @php $mixOffset += $length; @endphp
                    @endforeach
                </svg>

                <ul class="grid gap-2 text-sm">
                    @foreach ($report['sources'] as $index => $row)
                        <li class="flex items-center gap-2">
                            <span
                                class="inline-block h-3 w-3 rounded-sm"
                                style="background: color-mix(in srgb, rgb(var(--primary-600)) {{ max(25, 100 - ($index * 22)) }}%, transparent)"
                            ></span>
                            <span>{{ $row['label'] }}</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $percent(((int) $row['bookings']) / $mixTotal) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">{{ __('analytics.sources.channel') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.columns.bookings') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.columns.pax') }}</th>
                        @if ($this->showsMoney())
                            <th class="py-2 text-right">{{ __('analytics.columns.revenue') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($report['sources'] as $row)
                        <tr>
                            <td class="py-2">{{ $row['label'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['bookings'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['pax'] }}</td>
                            @if ($this->showsMoney())
                                <td class="py-2 text-right tabular-nums">{{ $money($row['value']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($report['campaigns'] !== [] || $report['referrers'] !== [])
            <div class="mt-6 grid gap-6 xl:grid-cols-2">
                @if ($report['campaigns'] !== [])
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('analytics.sources.campaigns') }}</h3>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.sources.campaigns_help') }}</p>

                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($report['campaigns'] as $row)
                                    <tr>
                                        <td class="py-2">{{ $row['value'] }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ $row['bookings'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($report['referrers'] !== [])
                    <div>
                        <h3 class="text-sm font-semibold">{{ __('analytics.sources.referrers') }}</h3>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.sources.referrers_help') }}</p>

                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($report['referrers'] as $row)
                                    <tr>
                                        <td class="py-2">{{ $row['value'] }}</td>
                                        <td class="py-2 text-right tabular-nums">{{ $row['bookings'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    {{-- «Κουπόνια» (2026-09-17): which codes brought bookings, and what those
         bookings came to. --}}
    <x-filament::section icon="heroicon-o-tag" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.discount_codes.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.discount_codes.help') }}</x-slot>

        @if ($report['discount_codes'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.discount_codes.none') }}</p>
        @else
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">{{ __('discount_codes.fields.name') }}</th>
                        <th class="py-2">{{ __('discount_codes.fields.code') }}</th>
                        <th class="py-2 text-right">{{ __('discount_codes.columns.uses') }}</th>
                        @if ($this->showsMoney())
                            <th class="py-2 text-right">{{ __('discount_codes.columns.revenue') }}</th>
                            <th class="py-2 text-right">{{ __('analytics.discount_codes.discount') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($report['discount_codes'] as $row)
                        <tr>
                            <td class="py-2">{{ $row['name'] }}</td>
                            <td class="py-2 font-mono">{{ $row['code'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $row['uses'] }}</td>
                            @if ($this->showsMoney())
                                <td class="py-2 text-right tabular-nums">{{ $money($row['revenue']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $money($row['discount']) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    {{-- The funnel (ADR-0032). Counts per step, and the ratio between two of
         them — never called a conversion rate, because cookieless means nobody
         is followed from one step to the next and a number named something it
         is not is worse than no number. --}}
    <x-filament::section icon="heroicon-o-funnel" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.funnel.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.funnel.help') }}</x-slot>

        @if (! $report['has_counts'])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.funnel.empty') }}</p>
        @else
            @php $widest = max(1, $report['funnel'][0]['count'] ?: 1); @endphp

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2">{{ __('analytics.funnel.step') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.funnel.count') }}</th>
                        <th class="py-2 text-right">{{ __('analytics.funnel.ratio') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($report['funnel'] as $step)
                        <tr>
                            <td class="py-2">
                                {{ __('analytics.funnel.metrics.' . $step['metric']) }}

                                {{-- A bar the width of the step, so the shape of
                                     the drop-off is readable without doing the
                                     division in your head. --}}
                                <span
                                    class="mt-1 block h-1 rounded"
                                    style="width: {{ round(($step['count'] / $widest) * 100, 1) }}%; background: rgb(var(--primary-600))"
                                ></span>
                            </td>
                            <td class="py-2 text-right align-top tabular-nums">{{ $step['count'] }}</td>
                            <td class="py-2 text-right align-top tabular-nums">
                                {{ $step['ratio'] === null ? '—' : $percent($step['ratio']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    {{-- Cancellations. --}}
    <x-filament::section icon="heroicon-o-x-circle" icon-color="primary">
        <x-slot name="heading">{{ __('analytics.cancellations.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.cancellations.help') }}</x-slot>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.cancellations.cancelled') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $report['cancellations']['cancelled'] }}</p>
            </div>
            <div>
                <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.cancellations.no_show') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $report['cancellations']['no_show'] }}</p>
            </div>
            <div>
                <p class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ __('analytics.cancellations.rate') }}</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ $report['cancellations']['rate'] === null ? '—' : $percent($report['cancellations']['rate']) }}
                </p>
            </div>
        </div>
    </x-filament::section>

</x-filament-panels::page>
