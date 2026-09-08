<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\Role;
use App\Filament\App\Resources\StaffResource;
use App\Filament\App\Resources\StaffResource\Pages\ListStaff;
use App\Mail\StaffInvitationMail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Giving a colleague a login — spec TEN-8, Capability::ManageStaff
|--------------------------------------------------------------------------
|
| From M0 until now there was no way to do this at all: the policies, the
| capability and the Greek strings were written, and the screen was not. So an
| owner could describe the three roles and hand out none of them, and the only
| route to a second account was somebody editing the database.
|
| Two rules carry this file:
|
|   1. **The inviter never chooses a password.** Not a preference — a password
|      typed by one person and read to another lives on in a chat window, a
|      notebook, and the memory of somebody who will not always work here.
|   2. **One owner always remains.** The guard is on `RoleAssignment::deleting`
|      and this proves both screens reach it rather than stepping around it.
|
*/

/** @return array{0: User, 1: Tenant} */
function staffOwner(): array
{
    $owner = OperatorUser::withRole(Role::Owner);

    return [$owner, Tenant::query()->findOrFail($owner->tenant_id)];
}

function staffScreen(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListStaff::class);
}

beforeEach(function (): void {
    Mail::fake();
});

it('creates the colleague, their roles and their invitation in one go', function (): void {
    [$owner, $tenant] = staffOwner();

    $invited = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Νίκος Βασιλείου',
        email: 'nikos@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
        locale: 'el',
    ));

    expect($invited->tenant_id)->toBe($tenant->getKey())
        ->and($invited->is_super_admin)->toBeFalse()
        ->and(array_map(fn (Role $r): string => $r->value, $invited->roles()))->toBe(['crew']);

    Mail::assertSent(StaffInvitationMail::class, fn (StaffInvitationMail $mail): bool => $mail->hasTo('nikos@example.test'));
})->group('fast');

it('leaves the account unusable until they choose their own password', function (): void {
    // The whole security argument of the feature, asserted rather than
    // described: nothing anyone knows opens this account.
    [$owner, $tenant] = staffOwner();

    $invited = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Ελένη Κοντού',
        email: 'eleni@example.test',
        roles: [Role::Manager],
        invitedBy: $owner,
    ));

    foreach (['', 'password', 'eleni@example.test', 'Ελένη Κοντού'] as $guess) {
        expect(Hash::check($guess, (string) $invited->password))->toBeFalse();
    }

    // And they have not been in yet, which is what the list's status column
    // reads to tell an owner an invitation is still outstanding.
    expect($invited->email_verified_at)->toBeNull();
})->group('fast');

it('sends a link that lands on the panel password page rather than a 404', function (): void {
    // The invitation is worthless without the reset routes, and those were not
    // registered on either panel until this feature added them. A link that
    // 404s is worse than no invitation: the owner believes they sent one.
    [$owner, $tenant] = staffOwner();

    Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Γιώργος Δημητρίου',
        email: 'giorgos@example.test',
        roles: [Role::Manager],
        invitedBy: $owner,
    ));

    Mail::assertSent(StaffInvitationMail::class, function (StaffInvitationMail $mail): bool {
        expect($mail->resetUrl)->toContain('/app/password-reset/reset')
            ->and($mail->resetUrl)->toContain('token=');

        return true;
    });
})->group('fast');

it('opens for an owner and is invisible to a manager', function (): void {
    // TEN-8: adding a colleague is how somebody grants themselves more than
    // they had, so it stays with the owner alone.
    [$owner] = staffOwner();
    $manager = OperatorUser::withRole(Role::Manager);
    $crew = OperatorUser::withRole(Role::Crew);

    // `Auth::login` rather than `$this->actingAs`: inside a Pest closure that
    // chains `->group()`, static analysis resolves `$this` to the pending
    // `TestCall`, and the method it wants is on the test case. `canAccess()`
    // reads the guard, which is all either call sets up here.
    $access = static function (User $user): bool {
        tenancy()->initialize($user->tenant);
        Auth::login($user);

        return StaffResource::canAccess();
    };

    expect($access($owner))->toBeTrue()
        ->and($access($manager))->toBeFalse()
        ->and($access($crew))->toBeFalse();
})->group('fast');

it('lists this operator and not another', function (): void {
    // `User` has no `BelongsToTenant` on purpose — one person can crew for two
    // companies — so nothing scopes this screen except its own `where`.
    [$owner, $tenant] = staffOwner();

    $stranger = OperatorUser::withRole(Role::Owner);

    $colleague = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Άννα Ιωάννου',
        email: 'anna@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
    ));

    staffScreen($owner)
        ->assertCanSeeTableRecords([$owner, $colleague])
        ->assertCanNotSeeTableRecords([$stranger]);
})->group('fast');

it('offers nobody a button to delete themselves', function (): void {
    // `UserPolicy::delete()` refuses it outright, and Filament therefore does
    // not render the action. Deleting your own account is a support
    // conversation, not a button beside your own name in a list.
    [$owner] = staffOwner();

    staffScreen($owner)->assertTableActionHidden('delete', $owner);
})->group('fast');

it('refuses to take the owner role off the last owner, and says so', function (): void {
    // The guard lives on `RoleAssignment::deleting` and throws. What is asserted
    // here is that the screen reaches it and survives: an owner demoting
    // themselves by accident meets a sentence, not a five hundred, and still
    // holds the role afterwards.
    [$owner, $tenant] = staffOwner();

    $colleague = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Άλλος Ιδιοκτήτης',
        email: 'other@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
    ));

    staffScreen($owner)
        ->callTableAction('edit', $owner, [
            'name' => $owner->name,
            'locale' => 'el',
            'roles' => ['crew'],
        ])
        ->assertNotified();

    expect(array_map(fn (Role $r): string => $r->value, $owner->refresh()->roles()))
        ->toBe(['owner'])
        // And the colleague was not collateral damage of the rolled-back swap.
        ->and(array_map(fn (Role $r): string => $r->value, $colleague->refresh()->roles()))
        ->toBe(['crew']);
})->group('fast');

it('removes a colleague who is not the last owner', function (): void {
    [$owner, $tenant] = staffOwner();

    $colleague = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Δημήτρης Αλεξίου',
        email: 'dimitris@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
    ));

    staffScreen($owner)->callTableAction('delete', $colleague);

    expect(User::query()->whereKey($colleague->getKey())->exists())->toBeFalse();
})->group('fast');

it('sends a fresh invitation only to somebody who has not been in', function (): void {
    // A "choose your password" link arriving at a colleague who already has one
    // reads as a security incident, so the action is not offered to them.
    [$owner, $tenant] = staffOwner();

    $pending = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Μαρία Παπαδοπούλου',
        email: 'maria@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
    ));

    $settled = Tenancy::forTenant($tenant, fn (): User => app(InviteStaffMember::class)(
        name: 'Κώστας Λεωνίδα',
        email: 'kostas@example.test',
        roles: [Role::Crew],
        invitedBy: $owner,
    ));

    $settled->forceFill(['email_verified_at' => now()])->save();

    staffScreen($owner)
        ->assertTableActionVisible('resend', $pending)
        ->assertTableActionHidden('resend', $settled);
})->group('fast');
