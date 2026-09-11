{{--
    The way back to «Ρυθμίσεις», at the top of every screen a card leads to.

    A render hook rather than a breadcrumb on each screen: eight of the fifteen
    are plain pages with no breadcrumbs at all, and the seven resources already
    have their own. This is one line in one place, and it looks the same on all
    fifteen.

    Scoped `<style>`, for the reason given in `pages/settings.blade.php`.
--}}
<a href="{{ $url }}" class="ka-hub-back">
    <x-filament::icon icon="heroicon-m-arrow-left" class="ka-hub-back-icon" />
    <span>{{ __('settings_hub.back') }}</span>
</a>

<style>
    .ka-hub-back {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .875rem; font-weight: 500;
        color: rgb(var(--gray-500));
        text-decoration: none;
        margin-bottom: -.5rem;
    }

    .ka-hub-back:hover { color: rgb(var(--primary-600)); }
    .ka-hub-back:focus-visible { outline: 2px solid rgb(var(--primary-600)); outline-offset: 2px; border-radius: .25rem; }
    .ka-hub-back-icon { width: 1rem; height: 1rem; }

    .dark .ka-hub-back { color: rgb(var(--gray-400)); }
    .dark .ka-hub-back:hover { color: rgb(var(--primary-400)); }
</style>
