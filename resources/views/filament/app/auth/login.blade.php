{{--
    The operator's sign-in (direction Α1, product owner, 2026-09-23). Filament's
    own `filament-panels::pages.auth.login`, plus two things it has no slot for:

    - the alert above the fields when the email or password was wrong, with
      what to try next — said once, here, rather than in red under a field;
    - a few lines of script for the phone: «Να με θυμάσαι» ticked by default
      below the phone breakpoint, the «Επόμενο» key moving to the password, and
      the «Ξέχασα τον κωδικό» link carrying the email that was typed.

    The layout, including the fixed 230 px band on a phone, is
    `filament/auth-split.blade.php`, which styles every sign-in screen.
--}}
<x-filament-panels::page.simple>
    @if (filament()->hasRegistration())
        <x-slot name="subheading">
            {{ __('filament-panels::pages/auth/login.actions.register.before') }}

            {{ $this->registerAction }}
        </x-slot>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

    @if ($this->credentialsRejected && $errors->has('data.password'))
        <div class="kaiki-login-alert" role="alert">
            <span class="kaiki-login-alert__icon" aria-hidden="true">!</span>
            <div>
                <p class="kaiki-login-alert__title">{{ $errors->first('data.password') }}</p>
                <p class="kaiki-login-alert__hint">{{ __('auth.login.failed_hint') }}</p>
            </div>
        </div>
    @endif

    <x-filament-panels::form
        id="form"
        wire:submit="authenticate"
        @class(['kaiki-login-form', 'kaiki-login-form--rejected' => $this->credentialsRejected])
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}

    <script>
        (function () {
            const form = document.querySelector('.kaiki-login-form');

            if (! form || form.dataset.kaikiReady) {
                return;
            }

            form.dataset.kaikiReady = '1';

            const phone = window.matchMedia('(max-width: 1023.98px)');
            const email = form.querySelector('input[autocomplete="username"]');
            const password = form.querySelector('input[autocomplete="current-password"]');

            /* «Να με θυμάσαι» on by default on a phone. Set on the component's
               state rather than on the box, once Livewire has started — before
               that, `wire:model` would sync the box back to the server's
               `false` as it boots. `false` as the third argument: no request,
               the value simply travels with the sign-in. Only on the first
               load, so it never overrides somebody who has unticked it. */
            const tickRemember = () => {
                const root = form.closest('[wire\\:id]');
                const component = root && window.Livewire?.find(root.getAttribute('wire:id'));

                if (phone.matches && component && component.$get('data.remember') !== true) {
                    component.$set('data.remember', true, false);
                }
            };

            if (window.Livewire?.find && form.closest('[wire\\:id]') && window.Livewire.find(form.closest('[wire\\:id]').getAttribute('wire:id'))) {
                tickRemember();
            } else {
                document.addEventListener('livewire:initialized', tickRemember, { once: true });
            }

            /* «Επόμενο» on the email keyboard: on to the password, rather than
               submitting a form with the password still empty. */
            email?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && password && password.value === '') {
                    event.preventDefault();
                    password.focus();
                }
            });

            /* Whatever email was typed goes to the reset page with the person. */
            form.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-kaiki-forgot]');

                if (! link || ! email || email.value.trim() === '') {
                    return;
                }

                const url = new URL(link.href, window.location.href);
                url.searchParams.set('email', email.value.trim());
                link.href = url.toString();
            });
        })();
    </script>
</x-filament-panels::page.simple>
