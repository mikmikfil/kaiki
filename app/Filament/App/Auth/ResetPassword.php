<?php

declare(strict_types=1);

namespace App\Filament\App\Auth;

use App\Domain\Tenancy\Actions\InviteStaffMember;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\PasswordResetResponse;
use Filament\Pages\Auth\PasswordReset\ResetPassword as BaseResetPassword;
use Livewire\Attributes\Locked;

/**
 * «Ορισμός κωδικού», for a reset and for an invitation.
 *
 * The same page and the same form: an invited colleague and somebody who
 * forgot their password both arrive with a token and choose a password. What
 * differs is how long the token lives — sixty minutes for a reset, seven days
 * for an invitation (25/9; see `auth.passwords.invitations`) — and so which
 * broker checks it.
 *
 * The page knows which by `invite=1` on the address. The address is signed
 * (the route carries Filament's `signed` middleware) and the flag is part of
 * what was signed, so only a link {@see InviteStaffMember} made can carry it:
 * adding it to a reset link breaks the signature and the page 403s. It is
 * `#[Locked]` for the same reason `$token` is — Livewire would otherwise let
 * the browser change it between mount and submit.
 */
class ResetPassword extends BaseResetPassword
{
    #[Locked]
    public bool $invitation = false;

    public function mount(?string $email = null, ?string $token = null): void
    {
        parent::mount($email, $token);

        $this->invitation = request()->boolean('invite');
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        if ($this->invitation) {
            Filament::getCurrentPanel()?->authPasswordBroker(InviteStaffMember::BROKER);
        }

        return parent::resetPassword();
    }
}
