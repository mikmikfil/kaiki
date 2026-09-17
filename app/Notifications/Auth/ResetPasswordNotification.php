<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Notifications\Auth\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * «Επαναφορά κωδικού πρόσβασης», for both panels (product owner, 2026-09-17).
 *
 * The reset used to be Laravel's own notification: English whatever the
 * person's language, «Reset Password Notification» in the subject, and a
 * layout that looked like nothing else the product sends. Somebody who has
 * forgotten their password is the person least inclined to trust an email
 * that does not look like it came from where they work.
 *
 * So it is a Kaiki message built like the invitation it follows on from: the
 * operator's name and colour for an operator's user (the platform's name for a
 * super-admin, who has no operator), one table with no image, and an HTML and a
 * plain-text part (NTF-6). The language is the person's own, through
 * `User::preferredLocale()`, which the notification sender applies before this
 * is rendered.
 *
 * Filament's reset page resolves `Filament\Notifications\Auth\ResetPassword`
 * from the container and sets `url` on it before notifying, so binding this
 * subclass in `AppServiceProvider` swaps only what the email looks like: the
 * token, the per-panel URL (`/app` and `/admin` each land on their own reset
 * page), the queueing and the broker all stay Filament's.
 */
class ResetPasswordNotification extends ResetPassword
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        /** @var User $notifiable */
        $tenant = $notifiable->tenant_id === null
            ? null
            : Tenancy::withoutTenancy(fn (): ?Tenant => Tenant::query()->find($notifiable->tenant_id));

        $operator = $tenant instanceof Tenant ? $tenant->name : (string) config('app.name');
        $locale = $notifiable->preferredLocale();

        $brand = $tenant instanceof Tenant
            ? Tenancy::forTenant($tenant, fn (): array => app(GetBrandPayload::class)($tenant, $locale))
            : [];

        $broker = (string) config('auth.defaults.passwords', 'users');

        return (new MailMessage)
            ->subject(__('auth.reset_mail.subject', ['operator' => $operator], $locale))
            ->view(
                ['html' => 'mail.account.password-reset-html', 'text' => 'mail.account.password-reset-text'],
                [
                    'user' => $notifiable,
                    'resetUrl' => $this->url,
                    'operator' => $operator,
                    'accent' => $brand['colors']['primary'] ?? '#0F2E57',
                    'minutes' => (int) config("auth.passwords.{$broker}.expire", 60),
                ],
            );
    }
}
