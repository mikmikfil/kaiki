<?php

declare(strict_types=1);

namespace App\Filament\App\Auth;

use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Illuminate\Contracts\Support\Htmlable;

/**
 * «Ξέχασα τον κωδικό» (product owner, 2026-09-23, direction Α1, screen 4 of
 * `docs/mockups/login-mobile-directions.html`).
 *
 * The same band as the sign-in, «‹ Σύνδεση» above the heading, one line saying
 * what happens next, and **the email already written in** when the sign-in
 * form had one: its «Ξέχασα τον κωδικό» link carries what was typed as
 * `?email=`, so nobody types their address twice on a phone.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * @var view-string
     */
    protected static string $view = 'filament.app.auth.request-password-reset';

    public function mount(): void
    {
        parent::mount();

        $email = request()->query('email');

        // Only something that is an email address, and only into the field:
        // this is a prefill for the person's convenience, never trusted.
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $this->form->fill(['email' => $email]);
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        $broker = Filament::getAuthPasswordBroker() ?? config('auth.defaults.passwords');

        return __('auth.password_request.lead', [
            'minutes' => (int) config("auth.passwords.{$broker}.expire", 60),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::pages/auth/password-reset/request-password-reset.form.email.label'))
            ->email()
            ->inputMode('email')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes([
                'enterkeyhint' => 'send',
                'autocapitalize' => 'none',
                'spellcheck' => 'false',
            ]);
    }
}
