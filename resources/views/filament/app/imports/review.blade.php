{{--
    The import review screen (SAA-14, SAA-15).

    The choices first, then every record the files held with what will happen
    to it — or what did — and why. It polls while a queued job is working, so
    the operator watches the status move rather than refreshing a page that
    looks stuck.

    No hardcoded strings (I18N-1): `NoHardcodedStringsTest` scans this directory.
--}}
<x-filament-panels::page>
    <div @if ($this->isBusy()) wire:poll.5s @endif class="flex flex-col gap-6">

        @if ($this->isBusy())
            <x-filament::section>
                <p class="text-sm">{{ __('imports.review.busy') }}</p>
            </x-filament::section>
        @endif

        @if (filled($this->job()->error_message))
            <x-filament::section>
                <p class="text-sm font-medium text-danger-600">{{ $this->job()->error_message }}</p>
            </x-filament::section>
        @endif

        <form wire:submit="save" class="flex flex-col gap-4">
            {{ $this->form }}

            @if ($this->isReviewable())
                <div>
                    <x-filament::button type="submit" color="gray">
                        {{ __('imports.actions.save') }}
                    </x-filament::button>
                </div>
            @endif
        </form>

        <x-filament::section :heading="__('imports.review.log')">
            @php($groups = $this->groups())

            @if ($groups === [])
                <p class="text-sm text-gray-500">{{ __('imports.review.nothing') }}</p>
            @endif

            <div class="flex flex-col gap-6">
                @foreach ($groups as $type => $rows)
                    <div>
                        <h3 class="text-sm font-semibold">{{ \App\Enums\ImportRowType::from($type)->label() }}</h3>
                        <p class="text-xs text-gray-500">{{ $this->countsLine($rows) }}</p>

                        <ul class="mt-2 divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($rows as $row)
                                <li class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2 text-sm" data-row="{{ $row->source_type->value }}-{{ $row->source_id }}">
                                    <span class="min-w-0 flex-1 font-medium">{{ $row->label() }}</span>
                                    <x-filament::badge :color="$row->status->color()" size="sm">
                                        {{ $row->status->label() }}
                                    </x-filament::badge>
                                    @if ($row->messages !== [])
                                        <span class="w-full text-xs text-gray-500">{{ implode(' ', $row->renderedMessages()) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

    </div>
</x-filament-panels::page>
