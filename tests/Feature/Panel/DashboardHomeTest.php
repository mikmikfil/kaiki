<?php

declare(strict_types=1);

use App\Domain\Operations\Support\AttentionItems;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\Role;
use App\Filament\App\Pages\CheckIn;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The home page, the phone menu and the decisions (2026-09-17)
|--------------------------------------------------------------------------
|
| «Version 3», the day by boat; Menu 1 as boxes on a phone; and «Χρειάζονται
| προσοχή», where every row now leads to the screen that fixes it.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

function homeOwner(): User
{
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create(['timezone' => 'Europe/Athens']));

    Tenancy::forTenant($owner->tenant, function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Αύρα']);
        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
        ]);
    });

    return $owner;
}

it('says good morning until four in the afternoon, and good evening from then, on the operator\'s clock', function (string $utc, bool $evening): void {
    $owner = homeOwner();
    tenancy()->initialize($owner->tenant);

    // Athens is UTC+3 in September: 12:59 UTC is 15:59 on the quay.
    expect(Dashboard::isEvening(Carbon::parse($utc, 'UTC')))->toBe($evening);
})->with([
    'a minute before four' => ['2026-09-08 12:59:00', false],
    'four o\'clock' => ['2026-09-08 13:00:00', true],
    'late at night' => ['2026-09-08 23:30:00', true],
    'early morning' => ['2026-09-08 04:30:00', false],
])->group('fast');

it('greets the operator with a sun or a moon', function (): void {
    Carbon::setTestNow('2026-09-08 14:00:00');
    $owner = homeOwner();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('dashboard.home.greeting.evening'))
        ->assertSee('ka-greeting-ic', escape: false);
})->group('fast');

it('puts the next departure, the four boxes and the boats on the home page', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertSee(__('dashboard.home.next.label'))
        ->assertSee('18:00')
        ->assertSee(__('dashboard.home.boxes.bookings'))
        ->assertSee(__('dashboard.home.boats.heading'))
        ->assertSee('Αύρα');
})->group('fast');

it('offers the phone menu as boxes, with a close button', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    actingAs(homeOwner())->get('/app')
        ->assertSuccessful()
        ->assertSee('ka-mobile-menu-btn', escape: false)
        ->assertSee(__('panel.mobile_menu.close'))
        ->assertSee('ka-menu-box', escape: false);
})->group('fast');

it('sends a sailing short of its minimum to that departure', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();

    Tenancy::forTenant($owner->tenant, function (): void {
        Departure::query()->firstOrFail()->forceFill([
            'status' => DepartureStatus::Scheduled,
            'min_pax' => 8,
            'seats_sold' => 2,
        ])->save();
    });

    tenancy()->initialize($owner->tenant);
    actingAs($owner);

    $item = (new AttentionItems('Europe/Athens'))->all()[0];
    $row = NeedsAttention::actionFor($item);

    expect($row)->not->toBeNull()
        ->and($row['url'])->toBe(DepartureResource::getUrl('edit', ['record' => $item->subject]))
        ->and($row['action'])->toBe(__('attention.actions.departure'));

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertOk()
        ->assertSee(__('attention.actions.departure'));
})->group('fast');

it('takes scanning, boarding and aboard counts off the home page when boarding is switched off', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => false])->save();
    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertDontSee(__('dashboard.home.next.scan'))
        ->assertDontSee(__('dashboard.home.next.board'))
        ->assertDontSeeHtml('role="progressbar"')
        ->assertSee(__('dashboard.home.next.booked'));

    // Nor in the phone's box menu, which reads the same navigation.
    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertDontSee(CheckIn::getUrl(), escape: false);
})->group('fast');

it('keeps the boarding list but never «Σάρωση» when only the QR scanner is switched off', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => true, 'qr_check_in_enabled' => false])->save();
    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertDontSee(__('dashboard.home.next.scan'))
        ->assertSee(__('dashboard.home.next.board'));
})->group('fast');

it('greets the operator by their first name, or without one when it is blank', function (?string $name, string $expected): void {
    app()->setLocale('el');

    expect(Dashboard::greeting(false, $name))->toBe($expected)
        ->and(Dashboard::greeting(true, $name))->toBe(str_replace('Καλημέρα', 'Καλησπέρα', $expected));
})->with([
    'full name' => ['  Μαρία Παπαδοπούλου ', 'Καλημέρα, Μαρία'],
    'blank' => ['   ', 'Καλημέρα'],
    'none' => [null, 'Καλημέρα'],
])->group('fast');
