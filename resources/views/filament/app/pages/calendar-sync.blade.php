{{--
    Calendar sync (#124, OPS-13, OPS-14, OPS-15).

    Two directions on one screen, because to an operator they are one question.
    The export half leads with what the address exposes — busy periods and
    nothing else — because the address is the whole authentication and it ends
    up pasted into services nobody here controls.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1). And no `text-transform: uppercase` on anything that can
    hold Greek: capitals drop their accents.
--}}
<x-filament-panels::page>
    <div class="space-y-8">

        {{-- ── Out ─────────────────────────────────────────────────────── --}}
        <section class="space-y-4">
            <div>
                <h3 class="text-base font-semibold">{{ __('ical.export.heading') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ical.export.body') }}</p>
            </div>

            @foreach ($this->vessels() as $vessel)
                @php($feed = $this->feedFor($vessel))

                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="font-semibold">{{ $vessel->name }}</p>

                        <div class="flex items-center gap-2">
                            @if ($feed->is_active)
                                <x-filament::badge color="success">{{ __('ical.export.enable') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">{{ __('ical.export.disabled') }}</x-filament::badge>
                            @endif

                            <x-filament::button
                                size="xs"
                                color="gray"
                                wire:click="togglePublish({{ $feed->getKey() }})"
                            >
                                {{ $feed->is_active ? __('ical.export.disable') : __('ical.export.enable') }}
                            </x-filament::button>
                        </div>
                    </div>

                    @if ($feed->is_active)
                        {{-- `select-all` so one click takes the whole address: it is
                             40 hex characters and a partial copy is a subscription
                             that silently never works. --}}
                        <p class="mt-3 select-all break-all rounded-lg bg-gray-50 p-2 font-mono text-xs dark:bg-gray-800">
                            {{ $this->feedUrl($feed) }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    @checked($feed->include_departures)
                                    wire:click="toggleDepartures({{ $feed->getKey() }})"
                                >
                                <span>{{ __('ical.export.include_departures') }}</span>
                            </label>

                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    @checked($feed->include_blocks)
                                    wire:click="toggleBlocks({{ $feed->getKey() }})"
                                >
                                <span>{{ __('ical.export.include_blocks') }}</span>
                            </label>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($feed->last_accessed_at)
                                    {{ __('ical.export.last_read') }}:
                                    {{ $feed->last_accessed_at->diffForHumans() }}
                                    · {{ __('ical.export.reads') }}: {{ $feed->access_count }}
                                @else
                                    {{ __('ical.export.never_read') }}
                                @endif
                            </p>

                            {{-- The confirmation names the consequence rather than
                                 asking "are you sure": every subscriber breaks
                                 silently and immediately, and nothing on anybody's
                                 screen will say why. --}}
                            <x-filament::button
                                size="xs"
                                color="danger"
                                wire:click="rotate({{ $feed->getKey() }})"
                                wire:confirm="{{ __('ical.export.rotate_confirm') }}"
                            >
                                {{ __('ical.export.rotate') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- ── In ──────────────────────────────────────────────────────── --}}
        <section class="space-y-4">
            <div>
                <h3 class="text-base font-semibold">{{ __('ical.import.heading') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ical.import.body') }}</p>
            </div>

            <form wire:submit="addSource" class="space-y-4">
                {{ $this->form }}

                <x-filament::button type="submit">
                    {{ __('ical.import.add') }}
                </x-filament::button>
            </form>

            @foreach ($this->sources() as $source)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $source->name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $source->vessel?->name }}</p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if (! $source->is_active)
                                <x-filament::badge color="gray">{{ __('ical.import.status_off') }}</x-filament::badge>
                            @elseif ($source->needsAttention())
                                <x-filament::badge color="warning">{{ __('ical.import.status_attention') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="success">{{ __('ical.import.status_ok') }}</x-filament::badge>
                            @endif

                            <x-filament::button
                                size="xs"
                                color="gray"
                                wire:click="syncNow({{ $source->getKey() }})"
                            >
                                {{ __('ical.import.sync_now') }}
                            </x-filament::button>

                            <x-filament::button
                                size="xs"
                                color="danger"
                                wire:click="removeSource({{ $source->getKey() }})"
                                wire:confirm="{{ __('ical.import.remove_confirm') }}"
                            >
                                {{ __('ical.import.remove') }}
                            </x-filament::button>
                        </div>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        @if ($source->last_synced_at)
                            {{ __('ical.import.last_checked') }}: {{ $source->last_synced_at->diffForHumans() }}
                            · {{ __('ical.import.events') }}: {{ $source->events_imported }}
                        @else
                            {{ __('ical.import.never_checked') }}
                        @endif
                    </p>

                    {{-- OPS-15: surfaced to the operator after three consecutive
                         failures. The sentence names the likeliest cause, because
                         "sync failed" sends them to us and "the address may have
                         changed" sends them to the right place. --}}
                    @if ($source->needsAttention())
                        <div class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                            <p>{{ $source->is_active ? __('ical.import.attention') : __('ical.import.disabled_notice') }}</p>

                            <p class="mt-1 text-xs">
                                {{ __('ical.import.failures', ['count' => $source->consecutive_failures]) }}
                                @if ($source->last_error)
                                    — {{ $source->last_error }}
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

    </div>
</x-filament-panels::page>
