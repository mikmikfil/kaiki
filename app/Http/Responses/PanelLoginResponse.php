<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Filament\App\Pages\Dashboard;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After signing in to /app: the home page, not the camera (Mike, 25/9).
 *
 * Filament sends a signed-in user to `Filament::getUrl()`, which is the first
 * item of the navigation — and since 24/9 that is «Σάρωση εισιτηρίων», pinned
 * above every group, so every sign-in opened the boarding camera. The page a
 * signed-out visitor was trying to reach still wins (`intended`); otherwise
 * «Αρχική». Other panels keep Filament's own answer.
 */
final class PanelLoginResponse implements LoginResponse
{
    // Filament's own signature: inside a Livewire request `redirect()` is
    // Livewire's Redirector, which PHPStan's stubs cannot see.
    // @phpstan-ignore return.unusedType
    public function toResponse($request): RedirectResponse|Redirector
    {
        $home = Filament::getCurrentPanel()?->getId() === 'app'
            ? Dashboard::getUrl()
            : Filament::getUrl();

        return redirect()->intended($home);
    }
}
