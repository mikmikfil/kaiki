<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\AuditLog;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Booking\CheckInScenario;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| BKG-20, amended 2026-09-11 — QR boarding is the platform's switch
|--------------------------------------------------------------------------
|
| A one-boat operator with twelve people on the quay boards from the passenger
| list. The product owner asked for the QR to be optional and for the switch to
| be theirs, on `/admin`, rather than the operator's.
|
| What "off" has to mean everywhere at once, because hiding one surface and
| leaving the others would be a ticket whose QR scans to a 404:
|
| - the ticket carries **no** QR and no ticket code under it;
| - the offline boarding page loses its scan box and ignores `?ticket=`, and
|   keeps the list with a button per name, queued offline like a scan;
| - the check-in page loses the scan box and ignores `?ticket=`, and keeps the
|   list with a tap per name — check-in itself is not optional, the scan is.
|
*/

/** @return array{0: Tenant, 1: User, 2: BookingGuest} */
function qrSwitchCrew(bool $qr): array
{
    [$tenant, $booking] = GuestPageScenario::booking();

    $tenant->forceFill(['qr_check_in_enabled' => $qr])->save();

    $guest = Tenancy::forTenant($tenant, function () use ($booking): BookingGuest {
        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => Carbon::now()->addMinutes(15),
            'ends_at_utc' => Carbon::now()->addHours(4),
        ])->save();

        $guest = BookingGuest::query()->where('booking_id', $booking->getKey())->firstOrFail();

        $guest->forceFill([
            'full_name' => 'Νίκος Αλεξίου',
            'ticket_code' => 'TCK-QR-SWITCH',
            'checked_in_at' => null,
        ])->save();

        return $guest;
    });

    return [$tenant, OperatorUser::withRole(Role::Crew, $tenant), $guest];
}

it('reads an operator with no value as scanning, because that is every operator before the switch', function (): void {
    expect(Tenant::factory()->create(['qr_check_in_enabled' => null])->usesQrCheckIn())->toBeTrue()
        ->and(Tenant::factory()->create()->usesQrCheckIn())->toBeTrue()
        ->and(Tenant::factory()->withoutQrCheckIn()->create()->usesQrCheckIn())->toBeFalse();
})->group('fast');

it('keeps the no-signal page without QR, as a list with a button per name', function (): void {
    [$tenant, $crew, $guest] = qrSwitchCrew(qr: false);

    // The first version answered 404 here, which took the only boarding that
    // works with no signal away from the operators least likely to have one.
    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    expect($body)->toContain('Νίκος Αλεξίου')
        ->and($body)->toContain('TCK-QR-SWITCH')
        ->and($body)->not->toContain('id="scanForm"')
        // An old QR's `?ticket=` is ignored by the page's script, not acted on.
        ->and($body)->toContain('var QR_ENABLED = false');

    actingAs($crew)->get(route('filament.app.boarding.sw'))->assertOk();

    // A tapped name posts its guest's code through the same queue a scan uses,
    // and the same Action decides.
    $tap = actingAs($crew)
        ->postJson(route('filament.app.boarding.scan'), ['scans' => [['ticket_code' => 'TCK-QR-SWITCH']]])
        ->assertOk();

    expect($tap->json('results.0.status'))->toBe('checked_in');

    Tenancy::forTenant($tenant, function () use ($guest): void {
        expect($guest->fresh()->checked_in_at)->not->toBeNull();
    });
})->group('fast');

it('keeps the scan box on the no-signal page for an operator who scans', function (): void {
    [, $crew] = qrSwitchCrew(qr: true);

    $body = (string) actingAs($crew)->get(route('filament.app.boarding'))->assertOk()->getContent();

    expect($body)->toContain('id="scanForm"')->toContain('var QR_ENABLED = true');
})->group('fast');

it('offers early boarding from the list, before the window opens', function (): void {
    [$tenant, $crew, $guest] = qrSwitchCrew(qr: false);

    // Two hours out: the window (thirty minutes by default) has not opened, so
    // a plain tap would be refused. Until this fix the override existed only
    // beside a scanned ticket — without QR there was no way to board early,
    // while the refusal message said a manager could.
    Tenancy::forTenant($tenant, function () use ($guest): void {
        $guest->booking?->forceFill([
            'starts_at_utc' => Carbon::now()->addHours(2),
            'ends_at_utc' => Carbon::now()->addHours(5),
        ])->save();
    });

    actingAs($crew)
        ->get(route('filament.app.pages.check-in', ['lang' => 'el']))
        ->assertOk()
        ->assertSee(__('checkin.actions.override.label', [], 'el'))
        ->assertSee(__('checkin.offline_link', [], 'el'));
})->group('fast');

it('keeps the passenger list and drops the scan box when QR is off', function (): void {
    [, $crew] = qrSwitchCrew(qr: false);

    // An old QR still opens this URL. Off, the code in it is ignored rather
    // than acted on — the page shows the list, not a scanned guest.
    $body = (string) actingAs($crew)
        ->get(route('filament.app.pages.check-in', ['ticket' => 'TCK-QR-SWITCH', 'lang' => 'el']))
        ->assertOk()
        ->getContent();

    expect($body)->toContain('Νίκος Αλεξίου')
        ->and($body)->toContain(__('checkin.subtitle_list', [], 'el'))
        ->and($body)->not->toContain('wire:model.live.debounce.300ms="ticket"')
        ->and($body)->not->toContain(__('checkin.scan.label', [], 'el'))
        // Nor the scanned-guest panel, which is the only place the override
        // help sentence appears.
        ->and($body)->not->toContain('Η επιβίβαση για αυτό το δρομολόγιο ανοίγει');
})->group('fast');

it('keeps the scan box for an operator who scans', function (): void {
    [, $crew] = qrSwitchCrew(qr: true);

    $body = (string) actingAs($crew)
        ->get(route('filament.app.pages.check-in', ['lang' => 'el']))
        ->assertOk()
        ->getContent();

    expect($body)->toContain('wire:model.live.debounce.300ms="ticket"')
        ->and($body)->not->toContain(__('checkin.subtitle_list', [], 'el'));
})->group('fast');

it('prints a ticket with no QR and no ticket code when QR is off', function (): void {
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, fn () => $booking->load(['guests', 'product.meetingPoint', 'vessel']));

    $render = fn (bool $qr): string => Tenancy::forTenant($tenant, fn (): string => view('pdf.e-ticket', [
        'booking' => $booking,
        'brand' => [],
        'qr' => $qr,
    ])->render());

    $code = (string) $booking->guests->first()?->ticket_code;

    $without = $render(false);

    // The ticket still says who, when and where — the booking reference is
    // what a skipper with a paper list looks for.
    expect($without)->not->toContain('<svg')
        ->and($without)->not->toContain($code)
        ->and($without)->toContain($booking->reference);

    expect($render(true))->toContain('<svg')->toContain($code);
})->group('fast');

it('lets the platform switch it off, with a reason, in the operator\'s own trail', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getKey()])
        ->assertFormSet(['qr_check_in_enabled' => true])
        ->fillForm(['qr_check_in_enabled' => false])
        ->callAction('save', ['auditReason' => 'Ένα σκάφος, επιβίβαση από τη λίστα.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->usesQrCheckIn())->toBeFalse();

    // SEC-16, the same as a plan change: who, when, why, and only what moved.
    Tenancy::forTenant($tenant, function () use ($admin): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->user_id)->toBe($admin->id)
            ->and($entry->context)->toBe([
                'qr_check_in_enabled_from' => true,
                'qr_check_in_enabled_to' => false,
            ]);
    });
})->group('fast');
