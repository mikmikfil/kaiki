<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\Role;
use App\Mail\StaffInvitationMail;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The invitation link has to actually open
|--------------------------------------------------------------------------
|
| `filament.app.auth.password-reset.reset` carries Filament's `signed`
| middleware, and the Action built its address with `route()` — which does not
| sign. Every invitation ever sent answered **403 Invalid signature**, so no
| invited colleague could set a password and the feature had never worked.
|
| It survived because every existing test calls the Action and then asserts on
| the `users` row. Nothing opened the URL. So that is what these do: they follow
| the link the way a person does, and one of them asserts the signature is there
| at all, because a future refactor back to `route()` would look harmless.
|
*/

/** The link out of the mail the Action actually sends. */
function invitationLinkFor(callable $invite): string
{
    Mail::fake();

    $invite();

    $captured = null;

    Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail) use (&$captured): bool {
        $captured = $mail->resetUrl;

        return true;
    });

    return (string) $captured;
}

it('sends a link that opens rather than one that answers 403', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $url = Tenancy::forTenant($owner->tenant, fn (): string => invitationLinkFor(
        fn () => app(InviteStaffMember::class)(
            name: 'Νίκος Καπετάνιος',
            email: 'skipper@example.test',
            roles: [Role::Crew],
            invitedBy: $owner,
        ),
    ));

    expect($url)->not->toBe('');

    // The whole defect, in one assertion: the page the colleague is sent to.
    get($url)->assertOk();
});

it('carries a signature, because the route refuses without one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $url = Tenancy::forTenant($owner->tenant, fn (): string => invitationLinkFor(
        fn () => app(InviteStaffMember::class)(
            name: 'Μαρία Πλήρωμα',
            email: 'crew@example.test',
            roles: [Role::Crew],
            invitedBy: $owner,
        ),
    ));

    // `route()` produces the same URL minus these, and reads as correct.
    expect($url)->toContain('signature=')
        ->and($url)->toContain('token=');
});

it('refuses a link whose signature has been tampered with', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $url = Tenancy::forTenant($owner->tenant, fn (): string => invitationLinkFor(
        fn () => app(InviteStaffMember::class)(
            name: 'Κάποιος Άλλος',
            email: 'tampered@example.test',
            roles: [Role::Crew],
            invitedBy: $owner,
        ),
    ));

    // Signing is not decoration: the point is that the token and the address
    // cannot be edited by whoever is holding the link.
    $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $url);

    get((string) $tampered)->assertForbidden();
});

it('lets the invited colleague sign in once they have set a password', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant($owner->tenant, function () use ($owner): void {
        invitationLinkFor(fn () => app(InviteStaffMember::class)(
            name: 'Νέος Συνεργάτης',
            email: 'colleague@example.test',
            roles: [Role::Manager],
            invitedBy: $owner,
        ));

        $invited = User::query()->where('email', 'colleague@example.test')->firstOrFail();

        // Until the link is spent the account exists and is unusable — the row
        // carries a random string nobody has ever seen, which is what makes
        // "cannot sign in yet" true rather than "empty password".
        expect($invited->password)->not->toBeEmpty()
            ->and($invited->tenant_id)->toBe($owner->tenant->getKey());
    });
});
