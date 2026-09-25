{{--
    «Περίοδοι»: which of the operator's periods this trip prices differently
    (product owner, 2026-09-24, from docs/mockups/pricing-flow.html).

    A tick per period, and «Όλο τον χρόνο» always ticked — a date outside every
    period is priced from it. Ticking adds the period's column to the table
    below at once; nothing is saved until «Αποθήκευση τιμών». «Νέα περίοδος»
    makes one here, ticked, without leaving the trip.
--}}
@php
    /** @var \App\Filament\App\Resources\ProductResource\Pages\EditProduct $page */
    $page = $getLivewire();
@endphp

@if ($page->hasPriceTable())
    @php($periods = $page->priceTable()->periods)

    <div class="kpp">
        <div class="kpp-list">
            <label class="kpp-item is-fixed">
                <input type="checkbox" checked disabled>
                <span>
                    <b>{{ __('pricing.on_product.season.default') }}</b>
                    <small>{{ __('pricing.price_table.no_period') }}</small>
                </span>
            </label>

            @foreach ($periods as $period)
                <label class="kpp-item" wire:key="period-{{ $period['id'] }}">
                    <input type="checkbox" @checked($period['ticked'])
                           wire:click="togglePriceSeason({{ $period['id'] }})">
                    <span>
                        <b>{{ $period['label'] }}</b>
                        <small>{{ $period['dates'] }}</small>
                    </span>
                </label>
            @endforeach
        </div>

        @if ($page->priceNewPeriodOpen)
            <div class="kpp-new">
                <label>
                    <span>{{ __('pricing.periods.new.name') }}</span>
                    <input type="text" wire:model="priceNewPeriod.name" maxlength="60">
                </label>
                <label>
                    <span>{{ __('pricing.periods.new.from') }}</span>
                    <input type="date" wire:model="priceNewPeriod.from">
                </label>
                <label>
                    <span>{{ __('pricing.periods.new.to') }}</span>
                    <input type="date" wire:model="priceNewPeriod.to">
                </label>
                <div class="kpp-new-actions">
                    <x-filament::button type="button" size="sm" wire:click="addPricePeriod" wire:target="addPricePeriod" wire:loading.attr="disabled">
                        {{ __('pricing.periods.new.add') }}
                    </x-filament::button>
                    <x-filament::button type="button" size="sm" color="gray" wire:click="$set('priceNewPeriodOpen', false)">
                        {{ __('pricing.periods.new.cancel') }}
                    </x-filament::button>
                </div>
                @error('priceNewPeriod.name') <p class="kpp-error">{{ $message }}</p> @enderror
                @error('priceNewPeriod.to') <p class="kpp-error">{{ $message }}</p> @enderror
            </div>
        @else
            <button type="button" class="kpp-add" wire:click="$set('priceNewPeriodOpen', true)">
                + {{ __('pricing.periods.new.open') }}
            </button>
        @endif
    </div>

    <style>
        .kpp { display: grid; gap: .9rem; }
        .kpp-list { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
        .kpp-item { display: flex; gap: .65rem; align-items: flex-start; padding: .7rem .85rem; border: 1px solid rgb(var(--gray-200)); border-radius: .75rem; background: #fff; cursor: pointer; }
        .kpp-item:has(input:checked) { border-color: rgb(var(--primary-600)); background: rgb(var(--primary-50)); }
        .kpp-item.is-fixed { cursor: default; }
        .kpp-item input { margin-top: .2rem; border-radius: .3rem; color: rgb(var(--primary-600)); }
        .kpp-item b { display: block; font-size: .9375rem; font-weight: 600; color: rgb(var(--gray-950)); }
        .kpp-item small { display: block; font-size: .8rem; color: rgb(var(--gray-500)); }
        .kpp-add { justify-self: start; min-height: 2.25rem; font-size: .875rem; font-weight: 600; color: rgb(var(--primary-600)); }
        .kpp-new { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); padding: .9rem; border: 1px solid rgb(var(--gray-200)); border-radius: .75rem; background: rgb(var(--gray-50)); }
        @media (min-width: 768px) { .kpp-new { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr); } }
        .kpp-new label { display: grid; gap: .3rem; font-size: .875rem; font-weight: 600; }
        .kpp-new input { border-radius: .5rem; border: 1px solid rgb(var(--gray-300)); padding: .45rem .6rem; font-weight: 400; }
        .kpp-new-actions { display: flex; gap: .5rem; grid-column: 1 / -1; }
        .kpp-error { grid-column: 1 / -1; margin: 0; font-size: .8rem; color: rgb(var(--danger-600)); }
        .dark .kpp-item { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
        .dark .kpp-item:has(input:checked) { border-color: rgb(var(--primary-400)); background: rgb(var(--primary-400) / .1); }
        .dark .kpp-item b { color: #fff; }
        .dark .kpp-new { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
        .dark .kpp-new input { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .15); }
    </style>
@endif
