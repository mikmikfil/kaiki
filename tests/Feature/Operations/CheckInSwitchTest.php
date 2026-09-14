<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CompleteDepartures;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Filament\App\Pages\CheckIn;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Check-in itself is optional, not only the scan
|--------------------------------------------------------------------------
|
| `qr_check_in_enabled` answered "do they scan?", and the answer left every
| operator with a passenger list and a tap beside each name. A good number of
| them do not do that either: they know who is coming, the twelve people are
| standing in front of them, and a screen asking them to confirm each one is a
| second list to keep in step with the one in their head.
|
| So "off" has to mean off on **every** boarding surface at once, which is the
| lesson the QR switch already learnt the hard way — one live surface after the
| feature was switched off is a page somebody has bookmarked:
|
| - the check-in page leaves the navigation and refuses its URL;
| - the no-signal boarding page refuses too, rather than staying open as it
|   deliberately does when only the QR is off;
| - the ticket carries no QR, because scanning cannot outlive check-in;
| - and `/admin` stops offering the QR question, which has no meaning under it.
|
| What must **not** change is anything that is a record rather than a gesture:
| the manifest still prints, the departure still completes, and a booking still
| reaches `completed` on the day. The switch removes a surface, not a fact.
|
*/

it('reads an operator with no value as checking in, because that is every operator before the switch', function (): void {
    expect(Tenant::factory()->create(['check_in_enabled' => null])->usesCheckIn())->toBeTrue()
        ->and(Tenant::factory()->create()->usesCheckIn())->toBeTrue()
        ->and(Tenant::factory()->withoutCheckIn()->create()->usesCheckIn())->toBeFalse();
})->group('fast');

it('cannot leave scanning switched on underneath a switched-off check-in', function (): void {
    // Two independent booleans would let `/admin` store exactly that pair, and
    // a ticket with a QR that scans to a page nobody can open is the shape of
    // the bug. The narrower switch reads the wider one.
    $tenant = Tenant::factory()->withoutCheckIn()->create(['qr_check_in_enabled' => true]);

    expect($tenant->usesQrCheckIn())->toBeFalse();
})->group('fast');

it('takes both boarding pages away from an operator who checks nobody in', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $tenant->forceFill(['check_in_enabled' => false])->save();

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    // The page a capability alone would have opened: the crew member still has
    // `CheckInGuests`, and the operator no longer boards anybody.
    Tenancy::forTenant($tenant, function (): void {
        expect(CheckIn::canAccess())->toBeFalse();
    });

    actingAs($crew)->get(route('filament.app.pages.check-in'))->assertForbidden();

    // And the one that stays open when only the QR is off, because then it is
    // the only boarding that works without a signal. With check-in off there is
    // no gesture left for it to queue.
    actingAs($crew)->get(route('filament.app.boarding'))->assertForbidden();
    actingAs($crew)
        ->postJson(route('filament.app.boarding.scan'), ['scans' => [['ticket_code' => 'TCK-ANY']]])
        ->assertForbidden();
})->group('fast');

it('leaves both pages open for everybody else', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    actingAs($crew)->get(route('filament.app.boarding'))->assertOk();

    Tenancy::forTenant($tenant, function (): void {
        expect(CheckIn::canAccess())->toBeTrue();
    });
})->group('fast');

it('keeps the departure and its bookings moving without a boarding screen', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    $tenant->forceFill(['check_in_enabled' => false])->save();

    // The record half of check-in is untouched: a confirmed booking on a
    // departure that has sailed still completes, exactly as it would for an
    // operator who never opened the page.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => Carbon::now()->subHours(9),
            // Past `CompleteDepartures::GRACE_HOURS`, so the sweep is due.
            'ends_at_utc' => Carbon::now()->subHours(5),
        ])->save();

        app(CompleteDepartures::class)();

        expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
    });
})->group('fast');

it('offers the QR question only while check-in is on', function (): void {
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The column is null for every operator who existed before it, and a truthy
    // visibility test hid this toggle from all of them — which is how the
    // manual's screenshot of this section failed to capture, two hours after it
    // was written.
    $before = Tenant::factory()->create(['check_in_enabled' => null]);

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $before->getRouteKey()])
        ->assertFormFieldExists('qr_check_in_enabled');

    $off = Tenant::factory()->withoutCheckIn()->create();

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $off->getRouteKey()])
        ->assertFormFieldDoesNotExist('qr_check_in_enabled');
})->group('fast');

it('lets the platform switch it off, with a reason, in the operator\'s own trail', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet(['check_in_enabled' => true])
        ->fillForm(['check_in_enabled' => false])
        ->callAction('save', ['auditReason' => 'Δώδεκα άτομα στο μουράγιο· δεν κάνει έλεγχο επιβίβασης.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->usesCheckIn())->toBeFalse();

    // SEC-16, the same as a plan change: who, when, why, and only what moved.
    Tenancy::forTenant($tenant, function () use ($admin): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->user_id)->toBe($admin->id)
            // `toEqual`, because MySQL's JSON type sorts an object's keys by
            // length and SQLite keeps them as written.
            ->and($entry->context)->toEqual([
                'check_in_enabled_from' => true,
                'check_in_enabled_to' => false,
            ]);
    });
})->group('fast');
