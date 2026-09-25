{{--
    «Ξέχασα τον κωδικό» (direction Α1, product owner, 2026-09-23, screen 4 of
    `docs/mockups/login-mobile-directions.html`): «‹ Σύνδεση» above the
    heading, then the heading, then one line on what happens next.

    Filament's header puts its subheading — the back link — *under* the
    heading, and has no slot above it. So the header is given nothing and this
    view writes the three lines itself, with Filament's own class names, so the
    sign-in stylesheet (`filament/auth-split.blade.php`) styles them as it
    styles every other heading on these screens. The empty header is hidden
    there, by `:has(.kaiki-auth-back)`.
--}}
<x-filament-panels::page.simple heading="" subheading="">
    <div class="kaiki-auth-intro">
        @if (filament()->hasLogin())
            <a class="kaiki-auth-back" href="{{ filament()->getLoginUrl() }}">
                <span aria-hidden="true">‹</span>
                {{ __('filament-panels::pages/auth/password-reset/request-password-reset.actions.login.label') }}
            </a>
        @endif

        <h1 class="fi-simple-header-heading">{{ $this->getHeading() }}</h1>
        <p class="fi-simple-header-subheading">{{ $this->getSubheading() }}</p>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

    <x-filament-panels::form id="form" wire:submit="request">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
</x-filament-panels::page.simple>
