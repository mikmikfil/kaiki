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
    <x-filament::section>
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

            <x-filament::section>
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

        <x-filament::section>
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

        <x-filament::section>
            <x-slot name="heading">{{ __('analytics.headline.pax') }}</x-slot>

            <p class="text-3xl font-semibold tracking-tight">{{ $report['sales']['pax'] }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('analytics.headline.pax_basis') }}</p>
        </x-filament::section>

        @if ($this->showsMoney())
            <x-filament::section>
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
        <x-filament::section>
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
                    <span>{{ $series[count($series) - 1]['bucket'] }}</span>
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Per trip and per boat. --}}
    <div class="grid gap-4 xl:grid-cols-2">
        @foreach ([
            ['rows' => $report['products'], 'heading' => __('analytics.products.heading'), 'label' => __('analytics.products.label'), 'help' => __('analytics.products.help')],
            ['rows' => $report['vessels'], 'heading' => __('analytics.vessels.heading'), 'label' => __('analytics.vessels.label'), 'help' => null],
        ] as $table)
            <x-filament::section>
                <x-slot name="heading">{{ $table['heading'] }}</x-slot>

                @if ($table['help'])
                    <x-slot name="description">{{ $table['help'] }}</x-slot>
                @endif

                @if ($table['rows'] === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.empty') }}</p>
                @else
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
                                    <td class="py-2 pr-2">{{ $row['label'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $row['bookings'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $row['pax'] }}</td>
                                    @if ($this->showsMoney())
                                        <td class="py-2 text-right tabular-nums">{{ $money($row['revenue']) }}</td>
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
    <x-filament::section>
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
                                    <td class="py-2">{{ $month['month'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $month['sold'] }} / {{ $month['capacity'] }}</td>
                                    <td class="py-2 text-right tabular-nums font-medium">{{ $percent($month['rate']) }}</td>
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
                                    <td class="py-2">{{ $row['label'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $row['sold'] }} / {{ $row['capacity'] }}</td>
                                    <td class="py-2 text-right tabular-nums font-medium">{{ $percent($row['rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>

    {{-- The emptiest sailings: the part of the page an operator can act on. --}}
    <x-filament::section>
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
                            <td class="py-2">{{ $sailing['date'] }}</td>
                            <td class="py-2 tabular-nums">{{ $sailing['time'] }}</td>
                            <td class="py-2">{{ $sailing['label'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $sailing['sold'] }} / {{ $sailing['capacity'] }}</td>
                            <td class="py-2 text-right tabular-nums font-medium">{{ $percent($sailing['rate']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>

    {{-- Where the bookings came from, from the bookings themselves. --}}
    <x-filament::section>
        <x-slot name="heading">{{ __('analytics.sources.heading') }}</x-slot>
        <x-slot name="description">{{ __('analytics.sources.help') }}</x-slot>

        @if ($report['sources'] === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('analytics.sources.none') }}</p>
        @else
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

    {{-- The funnel (ADR-0032). Counts per step, and the ratio between two of
         them — never called a conversion rate, because cookieless means nobody
         is followed from one step to the next and a number named something it
         is not is worse than no number. --}}
    <x-filament::section>
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
    <x-filament::section>
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
