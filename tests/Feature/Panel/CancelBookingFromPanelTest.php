<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Enums\Role;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Authorization\Capability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Booking\CancellationScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Ακύρωση κράτησης» from the panel (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| The phone call: a guest who cannot come, or an operator who cannot take
| them. Everything but the choice is `CancelBooking`'s; these hold the form's
| choices to what they promise.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();
    Mail::fake();

    Carbon::setTestNow('2026-06-20 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking, 2: User} */
function panelCancellation(array $ladder = [15 => 100, 7 => 50, 2 => 0]): array
{
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000, ladder: $ladder);
    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    return [$tenant, $booking, $owner];
}

it('cancels as the policy says when the guest asked, and emails them', function (): void {
    [$tenant, $booking, $owner] = panelCancellation(ladder: [0 => 50]);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('cancel_booking', data: ['who' => 'guest', 'refund' => 'policy'])
        ->assertHasNoActionErrors();

    $fresh = $booking->refresh();

    expect($fresh->status)->toBe(BookingStatus::Cancelled)
        ->and($fresh->cancel_reason)->toBe(CancelReason::GuestRequest)
        ->and($fresh->refunded_cents)->toBe(5000)
        ->and(NotificationLog::alreadySent($booking->getKey(), NotificationTemplate::BookingCancelled, NotificationChannel::Mail))->toBeTrue()
        ->and(AuditLog::query()->where('action', AuditAction::BookingCancelled->value)->exists())->toBeTrue();
})->group('fast');

it('gives everything back without a reason when the operator is the cause', function (): void {
    [$tenant, $booking, $owner] = panelCancellation(ladder: [60 => 0]);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('cancel_booking', data: ['who' => 'operator', 'refund' => 'full'])
        ->assertHasNoActionErrors();

    expect($booking->refresh()->refunded_cents)->toBe(10000)
        ->and($booking->cancel_reason)->toBe(CancelReason::Operator);
})->group('fast');

it('needs a reason to overrule the policy, and applies the percentage given', function (): void {
    [$tenant, $booking, $owner] = panelCancellation(ladder: [60 => 0]);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('cancel_booking', data: ['who' => 'guest', 'refund' => 'percent', 'percent' => 30])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('cancel_booking', data: ['who' => 'guest', 'refund' => 'percent', 'percent' => 30, 'reason' => 'Family emergency'])
        ->assertHasNoActionErrors();

    expect($booking->refresh()->refunded_cents)->toBe(3000);
})->group('fast');

it('issues a voucher instead of money when asked', function (): void {
    [$tenant, $booking, $owner] = panelCancellation(ladder: [60 => 0]);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('cancel_booking', data: ['who' => 'guest', 'refund' => 'voucher', 'percent' => 100, 'reason' => 'Next summer'])
        ->assertHasNoActionErrors();

    // `sum()` is a string on MySQL and a number on SQLite.
    expect((int) Voucher::query()->sum('amount_cents'))->toBe(10000);
})->group('fast');

it('shows the button to a manager, and crew cannot open the booking at all', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);
    $crew = OperatorUser::withRole(Role::Crew, $tenant);
    $manager = OperatorUser::withRole(Role::Manager, $tenant);
    tenancy()->initialize($tenant);

    Livewire::actingAs($manager)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('cancel_booking');

    // Crew never reach a booking's page, and could not act on it if they did.
    expect($this->actingAs($crew)->get('/app/bookings/' . $booking->getRouteKey())->status())->toBeIn([403, 404])
        ->and($crew->hasCapability(Capability::ManageBookings))->toBeFalse();
})->group('fast');
