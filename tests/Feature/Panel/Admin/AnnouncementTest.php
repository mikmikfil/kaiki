<?php

declare(strict_types=1);

use App\Domain\Platform\Support\Announcements;
use App\Enums\AnnouncementSeverity;
use App\Enums\Role;
use App\Filament\Admin\Resources\AnnouncementResource;
use App\Filament\Admin\Resources\AnnouncementResource\Pages\CreateAnnouncement;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| SAA-1 — the platform's announcement banner
|--------------------------------------------------------------------------
|
| One message from the platform owner at the top of every operator's panel.
| What has to hold: it shows in `/app` and nowhere in `/admin`; it respects its
| dates and its switch; each person can close it, and it stays closed until the
| next one; and only the super-admin can write one.
|
*/

function announceAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/** @param  array<string, mixed>  $overrides */
function announce(array $overrides = []): PlatformAnnouncement
{
    return PlatformAnnouncement::query()->create(array_merge([
        'message' => ['el' => 'Συντήρηση την Κυριακή 02:00–03:00', 'en' => 'Maintenance on Sunday 02:00–03:00'],
        'severity' => AnnouncementSeverity::Info,
        'is_active' => true,
    ], $overrides));
}

it('shows the announcement at the top of every operator page, to every role', function (Role $role): void {
    announce();

    actingAs(OperatorUser::withRole($role))
        ->get('/app?lang=el')
        ->assertSuccessful()
        ->assertSee('Συντήρηση την Κυριακή 02:00–03:00');
})->with([[Role::Owner], [Role::Manager], [Role::Crew]])->group('fast');

it('does not show it in /admin', function (): void {
    announce();

    actingAs(announceAdmin())
        ->get('/admin?lang=el')
        ->assertSuccessful()
        ->assertDontSee('Συντήρηση την Κυριακή 02:00–03:00');
})->group('fast');

it('stays closed for the person who closed it, and only for them', function (): void {
    $announcement = announce();

    $owner = OperatorUser::withRole(Role::Owner);
    $colleague = OperatorUser::withRole(Role::Manager);

    actingAs($owner)
        ->post(route('filament.app.announcements.dismiss', ['announcement' => $announcement->getKey()]))
        ->assertRedirect();

    // Twice, as a double click would: a no-op, not an error.
    actingAs($owner)
        ->post(route('filament.app.announcements.dismiss', ['announcement' => $announcement->getKey()]))
        ->assertRedirect();

    actingAs($owner)->get('/app?lang=el')->assertDontSee('Συντήρηση την Κυριακή');

    // Asked of the domain rather than through a second HTTP session: switching
    // users mid-test trips `AuthenticateSession`'s password-hash check and
    // redirects to the login page, which would test the framework, not this.
    expect(Announcements::currentFor($colleague)?->getKey())->toBe($announcement->getKey());

    // "Until the next announcement."
    announce(['message' => ['el' => 'Νέα έκδοση του πίνακα', 'en' => 'A new panel release']]);

    actingAs($owner)->get('/app?lang=el')->assertSee('Νέα έκδοση του πίνακα');
})->group('fast');

it('does not bring back an older notice when the newest is closed', function (): void {
    announce(['message' => ['el' => 'Παλιά', 'en' => 'Old']]);
    $newest = announce(['message' => ['el' => 'Νέα', 'en' => 'New']]);

    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->post(route('filament.app.announcements.dismiss', ['announcement' => $newest->getKey()]));

    expect(Announcements::currentFor($owner))->toBeNull();
})->group('fast');

it('respects its dates and its switch', function (): void {
    Carbon::setTestNow('2026-09-11 12:00:00');

    $owner = OperatorUser::withRole(Role::Owner);

    announce(['starts_at' => '2026-09-12 00:00:00']);
    expect(Announcements::currentFor($owner))->toBeNull();

    PlatformAnnouncement::query()->delete();
    announce(['ends_at' => '2026-09-10 23:59:00']);
    expect(Announcements::currentFor($owner))->toBeNull();

    PlatformAnnouncement::query()->delete();
    announce(['is_active' => false]);
    expect(Announcements::currentFor($owner))->toBeNull();

    PlatformAnnouncement::query()->delete();
    $live = announce(['starts_at' => '2026-09-11 08:00:00', 'ends_at' => '2026-09-11 18:00:00']);
    expect(Announcements::currentFor($owner)?->getKey())->toBe($live->getKey());

    Carbon::setTestNow();
})->group('fast');

it('keeps the message plain text', function (): void {
    $announcement = announce(['message' => ['el' => '<b>Προσοχή</b> αύριο', 'en' => '<script>alert(1)</script>Note']]);

    expect($announcement->refresh()->getTranslation('message', 'el'))->toBe('Προσοχή αύριο')
        ->and($announcement->getTranslation('message', 'en'))->toBe('alert(1)Note');
})->group('fast');

it('refuses an operator the announcement list, at the panel and at the policy', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get(AnnouncementResource::getUrl('index', panel: 'admin'))->assertForbidden();

    expect($owner->can('viewAny', PlatformAnnouncement::class))->toBeFalse()
        ->and($owner->can('create', PlatformAnnouncement::class))->toBeFalse()
        ->and(announceAdmin()->can('create', PlatformAnnouncement::class))->toBeTrue();
})->group('fast');

it('lets the super-admin write one, and records who', function (): void {
    $admin = announceAdmin();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(CreateAnnouncement::class)
        ->fillForm([
            'message' => ['el' => 'Νέα λειτουργία: οι ρυθμίσεις σε κάρτες', 'en' => 'New: settings as cards'],
            'severity' => AnnouncementSeverity::Warning->value,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $announcement = PlatformAnnouncement::query()->sole();

    expect($announcement->getTranslation('message', 'el'))->toBe('Νέα λειτουργία: οι ρυθμίσεις σε κάρτες')
        ->and($announcement->severity)->toBe(AnnouncementSeverity::Warning)
        ->and($announcement->created_by_user_id)->toBe($admin->getKey());
})->group('fast');

it('refuses an end before the start', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs(announceAdmin())
        ->test(CreateAnnouncement::class)
        ->fillForm([
            'message' => ['el' => 'Κάτι', 'en' => 'Something'],
            'severity' => AnnouncementSeverity::Info->value,
            'starts_at' => '2026-09-12 10:00:00',
            'ends_at' => '2026-09-12 09:00:00',
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_at']);

    expect(PlatformAnnouncement::query()->count())->toBe(0);
})->group('fast');
