{{--
    «← Πίσω» and «Επόμενο →» under a tab of a draft trip
    ({@see \App\Filament\App\Resources\ProductResource::stepNav()}).

    Inside the tabs' own Alpine scope, so a button only sets `tab` — the same
    thing clicking the tab at the top does — and scrolls back up to the tabs.
    Nothing is sent to the server: the tabs are one form.
--}}
<div class="ka-step-nav">
    @if ($previous)
        <button type="button" class="ka-step-back"
                x-on:click="tab = @js($previous['key']); $el.closest('.fi-fo-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' })">
            ← {{ __('catalog.product.steps.back', ['tab' => $previous['label']]) }}
        </button>
    @else
        <span></span>
    @endif

    @if ($next)
        <button type="button" class="ka-step-next"
                x-on:click="tab = @js($next['key']); $el.closest('.fi-fo-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' })">
            {{ __('catalog.product.steps.next', ['tab' => $next['label']]) }} →
        </button>
    @else
        <p class="ka-step-last">{{ __('catalog.product.steps.last') }}</p>
    @endif
</div>

@once
    <style>
        .ka-step-nav {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
            padding-block-start: .5rem;
        }
        .ka-step-back, .ka-step-next {
            display: inline-flex; align-items: center; gap: .4rem;
            min-block-size: 2.5rem; padding: .5rem 1rem; border-radius: .5rem; font-weight: 600;
        }
        .ka-step-back { color: rgb(var(--gray-600)); }
        .ka-step-back:hover { background: rgb(var(--gray-100)); }
        .ka-step-next { background: rgb(var(--primary-600)); color: #fff; margin-inline-start: auto; }
        .ka-step-next:hover { background: rgb(var(--primary-500)); }
        .ka-step-last { margin: 0 0 0 auto; font-size: .875rem; color: rgb(var(--gray-600)); }
    </style>
@endonce
