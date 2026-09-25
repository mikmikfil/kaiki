{{--
    How far an operator got through the first-run guide, on Edit Merchant.

    It used to be a line of «Τα στοιχεία σας: έγινε» strings joined by `<br>`,
    which read like debug output and told you nothing at a glance — the one
    question somebody on this screen has is *should I ring them*, and counting
    six words to answer it is six too many.

    **Every colour and icon here comes from a Filament component**, never from a
    Tailwind class written by hand. The panel ships a compiled stylesheet
    containing only the utilities its own components use: a colour class that is
    not in it renders black and nothing warns you. That is exactly how the
    statistics chart's bars came out black on 14 September.
--}}

@php
    $done = collect($steps)->where('state', 'done')->count();
    $total = count($steps);
    $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;
@endphp

<div class="space-y-3">
    {{-- The answer first: finished, or how far short. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        @if ($finished)
            <x-filament::badge color="success">{{ $headline }}</x-filament::badge>
        @else
            <x-filament::badge color="warning">{{ $headline }}</x-filament::badge>
        @endif

        <span class="text-sm text-gray-500 dark:text-gray-400">
            {{ trans_choice('tenants.edit.setup_remaining', $total - $done, ['count' => $total - $done]) }}
        </span>
    </div>

    {{-- A bar rather than a chart. Six steps do not need axes, and the panel's
         own `fi-` colour tokens keep it legible in both themes. --}}
    <div
        class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
        role="progressbar"
        aria-valuenow="{{ $done }}"
        aria-valuemin="0"
        aria-valuemax="{{ $total }}"
        aria-label="{{ $headline }}"
    >
        <div
            @class([
                'h-full rounded-full transition-all',
                'bg-success-500' => $finished,
                'bg-primary-500' => ! $finished,
            ])
            style="width: {{ $percent }}%"
        ></div>
    </div>

    {{-- Two columns on anything but a phone: six rows in one column is a
         scroll on a screen that already has three tabs above it. --}}
    <ul class="grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-2">
        @foreach ($steps as $step)
            <li class="flex items-start gap-2 text-sm">
                @if ($step['state'] === 'done')
                    <x-filament::icon
                        icon="heroicon-m-check-circle"
                        class="mt-0.5 h-4 w-4 shrink-0 text-success-600 dark:text-success-400"
                    />
                @elseif ($step['state'] === 'later')
                    <x-filament::icon
                        icon="heroicon-m-clock"
                        class="mt-0.5 h-4 w-4 shrink-0 text-warning-600 dark:text-warning-400"
                    />
                @else
                    <x-filament::icon
                        icon="heroicon-m-minus-circle"
                        class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500"
                    />
                @endif

                {{-- The state is in the icon *and* in the text weight, never in
                     colour alone: a skipped step and an open one are told apart
                     by somebody who cannot see the difference between amber and
                     grey. --}}
                <span @class([
                    'text-gray-500 dark:text-gray-400' => $step['state'] === 'done',
                    'font-medium text-gray-950 dark:text-white' => $step['state'] !== 'done',
                ])>
                    {{ $step['label'] }}

                    @if ($step['state'] === 'later')
                        <span class="text-gray-400 dark:text-gray-500">· {{ $step['note'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</div>
