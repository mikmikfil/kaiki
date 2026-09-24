<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\StartImpersonation;
use App\Domain\Tenancy\Actions\StopImpersonation;
use App\Domain\Tenancy\Support\ImpersonationSession;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Exceptions\ImpersonationRefused;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A super-admin signs in as an operator — TEN-7, SAA-2
|--------------------------------------------------------------------------
|
| Mike, 2026-09-23: *«θέλω ως admin να συνδέομαι ανά πάσα στιγμή στον operator
| και να μπορώ να φτιάχνω διάφορα»* — full access, sixty minutes.
|
| `User::hasCapability()` has always refused a super-admin and said why:
| *«Reaching an operator's data is impersonation (TEN-7, M7), an audited action
| rather than an implicit privilege.»* That was half a decision — the refusal
| shipped and the audited action did not, so `/app` was a 403 with no way
| forward. These tests are the other half.
|
| The design worth pinning is that **the acting user really becomes the
| operator's person**: there is no borrowed-powers mode, so every policy in the
| codebase behaves exactly as it does in normal use.
|
*/

it('signs the platform owner in as the operator, and records it in their log', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin);

    app(StartImpersonation::class)($admin, $owner, $owner->tenant, 'Δεν βγαίνει τιμή στο ηλιοβασίλεμα');

    // The session is the operator's person — not a super-admin wearing a hat.
    expect(auth()->id())->toBe($owner->getKey())
        ->and(ImpersonationSession::isActive())->toBeTrue()
        ->and(ImpersonationSession::impersonatorId())->toBe($admin->getKey())
        // SAA-2's sixty minutes, and the deadline is stored rather than
        // recomputed, so changing the default later cannot extend a session
        // that is already running.
        ->and(ImpersonationSession::minutesLeft())->toBe(ImpersonationSession::MINUTES);

    $entry = Tenancy::forTenant($owner->tenant, fn (): AuditLog => AuditLog::query()
        ->where('action', AuditAction::ImpersonationStarted->value)
        ->sole());

    // Written into the **operator's** trail and naming the platform owner as
    // the actor — not the person whose session it became. That is the whole
    // point of writing it before the switch.
    expect($entry->user_id)->toBe($admin->getKey())
        ->and($entry->reason)->toBe('Δεν βγαίνει τιμή στο ηλιοβασίλεμα')
        ->and($entry->context['impersonator_email'] ?? null)->toBe($admin->email);
})->group('fast');

it('refuses a target from another operator', function (): void {
    // The one refusal that is a tenancy boundary rather than a policy. The user
    // id arrives from a form, so it is checked against the tenant rather than
    // trusted — otherwise the form could name anybody on the platform.
    $mine = OperatorUser::withRole(Role::Owner);
    $theirs = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin);

    expect(fn () => app(StartImpersonation::class)($admin, $theirs, $mine->tenant, 'Δοκιμή'))
        ->toThrow(ImpersonationRefused::class);

    expect(auth()->id())->toBe($admin->getKey())
        ->and(ImpersonationSession::isActive())->toBeFalse();
})->group('fast');

it('refuses an operator asking, and refuses an empty reason', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $crew = OperatorUser::withRole(Role::Crew, tenant: $owner->tenant);
    $admin = User::factory()->superAdmin()->create();

    // Not a super-admin: the whole feature is the platform's.
    actingAs($owner);
    expect(fn () => app(StartImpersonation::class)($owner, $crew, $owner->tenant, 'Γιατί όχι'))
        ->toThrow(ImpersonationRefused::class);

    // SAA-2 wants the reason in the trail, and whitespace is not a reason.
    actingAs($admin);
    expect(fn () => app(StartImpersonation::class)($admin, $crew, $owner->tenant, '   '))
        ->toThrow(ImpersonationRefused::class);

    expect(Tenancy::forTenant($owner->tenant, fn (): int => AuditLog::query()
        ->where('action', AuditAction::ImpersonationStarted->value)
        ->count()))->toBe(0);
})->group('fast');

it('gives the platform owner back to themselves on the way out', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin);
    app(StartImpersonation::class)($admin, $owner, $owner->tenant, 'Υποστήριξη');

    $restored = app(StopImpersonation::class)();

    expect($restored?->getKey())->toBe($admin->getKey())
        ->and(auth()->id())->toBe($admin->getKey())
        ->and(ImpersonationSession::isActive())->toBeFalse();
})->group('fast');

it('ends by itself after the hour, and resumes nobody', function (): void {
    // The deadline is the promise SAA-2 makes to the *operator*, so it cannot
    // depend on the platform owner pressing anything. An expired session is
    // signed out rather than handed back: restoring one that expired because
    // it was unattended is the single case where this would quietly extend
    // itself.
    $owner = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin);
    app(StartImpersonation::class)($admin, $owner, $owner->tenant, 'Υποστήριξη');

    Carbon::setTestNow(Carbon::now()->addMinutes(ImpersonationSession::MINUTES + 1));

    expect(ImpersonationSession::isActive())->toBeFalse()
        ->and(ImpersonationSession::hasExpired())->toBeTrue();

    $restored = app(StopImpersonation::class)();

    expect($restored)->toBeNull()
        ->and(auth()->check())->toBeFalse();

    Carbon::setTestNow();
})->group('fast');

it('lets the platform owner reach the operator panel, which it refused before', function (): void {
    // The acceptance test for the whole thing: `admin@kaiki.example` 403s at
    // `/app` by design, and impersonation is what turns that into a door.
    $owner = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin)->get('/app')->assertForbidden();

    app(StartImpersonation::class)($admin, $owner, $owner->tenant, 'Υποστήριξη');

    actingAs(auth()->user())->get('/app')->assertSuccessful();
})->group('fast');

it('keeps the operator signed in on the next page, and the platform owner on the way back', function (): void {
    // Found by Mike on 2026-09-23: «Σύνδεση ως» landed on /app/login. The test
    // above signs in again with `actingAs()` after the switch, which hid it —
    // this one carries the session exactly as the browser does.
    //
    // Visiting /admin first puts the platform owner's password fingerprint in
    // the session, the way AuthenticateSession does in real use. The switch
    // happens inside a Livewire action, where that middleware does not run, so
    // unless the switch replaces the fingerprint itself the next page compares
    // it with the operator's password and signs the session out.
    $owner = OperatorUser::withRole(Role::Owner);
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin)->get('/admin')->assertSuccessful();

    app(StartImpersonation::class)($admin, $owner, $owner->tenant, 'Υποστήριξη');

    $this->get('/app')->assertSuccessful();
    expect(auth()->id())->toBe($owner->getKey());

    app(StopImpersonation::class)();

    $this->get('/admin')->assertSuccessful();
    expect(auth()->id())->toBe($admin->getKey());
})->group('fast');
