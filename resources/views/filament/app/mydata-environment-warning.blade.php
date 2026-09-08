{{--
    MYD-11's visible answer to "am I really filing these?"

    Shown only when a document on this operator's account actually went to the
    development endpoint. A permanent banner is furniture; one that appears is a
    fact.
--}}
<div class="kmw">
    <x-filament::icon icon="heroicon-o-beaker" class="kmw-icon" />
    <p>{{ __('mydata.environment.warning') }}</p>
</div>

<style>
    .kmw {
        display: flex;
        gap: .6rem;
        align-items: flex-start;
        padding: .75rem 1rem;
        border-radius: .5rem;
        background: rgb(var(--warning-50));
        border: 1px solid rgb(var(--warning-300));
        color: rgb(var(--warning-800));
        font-size: .875rem;
        line-height: 1.5;
    }

    .kmw-icon { width: 1.15rem; height: 1.15rem; flex: none; margin-top: .1rem; }
    .kmw p { margin: 0; }
</style>
