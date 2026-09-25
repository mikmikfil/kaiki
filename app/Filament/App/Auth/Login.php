<?php

declare(strict_types=1);

namespace App\Filament\App\Auth;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TextInput\Actions\HidePasswordAction;
use Filament\Forms\Components\TextInput\Actions\ShowPasswordAction;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * The operator's sign-in, built for a phone held in one hand on a quay
 * (product owner, 2026-09-23: direction Α, variant Α1 of
 * `docs/mockups/login-mobile-directions.html`).
 *
 * Filament's own form, with what the phone needs from the markup. The layout —
 * the 230 px band, the calm 48 px fields, the upright-tablet column — is all
 * in `resources/views/filament/auth-split.blade.php`; the desktop (direction C
 * of 2026-09-17) is unchanged.
 *
 * - **The email field asks for the right keyboard and the saved account**:
 *   `type=email inputmode=email autocomplete=username`, and `enterkeyhint=next`.
 * - **The password field is filled by Face ID / the password manager**:
 *   `autocomplete=current-password`, `enterkeyhint=go`, and a «Εμφάνιση» word
 *   rather than only an eye on the phone.
 * - **«Ξέχασα τον κωδικό» twice, one of them hidden.** Beside the password's
 *   label on a desktop, as it has been since 2026-09-08; in the 48 px row next
 *   to «Να με θυμάσαι» on a phone. The stylesheet shows exactly one at each
 *   width, with `display: none`, so the other is out of the tab order and out
 *   of the accessibility tree too.
 * - **A wrong password is said once, above the fields**, not in red under the
 *   email. The email keeps its value; the password is emptied and takes the
 *   focus, so the next attempt starts where the mistake was.
 */
class Login extends BaseLogin
{
    /**
     * @var view-string
     */
    protected static string $view = 'filament.app.auth.login';

    /**
     * True after the credentials were refused, until the next attempt. The
     * view shows the alert from it; the field keeps only its red border.
     */
    public bool $credentialsRejected = false;

    public function authenticate(): ?LoginResponse
    {
        $this->credentialsRejected = false;

        return parent::authenticate();
    }

    protected function throwFailureValidationException(): never
    {
        $this->credentialsRejected = true;

        // Emptied here, not in the browser: the next render sends the field
        // back blank, so a wrong password is never left sitting in the DOM.
        $this->data['password'] = null;

        // After the response lands. `$this->js()` runs once Livewire has
        // morphed the page, which is when the emptied field exists to focus.
        $this->js("document.getElementById('data.password')?.focus()");

        throw ValidationException::withMessages([
            'data.password' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/login.form.email.label'))
            ->email()
            ->inputMode('email')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes([
                'tabindex' => 1,
                'enterkeyhint' => 'next',
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            // The desktop's link, beside the label. Hidden on a phone.
            ->hint(filament()->hasPasswordReset() ? $this->forgotPasswordLink('kaiki-login-forgot-desktop', 3) : null)
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            // Same names as Filament's own two, so these replace them in place:
            // the same eye on a desktop, with a label short enough to be the
            // visible word on a phone.
            ->suffixActions(filament()->arePasswordsRevealable() ? [
                ShowPasswordAction::make()->label(__('auth.login.show_password')),
                HidePasswordAction::make()->label(__('auth.login.hide_password')),
            ] : [])
            ->autocomplete('current-password')
            ->required()
            ->extraInputAttributes([
                'tabindex' => 2,
                'enterkeyhint' => 'go',
            ]);
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')
            ->label(__('filament-panels::pages/auth/login.form.remember.label'))
            // On by default on a phone only, where the crew do not want to sign
            // in again every morning. The server cannot tell a phone from a
            // laptop, so the view's script ticks it below the phone breakpoint
            // and a desktop keeps Filament's unticked box.
            ->extraInputAttributes(['tabindex' => 3, 'data-kaiki-remember' => 'phone'])
            // The phone's link, in the same 48 px row. Hidden on a desktop.
            ->hint(filament()->hasPasswordReset() ? $this->forgotPasswordLink('kaiki-login-forgot-phone', 4) : null);
    }

    private function forgotPasswordLink(string $class, int $tabindex): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-filament::link :href="$href" :tabindex="$tabindex" :class="$class" data-kaiki-forgot> {{ $label }}</x-filament::link>',
            [
                'href' => filament()->getRequestPasswordResetUrl(),
                'tabindex' => $tabindex,
                'class' => $class,
                'label' => __('filament-panels::pages/auth/login.actions.request_password_reset.label'),
            ],
        ));
    }
}
