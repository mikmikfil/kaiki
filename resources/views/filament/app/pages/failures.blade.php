{{--
    «Τι πήγε στραβά» — OPS-21's consolidated failure feed.

    A hand-written list rather than a Filament table, because the rows come from
    six unrelated models and a table wants one query. `FailureFeed` does the
    merging; this decides what a row looks like.

    Every row answers three things in the order an operator asks them: what
    failed, what it means, and what to do about it. The provider's own words are
    last and small — useful to whoever is going to paste them into a support
    ticket, and noise to everybody else.
--}}
<x-filament-panels::page>
    @php($items = $this->getItems())

    @if ($items === [])
        <x-filament::section>
            <div class="kf-empty">
                <p class="kf-empty-title">{{ __('failures.empty.heading') }}</p>
                <p class="kf-empty-detail">{{ __('failures.empty.description') }}</p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('failures.heading', ['count' => count($items)]) }}</x-slot>
            <x-slot name="description">{{ __('failures.subheading') }}</x-slot>

            <div class="kf-list">
                @foreach ($items as $item)
                    <div class="kf-row" wire:key="{{ $item->key }}">
                        <div class="kf-what">
                            <div class="kf-badges">
                                <x-filament::badge :color="$item->source->color()" size="sm">
                                    {{ $item->source->label() }}
                                </x-filament::badge>

                                @if ($item->bookingReference !== null)
                                    {{-- OPS-21 asks for "the affected booking". The
                                         reference is what an operator searches on. --}}
                                    <span class="kf-ref">{{ $item->bookingReference }}</span>
                                @endif
                            </div>

                            <p class="kf-title">{{ $item->title }}</p>
                            <p class="kf-explanation">{{ $item->explanation }}</p>

                            @if ($item->detail !== null && $item->detail !== '')
                                {{-- The provider's own words. Last and quiet: it is
                                     the thing you want when you are about to ask
                                     somebody else for help, and noise until then. --}}
                                <p class="kf-detail">{{ \Illuminate\Support\Str::limit($item->detail, 160) }}</p>
                            @endif
                        </div>

                        <div class="kf-when">
                            <time datetime="{{ $item->failedAt->toIso8601String() }}"
                                  title="{{ $item->failedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}">
                                {{ $item->failedAt->diffForHumans() }}
                            </time>

                            @if ($item->retryable)
                                <x-filament::button
                                    size="xs"
                                    color="gray"
                                    icon="heroicon-o-arrow-path"
                                    wire:click="retry('{{ $item->source->value }}', {{ $item->id }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('failures.retry.label') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <style>
        .kf-list { display: flex; flex-direction: column; }

        .kf-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 1.25rem; padding: .85rem 0;
            border-bottom: 1px solid rgb(var(--gray-200));
        }

        .kf-row:last-child { border-bottom: 0; padding-bottom: 0; }
        .kf-row:first-child { padding-top: 0; }

        .kf-what { min-width: 0; }

        .kf-badges { display: flex; align-items: center; gap: .5rem; margin-bottom: .3rem; }

        .kf-ref {
            font-family: ui-monospace, monospace; font-size: .72rem;
            color: rgb(var(--gray-500));
        }

        .kf-title { font-size: .9rem; font-weight: 600; margin: 0; }
        .kf-explanation { font-size: .82rem; color: rgb(var(--gray-600)); margin: .15rem 0 0; }

        .kf-detail {
            font-family: ui-monospace, monospace; font-size: .7rem;
            color: rgb(var(--gray-400)); margin: .3rem 0 0;
            overflow-wrap: anywhere;
        }

        .kf-when {
            display: flex; flex-direction: column; align-items: flex-end; gap: .4rem;
            flex: none; font-size: .75rem; color: rgb(var(--gray-500));
            white-space: nowrap;
        }

        .kf-empty { text-align: center; padding: 1.5rem 0; }
        .kf-empty-title { font-size: .95rem; font-weight: 600; margin: 0; }
        .kf-empty-detail { font-size: .82rem; color: rgb(var(--gray-500)); margin: .3rem 0 0; }

        @media (max-width: 48rem) {
            .kf-row { flex-direction: column; gap: .5rem; }
            .kf-when { align-items: flex-start; flex-direction: row; align-items: center; }
        }
    </style>
</x-filament-panels::page>
