{{--
    A trip's prices as one table in euros (product owner, 2026-09-17).

    Rows are the age bands, columns the periods, and every cell is what one
    person pays. The state lives on the edit page (`ManagesPriceTable`), not in
    the trip form, and saves on its own button; see that trait for why.

    Beside it, «Τι πληρώνει ο επισκέπτης»: counters and a period, totalled from
    the cells as typed, so an operator sees what a family pays before saving.
--}}
@php
    /** @var \App\Filament\App\Resources\ProductResource\Pages\EditProduct $page */
    $page = $getLivewire();
@endphp

@if (! $page->hasPriceTable())
    <p class="kpt-muted">{{ __('pricing.price_table.save_trip_first') }}</p>
@else
    @php
        $table = $page->priceTable();
    @endphp

    @php($missing = $page->missingPriceCount())
    @if ($missing > 0)
        {{-- Said once, in words, above the table: the red cells are why. --}}
        <div class="kpt-missing" role="alert">
            {{ trans_choice('pricing.price_table.missing_banner', $missing, ['count' => $missing]) }}
        </div>
    @endif

    <div class="kpt-wrap">
        <div class="kpt-main">
            <div class="kpt-scroll">
                <table class="kpt-table">
                    <thead>
                        <tr>
                            <th scope="col" class="kpt-bandcol">{{ __('pricing.price_table.band') }}</th>
                            @foreach ($table->columns as $column)
                                <th scope="col">
                                    <span class="kpt-head">
                                        <span>
                                            <span class="kpt-period">{{ $column['label'] }}</span>
                                            <span class="kpt-dates">{{ $column['dates'] }}</span>
                                        </span>
                                        {{-- A period's own deposit and deadlines. Only once it
                                             is saved: a column just ticked has no plan yet. --}}
                                        @if ($column['season_id'] !== null && $column['plan_id'] !== null)
                                            <button type="button" class="kpt-more"
                                                    wire:click="mountAction('periodTerms', { plan: {{ $column['plan_id'] }} })"
                                                    aria-label="{{ __('pricing.periods.terms.open') }}: {{ $column['label'] }}"
                                                    title="{{ __('pricing.periods.terms.open') }}">⋯</button>
                                        @endif
                                    </span>
                                    @if ($column['season_id'] !== null && $column['plan_id'] === null)
                                        <span class="kpt-tag">{{ __('pricing.periods.new_column') }}</span>
                                    @elseif (! $column['follows'])
                                        <span class="kpt-tag is-own">{{ __('pricing.periods.terms.own_tag') }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table->rows as $row)
                            <tr wire:key="price-row-{{ $row['key'] }}">
                                <th scope="row" class="kpt-bandcol">
                                    <span class="kpt-band">{{ $row['label'] }}</span>
                                    <span class="kpt-ages">
                                        {{ $row['ages'] }}
                                        @if ($row['is_base']) · {{ __('pricing.price_table.base') }} @endif
                                        @unless ($row['takes_seat']) · {{ __('pricing.price_table.no_seat') }} @endunless
                                    </span>

                                </th>

                                @foreach ($table->columns as $column)
                                    @php($cell = "priceCells.{$row['key']}.{$column['key']}")
                                    <td>
                                        @php($empty = trim((string) data_get($page, $cell)) === '')
                                        <label class="kpt-input @error($cell) is-error @enderror @if ($empty) is-missing @endif">
                                            <span aria-hidden="true">€</span>
                                            <input type="text" inputmode="decimal" autocomplete="off"
                                                   aria-label="{{ $row['label'] }} · {{ $column['label'] }}"
                                                   wire:model.blur="{{ $cell }}">
                                        </label>
                                        @error($cell)
                                            <span class="kpt-error">{{ $message }}</span>
                                        @enderror
                                        @if ($empty)
                                            <span class="kpt-error">{{ __('pricing.price_table.missing_cell') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('priceCells')
                <p class="kpt-error kpt-block">{{ $message }}</p>
            @enderror

            <div class="kpt-actions">
                <x-filament::button type="button" wire:click="savePrices" wire:loading.attr="disabled" wire:target="savePrices">
                    {{ __('pricing.price_table.save') }}
                </x-filament::button>

                @if ($page->priceDirty)
                    <span class="kpt-dirty">{{ __('pricing.price_table.unsaved') }}</span>
                @endif
            </div>

        </div>

        <aside class="kpt-guest" aria-label="{{ __('pricing.price_table.guest.heading') }}">
            <h4>{{ __('pricing.price_table.guest.heading') }}</h4>

            @if (count($table->columns) > 1)
                <label class="kpt-select">
                    <span>{{ __('pricing.price_table.guest.period') }}</span>
                    <select wire:model.live="pricePreviewColumn">
                        @foreach ($table->columns as $column)
                            <option value="{{ $column['key'] }}">{{ $column['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @foreach ($table->rows as $row)
                <div class="kpt-pax" wire:key="price-pax-{{ $row['key'] }}">
                    <span>{{ $row['label'] }}</span>
                    <span class="kpt-counter">
                        <button type="button" wire:click="changePricePax('{{ $row['key'] }}', -1)"
                                aria-label="{{ __('pricing.price_table.guest.less') }}: {{ $row['label'] }}">−</button>
                        <b>{{ $page->pricePax[$row['key']] ?? 0 }}</b>
                        <button type="button" wire:click="changePricePax('{{ $row['key'] }}', 1)"
                                aria-label="{{ __('pricing.price_table.guest.more') }}: {{ $row['label'] }}">+</button>
                    </span>
                </div>
            @endforeach

            <div class="kpt-total">
                <span>{{ __('pricing.price_table.guest.total') }}</span>
                <b>{{ $page->priceGuestTotal() }}</b>
            </div>
        </aside>
    </div>
@endif

<style>
    .kpt-wrap { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 1280px) { .kpt-wrap { grid-template-columns: minmax(0, 1fr) 15rem; } }

    .kpt-scroll { overflow-x: auto; }
    .kpt-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .kpt-table th, .kpt-table td { padding: .6rem .5rem; border-bottom: 1px solid rgb(var(--gray-200)); text-align: start; vertical-align: top; }
    .kpt-table thead th { font-weight: 600; }
    .kpt-bandcol { min-width: 13rem; }
    .kpt-period, .kpt-band { display: block; font-weight: 600; color: rgb(var(--gray-950)); }
    .kpt-dates, .kpt-ages { display: block; font-size: .75rem; font-weight: 400; color: rgb(var(--gray-500)); }
    .kpt-tag { display: inline-block; margin-top: .2rem; font-size: .7rem; padding: .05rem .4rem; border-radius: 999px; background: rgb(var(--primary-50)); color: rgb(var(--primary-700)); }
    .kpt-tag.is-own { background: rgb(var(--warning-50)); color: rgb(var(--warning-800)); }
    .kpt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
    .kpt-more { flex: none; width: 2rem; height: 2rem; margin: -.3rem -.2rem 0 0; border-radius: .5rem; font-size: 1.1rem; line-height: 1; color: rgb(var(--gray-500)); }
    .kpt-more:hover { background: rgb(var(--gray-100)); color: rgb(var(--gray-900)); }
    .kpt-more:focus-visible { outline: 2px solid rgb(var(--primary-600)); outline-offset: 1px; }

    .kpt-input { display: flex; align-items: center; gap: .3rem; min-width: 6.5rem; padding: .3rem .5rem; border-radius: .5rem; border: 1px solid rgb(var(--gray-300)); background: #fff; }
    .kpt-input:focus-within { border-color: rgb(var(--primary-600)); box-shadow: 0 0 0 1px rgb(var(--primary-600)); }
    .kpt-input.is-error { border-color: rgb(var(--danger-600)); }
    .kpt-input.is-missing { border-color: rgb(var(--danger-500)); background: rgb(var(--danger-50)); }
    .kpt-missing { margin-bottom: .75rem; padding: .6rem .8rem; border-radius: .5rem; background: rgb(var(--danger-50)); color: rgb(var(--danger-700)); font-size: .875rem; font-weight: 500; }
    .kpt-input span { color: rgb(var(--gray-400)); }
    .kpt-input input { width: 100%; border: 0; padding: 0; background: transparent; font-variant-numeric: tabular-nums; text-align: end; box-shadow: none; }
    .kpt-input input:focus { outline: none; box-shadow: none; }

    .kpt-error { display: block; margin-top: .25rem; font-size: .75rem; color: rgb(var(--danger-600)); }
    .kpt-block { margin: .5rem 0 0; }
    .kpt-muted { margin: .6rem 0 0; font-size: .8rem; color: rgb(var(--gray-500)); }
    .kpt-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: .9rem; }
    .kpt-dirty { font-size: .8rem; color: rgb(var(--warning-700)); }

    .kpt-guest { align-self: start; padding: 1rem; border-radius: .75rem; background: rgb(var(--gray-50)); border: 1px solid rgb(var(--gray-200)); font-size: .875rem; }
    .kpt-guest h4 { margin: 0 0 .75rem; font-weight: 600; }
    .kpt-select { display: grid; gap: .25rem; margin-bottom: .75rem; font-size: .75rem; color: rgb(var(--gray-500)); }
    .kpt-select select { border-radius: .5rem; border: 1px solid rgb(var(--gray-300)); font-size: .875rem; padding: .3rem 2rem .3rem .5rem; }
    .kpt-pax { display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: .35rem 0; }
    .kpt-counter { display: inline-flex; align-items: center; gap: .5rem; }
    .kpt-counter button { width: 1.8rem; height: 1.8rem; border-radius: 999px; border: 1px solid rgb(var(--gray-300)); background: #fff; }
    .kpt-counter b { min-width: 1.2rem; text-align: center; font-variant-numeric: tabular-nums; }
    .kpt-total { display: flex; justify-content: space-between; margin-top: .6rem; padding-top: .6rem; border-top: 1px solid rgb(var(--gray-200)); }
    .kpt-total b { font-size: 1.1rem; font-variant-numeric: tabular-nums; }

    .dark .kpt-period, .dark .kpt-band { color: #fff; }
    .dark .kpt-table th, .dark .kpt-table td { border-color: rgba(255, 255, 255, .1); }
    .dark .kpt-input, .dark .kpt-counter button { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .15); color: rgb(var(--gray-200)); }
    .dark .kpt-guest { background: rgba(255, 255, 255, .03); border-color: rgba(255, 255, 255, .1); }
</style>
