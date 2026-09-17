<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Password reset as a Kaiki email (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| It was Laravel's English default on both panels. It is now the person's own
| language, the operator's name and colour, and a proper subject, with the
| per-panel link Filament builds left untouched.
|
*/

/**
 * @return array{0: string, 1: string} html and text, rendered in the locale
 *                                     the notification sender would apply
 */
function renderReset(MailMessage $message, string $locale): array
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return [
            (string) $message->render(),
            view($message->view['text'], $message->viewData)->render(),
        ];
    } finally {
        App::setLocale($previous);
    }
}

it('binds the panels\' reset notification to the Kaiki one', function (): void {
    expect(app(ResetPassword::class, ['token' => 'abc']))->toBeInstanceOf(ResetPasswordNotification::class);
})->group('fast');

it('sends the reset from /app in Greek with the operator\'s name', function (): void {
    Notification::fake();

    $tenant = Tenant::factory()->create(['name' => 'Aegean Blue']);
    $user = User::factory()->create(['tenant_id' => $tenant->getKey(), 'locale' => 'el']);

    Filament::setCurrentPanel(Filament::getPanel('app'));

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $user->email])
        ->call('request')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
        $message = $notification->toMail($user);
        [$html, $text] = renderReset($message, $user->preferredLocale());

        expect($user->preferredLocale())->toBe('el')
            ->and($message->subject)->toBe('Επαναφορά κωδικού πρόσβασης · Aegean Blue')
            ->and($message->viewData['resetUrl'])->toContain('/app/password-reset/reset')
            ->and($html)->toContain('Ορισμός νέου κωδικού')
            ->and($html)->toContain('Aegean Blue')
            ->and($text)->toContain($message->viewData['resetUrl'])
            ->and($html)->not->toContain('Reset Password Notification');

        return true;
    });
})->group('fast');

it('sends the reset from /admin in English with the platform\'s name', function (): void {
    Notification::fake();

    $admin = User::factory()->superAdmin()->create(['locale' => 'en']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $admin->email])
        ->call('request')
        ->assertHasNoErrors();

    Notification::assertSentTo($admin, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($admin): bool {
        $message = $notification->toMail($admin);
        [$html] = renderReset($message, $admin->preferredLocale());

        expect($message->viewData['resetUrl'])->toContain('/admin/password-reset/reset')
            ->and($message->subject)->toBe('Reset your password · ' . config('app.name'))
            ->and($html)->toContain('Choose a new password');

        return true;
    });
})->group('fast');
