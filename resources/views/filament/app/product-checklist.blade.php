{{--
    CAT-15's publish gate, as one row rather than six stacked paragraphs.

    The old shape put a `Placeholder` per requirement, each with its own label
    and its own line of prose, which produced six rows of text for a question
    with a one-word answer. An operator scanning it could not tell at a glance
    how far off publishing they were — which is the only thing the section is
    for.

    Now: a chip per requirement, ticked or not, wrapping on a narrow screen. The
    sentence explaining *how* to fix an unmet one is the tooltip, because it is
    the thing you want after you have found the red one and not before.
--}}
@php
    $unsaved = $record === null || ! $record->exists;
@endphp

@if ($unsaved)
    <p class="kc-unsaved">{{ __('catalog.product.checklist.unsaved') }}</p>
@else
    <div class="kc-row">
        @foreach ($items as $item)
            <span @class(['kc-chip', 'is-met' => $item['met']])
                  @if (! $item['met']) title="{{ $item['unmet'] }}" @endif>
                <x-filament::icon
                    :icon="$item['met'] ? 'heroicon-m-check-circle' : 'heroicon-o-exclamation-circle'"
                    class="kc-icon"
                />
                <span>{{ $item['label'] }}</span>
            </span>
        @endforeach
    </div>

    @if ($remaining > 0)
        {{-- The count, because six chips of which two are red still needs
             somebody to notice the two. --}}
        <p class="kc-summary">{{ trans_choice('catalog.product.checklist.remaining', $remaining, ['count' => $remaining]) }}</p>
    @else
        <p class="kc-summary kc-ready">{{ __('catalog.product.checklist.ready') }}</p>
    @endif
@endif

<style>
    .kc-row { display: flex; flex-wrap: wrap; gap: .45rem; }

    .kc-chip {
        display: inline-flex; align-items: center; gap: .3rem;
        padding: .3rem .6rem; border-radius: 999px;
        font-size: .8rem; line-height: 1.2;
        background: rgb(var(--danger-50));
        color: rgb(var(--danger-700));
        border: 1px solid rgb(var(--danger-200));
        cursor: default;
    }

    .kc-chip.is-met {
        background: rgb(var(--gray-50));
        color: rgb(var(--gray-500));
        border-color: rgb(var(--gray-200));
    }

    /* Grey when met rather than green: six green chips is a wall of colour that
       says nothing, and the one thing this row exists to make findable is the
       chip that is *not* done. */
    .kc-icon { width: .95rem; height: .95rem; flex: none; }

    .kc-summary { margin: .7rem 0 0; font-size: .82rem; color: rgb(var(--danger-600)); }
    .kc-summary.kc-ready { color: rgb(var(--success-600)); }
    .kc-unsaved { font-size: .85rem; color: rgb(var(--gray-500)); margin: 0; }
</style>
