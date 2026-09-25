{{--
    «Προκαταβολή και προθεσμίες», once for the trip (product owner, 2026-09-24).

    They lived on each «τιμοκατάλογος», so four periods meant setting them four
    times. Here they are set once and hold in every period; a period that
    should differ says so from the «⋯» on its column in the table, and is
    listed under these fields so the exception is never invisible.

    Saved with the table, on the same button.
--}}
@php
    /** @var \App\Filament\App\Resources\ProductResource\Pages\EditProduct $page */
    $page = $getLivewire();
    $type = $page->priceTerms['deposit_type'] ?? 'none';
@endphp

@if ($page->hasPriceTable())
    @php($differs = array_filter($page->priceTable()->columns, static fn (array $c): bool => ! $c['follows']))

    <div class="kpt2">
        <div class="kpt2-grid">
            <div class="kpt2-field kpt2-wide">
                <span class="kpt2-label">{{ __('pricing.periods.terms.deposit') }}</span>
                <div class="kpt2-seg" role="radiogroup" aria-label="{{ __('pricing.periods.terms.deposit') }}">
                    @foreach (\App\Enums\DepositType::cases() as $case)
                        <label @class(['is-on' => $type === $case->value])>
                            <input type="radio" class="sr-only" value="{{ $case->value }}" wire:model.live="priceTerms.deposit_type">
                            {{ $case->label() }}
                        </label>
                    @endforeach
                </div>
            </div>

            @if ($type === 'percent')
                <label class="kpt2-field">
                    <span class="kpt2-label">{{ __('pricing.periods.terms.deposit_percent') }}</span>
                    <span class="kpt2-input"><input type="number" min="1" max="100" wire:model.blur="priceTerms.deposit_percent"><em>%</em></span>
                    @error('priceTerms.deposit_percent') <span class="kpt2-error">{{ $message }}</span> @enderror
                </label>
            @elseif ($type === 'fixed')
                <label class="kpt2-field">
                    <span class="kpt2-label">{{ __('pricing.periods.terms.deposit_fixed') }}</span>
                    <span class="kpt2-input"><em>€</em><input type="text" inputmode="decimal" wire:model.blur="priceTerms.deposit_fixed"></span>
                    @error('priceTerms.deposit_fixed') <span class="kpt2-error">{{ $message }}</span> @enderror
                </label>
            @endif

            <label class="kpt2-field">
                <span class="kpt2-label">{{ __('pricing.periods.terms.lead') }}</span>
                <span class="kpt2-input"><input type="number" min="0" wire:model.blur="priceTerms.min_lead_time_hours"><em>{{ __('pricing.periods.terms.hours') }}</em></span>
                <span class="kpt2-help">{{ __('pricing.periods.terms.lead_help') }}</span>
            </label>

            <label class="kpt2-field">
                <span class="kpt2-label">{{ __('pricing.periods.terms.advance') }}</span>
                <span class="kpt2-input"><input type="number" min="1" wire:model.blur="priceTerms.max_advance_days"><em>{{ __('pricing.periods.terms.days') }}</em></span>
                <span class="kpt2-help">{{ __('pricing.periods.terms.advance_help') }}</span>
            </label>
        </div>

        @if ($differs !== [])
            <ul class="kpt2-differs">
                @foreach ($differs as $column)
                    <li>{{ __('pricing.periods.terms.differs', ['period' => $column['label']]) }}</li>
                @endforeach
            </ul>
        @endif

        <div class="kpt2-actions">
            <x-filament::button type="button" wire:click="savePrices" wire:loading.attr="disabled" wire:target="savePrices">
                {{ __('pricing.price_table.save') }}
            </x-filament::button>
            @if ($page->priceDirty)
                <span class="kpt2-dirty">{{ __('pricing.price_table.unsaved') }}</span>
            @endif
        </div>
    </div>

    <style>
        .kpt2 { display: grid; gap: 1rem; }
        .kpt2-grid { display: grid; gap: 1rem 1.25rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 768px) { .kpt2-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } .kpt2-wide { grid-column: 1 / -1; } }
        .kpt2-field { display: grid; gap: .35rem; align-content: start; }
        .kpt2-label { font-size: .875rem; font-weight: 600; color: rgb(var(--gray-950)); }
        .kpt2-help { font-size: .8rem; color: rgb(var(--gray-500)); }
        .kpt2-seg { display: inline-flex; flex-wrap: wrap; justify-self: start; border: 1px solid rgb(var(--gray-300)); border-radius: .6rem; overflow: hidden; }
        .kpt2-seg label { padding: .45rem .9rem; font-size: .875rem; font-weight: 600; color: rgb(var(--gray-600)); cursor: pointer; }
        .kpt2-seg label + label { border-left: 1px solid rgb(var(--gray-300)); }
        .kpt2-seg label.is-on { background: #0F2E57; color: #fff; }
        .kpt2-seg label:focus-within { outline: 2px solid rgb(var(--primary-600)); outline-offset: -2px; }
        .kpt2-input { display: flex; align-items: center; gap: .4rem; padding: 0 .6rem; min-height: 2.5rem; border: 1px solid rgb(var(--gray-300)); border-radius: .5rem; background: #fff; }
        .kpt2-input:focus-within { border-color: rgb(var(--primary-600)); box-shadow: 0 0 0 1px rgb(var(--primary-600)); }
        .kpt2-input input { flex: 1; min-width: 0; border: 0; padding: 0; background: transparent; box-shadow: none; font-variant-numeric: tabular-nums; }
        .kpt2-input input:focus { outline: none; box-shadow: none; }
        .kpt2-input em { font-style: normal; font-size: .8rem; color: rgb(var(--gray-500)); }
        .kpt2-error { font-size: .75rem; color: rgb(var(--danger-600)); }
        .kpt2-differs { margin: 0; padding: .7rem .9rem; list-style: none; border-radius: .6rem; background: rgb(var(--warning-50)); color: rgb(var(--warning-800)); font-size: .85rem; display: grid; gap: .2rem; }
        .kpt2-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .kpt2-dirty { font-size: .8rem; color: rgb(var(--warning-700)); }
        .dark .kpt2-label { color: #fff; }
        .dark .kpt2-input { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .15); }
        .dark .kpt2-seg { border-color: rgba(255, 255, 255, .15); }
        .dark .kpt2-seg label.is-on { background: rgb(var(--primary-400)); color: rgb(var(--primary-950)); }
        .dark .kpt2-differs { background: rgb(var(--warning-400) / .12); color: rgb(var(--warning-300)); }
    </style>
@endif
