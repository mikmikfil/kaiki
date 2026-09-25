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
    @php
        // One pass over the feed: six queries, not twelve.
        $groups = $this->groups();
        $total = array_sum(array_column($groups, 'count'));
        $visible = array_slice($groups, 0, $this->shown);
        // The provider's words split where a PHP class name has its
        // backslashes, so «Symfony\Component\Mailer\…» breaks between words
        // rather than in the middle of one (2026-09-23).
        $technical = static fn (string $text): string => str_replace('\\', '\\<wbr>', e($text));
    @endphp

    @if ($groups === [])
        <x-filament::section>
            <div class="kf-empty">
                <p class="kf-empty-title">{{ __('failures.empty.heading') }}</p>
                <p class="kf-empty-detail">{{ __('failures.empty.description') }}</p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ __('failures.heading', ['count' => $total]) }}</x-slot>
            <x-slot name="description">{{ __('failures.subheading') }}</x-slot>

            <div class="kf-list">
                @foreach ($visible as $group)
                    @php($item = $group['item'])

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

                                @if ($group['count'] > 1)
                                    <span class="kf-times">{{ __('failures.times', ['count' => $group['count']]) }}</span>
                                @endif
                            </div>

                            <p class="kf-title">{{ $item->title }}</p>
                            <p class="kf-explanation">{{ $item->explanation }}</p>

                            {{-- The provider's own words and the exact times, behind
                                 «Λεπτομέρειες»: the thing you want when you are
                                 about to ask somebody else for help, and noise
                                 until then. --}}
                            <details class="kf-more">
                                <summary>{{ __('failures.details') }}</summary>

                                <ul class="kf-times-list">
                                    @foreach ($group['all'] as $one)
                                        <li>
                                            <time datetime="{{ $one->failedAt->toIso8601String() }}">{{ $one->failedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</time>
                                            @if ($one->bookingReference !== null && $one->bookingReference !== $item->bookingReference)
                                                · {{ $one->bookingReference }}
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($item->detail !== null && $item->detail !== '')
                                    <p class="kf-detail">{!! $technical(\Illuminate\Support\Str::limit($item->detail, 600)) !!}</p>
                                @endif
                            </details>
                        </div>

                        <div class="kf-when">
                            <time datetime="{{ $item->failedAt->toIso8601String() }}"
                                  title="{{ $item->failedAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}">
                                {{ $item->failedAt->diffForHumans() }}
                            </time>

                            @if ($item->retryable)
                                <x-filament::button
                                    size="sm"
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

            @if (count($groups) > count($visible))
                <div class="kf-foot">
                    <span>{{ __('failures.showing', ['shown' => count($visible), 'total' => count($groups)]) }}</span>

                    <x-filament::button color="gray" wire:click="showMore" icon="heroicon-m-chevron-down">
                        {{ __('failures.more') }}
                    </x-filament::button>
                </div>
            @endif
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

        .kf-badges { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-bottom: .3rem; }

        .kf-ref {
            font-family: ui-monospace, monospace; font-size: .72rem;
            color: rgb(var(--gray-500));
        }

        .kf-title { font-size: .9rem; font-weight: 600; margin: 0; }
        .kf-explanation { font-size: .82rem; color: rgb(var(--gray-600)); margin: .15rem 0 0; }

        .kf-times {
            font-size: .72rem; font-weight: 600; color: rgb(var(--gray-600));
            background: rgb(var(--gray-100)); border-radius: 999px; padding: .05rem .5rem;
        }

        /* «Λεπτομέρειες»: a quiet disclosure, 44 px tall to the thumb. */
        .kf-more { margin: .35rem 0 0; font-size: .78rem; color: rgb(var(--gray-600)); }
        .kf-more > summary {
            display: inline-flex; align-items: center; min-height: 2.75rem;
            cursor: pointer; font-weight: 600; color: rgb(var(--primary-600));
            list-style: none;
        }
        .kf-more > summary::-webkit-details-marker { display: none; }
        .kf-more > summary::after { content: '▾'; margin-left: .35rem; font-size: .7rem; }
        .kf-more[open] > summary::after { content: '▴'; }
        .kf-more > summary:focus-visible { outline: 2px solid rgb(var(--primary-600)); outline-offset: 2px; border-radius: .25rem; }
        .kf-times-list { margin: 0; padding: 0; list-style: none; font-variant-numeric: tabular-nums; }

        .kf-detail {
            font-family: ui-monospace, monospace; font-size: .72rem;
            color: rgb(var(--gray-600)); margin: .4rem 0 0;
            overflow-wrap: anywhere;
        }

        .kf-foot {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
            margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgb(var(--gray-200));
            font-size: .8rem; color: rgb(var(--gray-600));
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

        /* --- dark ------------------------------------------------------------
           This page is read when something has gone wrong and somebody is trying
           to find out what. The three tiers of type — what happened, the
           explanation, the raw detail — have to keep their order, and on a dark
           ground `--gray-600` explanation text sat below the `--gray-400` detail
           it is supposed to outrank. Inverting the ramp puts them back in order.

           The rule between rows goes to white at eight per cent for the same
           reason it does elsewhere: a `--gray-200` hairline is a bright line. */
        .dark .kf-row,
        .dark .kf-foot { border-color: rgba(255, 255, 255, .08); }
        .dark .kf-times { color: rgb(var(--gray-200)); background: rgba(255, 255, 255, .08); }
        .dark .kf-more,
        .dark .kf-foot { color: rgb(var(--gray-300)); }
        .dark .kf-more > summary { color: rgb(var(--primary-400)); }
        .dark .kf-explanation { color: rgb(var(--gray-300)); }
        .dark .kf-ref,
        .dark .kf-when,
        .dark .kf-empty-detail { color: rgb(var(--gray-400)); }
        .dark .kf-detail { color: rgb(var(--gray-400)); }
    </style>
</x-filament-panels::page>
