{{--
    «Χρειάζονται προσοχή» (#130, OPS-1).

    Decisions, not failures. Since 17 September every row is a box that opens
    the screen where it is dealt with, with its button saying what that is,
    and a call button where a phone call is the fix. The two yes-or-no rows —
    a sailing short of its minimum, a balance past due — carry their answers
    as buttons instead, each confirmed in a dialog. Five first, «Όλα (N)» for
    the rest. See `NeedsAttention` for which item leads where.

    Ordered by deadline and never by severity: a red badge above a boat leaving
    in three hours makes a list that has to be read in full to be used.

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
@php
    $rows = $this->getRows();
    $total = count($rows);
    $shown = $this->withAnswered(
        $this->showAll ? $rows : array_slice($rows, 0, \App\Filament\App\Widgets\NeedsAttention::FIRST),
    );
@endphp

<x-filament-widgets::widget id="ka-attention">
    {{-- Kept open while the last answer fades, or it would vanish mid-click. --}}
    @if ($total > 0 || $this->answered !== [])
        <x-filament::section>
            <x-slot name="heading">{{ __('attention.heading') }}</x-slot>

            <x-slot name="description">{{ __('attention.subheading') }}</x-slot>

            <ul class="ka-list">
                @foreach ($shown as $row)
                    @if ($row['done'] ?? false)
                        {{-- Answered: the outcome where the row stood, then gone. --}}
                        <li class="ka-item is-done" wire:key="attention-done-{{ $row['key'] }}"
                            x-data="{ gone: false }"
                            x-init="setTimeout(() => { gone = true; setTimeout(() => $wire.forgetAnswer(@js($row['key'])), 400) }, 3500)"
                            x-bind:class="{ 'is-gone': gone }">
                            <span class="ka-done">
                                <x-filament::icon icon="heroicon-m-check-circle" class="ka-done-ic" />
                                <span>{{ $row['outcome'] }}</span>
                            </span>
                            <span class="ka-title">{{ $row['title'] }}</span>
                        </li>
                        @continue
                    @endif

                    @php($item = $row['item'])
                    <li class="ka-item" wire:key="attention-{{ $item->key }}">
                        <a class="ka-main" href="{{ $row['url'] }}">
                            <span class="ka-top">
                                <x-filament::badge :color="$item->severity->color()" class="ka-badge">
                                    {{ __('attention.severity.' . $item->severity->value) }}
                                </x-filament::badge>

                                <span class="ka-when">
                                    @if ($item->deadline)
                                        <span title="{{ $item->deadline->toDayDateTimeString() }}"
                                              @class(['is-overdue' => $item->isOverdue()])>
                                            {{ $item->deadline->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="is-undated">{{ __('attention.no_deadline') }}</span>
                                    @endif
                                </span>
                            </span>

                            <span class="ka-title">{{ $item->title }}</span>
                            <span class="ka-detail">{{ $item->detail }}</span>
                        </a>

                        <div class="ka-actions">
                            @if ($row['decide'] === 'departure')
                                {{-- The two answers to «short: run it or cancel it?» --}}
                                {{ ($this->sailAnywayAction)(['departure' => $row['id']]) }}
                                {{ ($this->cancelDepartureAction)(['departure' => $row['id']]) }}
                            @elseif ($row['decide'] === 'balance')
                                {{ ($this->markPaidAction)(['booking' => $row['id']]) }}
                            @elseif ($row['decide'] === 'refund')
                                {{-- Cash or a transfer to hand back: confirmed once it has gone. --}}
                                {{ ($this->markRefundedAction)(['refund' => $row['id']]) }}
                            @else
                                <a class="ka-btn is-primary" href="{{ $row['url'] }}">
                                    <span>{{ $row['action'] }}</span>
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="ka-btn-ic" />
                                </a>
                            @endif

                            @if ($row['phone'])
                                <a class="ka-btn" href="tel:{{ preg_replace('/[^0-9+]/', '', $row['phone']) }}">
                                    <x-filament::icon icon="heroicon-m-phone" class="ka-btn-ic" />
                                    <span>{{ __('attention.actions.call') }}</span>
                                </a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($total > \App\Filament\App\Widgets\NeedsAttention::FIRST)
                <button type="button" class="ka-more" wire:click="toggleAll">
                    {{ $this->showAll ? __('attention.actions.fewer') : __('attention.actions.all', ['count' => $total]) }}
                </button>
            @endif
        </x-filament::section>
    @endif

    <x-filament-actions::modals />

    <style>
        #ka-attention a { text-decoration: none; }

        .ka-list { display: grid; gap: .6rem; }

        .ka-item {
            display: grid; gap: .6rem;
            padding: .85rem .9rem;
            border: 1px solid #E1E8F2; border-radius: 1rem; background: #fff;
        }

        .ka-main { display: grid; gap: .2rem; color: inherit; }
        .ka-top { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .15rem; }

        .ka-title { font-size: .95rem; font-weight: 600; color: rgb(var(--gray-800)); }
        .ka-detail { font-size: .85rem; color: rgb(var(--gray-500)); }

        .ka-when { font-size: .78rem; color: rgb(var(--gray-500)); white-space: nowrap; }
        .ka-when .is-overdue { color: rgb(var(--danger-600)); font-weight: 600; }
        .ka-when .is-undated { color: rgb(var(--gray-400)); }

        .ka-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        /* Filament's own buttons for the answers, the same height as ours. */
        .ka-actions .fi-btn { min-height: 2.75rem; border-radius: .7rem; }
        @media (max-width: 767.98px) {
            /* Side by side and equal on a phone, never one per line. */
            .ka-actions { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); }
            .ka-actions > * { justify-content: center; }
            .ka-actions .fi-btn { padding-inline: .5rem; font-size: .85rem; white-space: normal; line-height: 1.15; }
        }
        .ka-btn {
            display: inline-flex; align-items: center; gap: .4rem;
            min-height: 2.75rem; padding: 0 .9rem; border-radius: .7rem;
            border: 1px solid #D5E0EE; background: #fff; color: #0F2E57;
            font-size: .9rem; font-weight: 600;
        }
        .ka-btn.is-primary { background: #0F2E57; border-color: #0F2E57; color: #fff; }
        .ka-btn:hover { filter: brightness(1.08); }
        .ka-btn-ic { width: 1.1rem; height: 1.1rem; }

        .ka-item.is-done {
            display: grid; gap: .2rem; grid-template-columns: none;
            border-color: #CBE8D6; background: #F1FAF4;
            transition: opacity .4s ease;
        }
        .ka-item.is-gone { opacity: 0; }
        .ka-done { display: inline-flex; align-items: center; gap: .35rem; font-size: .85rem; font-weight: 600; color: #1F7A45; }
        .ka-done-ic { width: 1.1rem; height: 1.1rem; }
        .ka-item.is-done .ka-title { color: rgb(var(--gray-500)); }

        .ka-more {
            margin-top: .75rem; min-height: 2.75rem; padding: 0 1rem; border-radius: .7rem;
            background: #EAF1FA; color: #0F2E57; font-weight: 600; font-size: .9rem;
        }

        @media (min-width: 768px) {
            .ka-item { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
            .ka-actions { justify-content: flex-end; }
        }

        /* --- dark ------------------------------------------------------------
           Each shade moves to its opposite number on the ramp, so the title and
           the overdue marker keep their contrast. */
        .dark .ka-item { border-color: rgba(255, 255, 255, .1); background: rgb(var(--gray-900)); }
        .dark .ka-title { color: rgb(var(--gray-100)); }
        .dark .ka-detail,
        .dark .ka-when { color: rgb(var(--gray-400)); }
        .dark .ka-when .is-undated { color: rgb(var(--gray-500)); }
        .dark .ka-when .is-overdue { color: rgb(var(--danger-400)); }
        .dark .ka-item.is-done { border-color: rgba(74, 222, 128, .25); background: rgba(74, 222, 128, .06); }
        .dark .ka-done { color: rgb(134, 239, 172); }
        .dark .ka-btn { background: transparent; border-color: rgba(255, 255, 255, .2); color: #fff; }
    </style>
</x-filament-widgets::widget>
