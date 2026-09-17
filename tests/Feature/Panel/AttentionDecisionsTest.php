<?php

declare(strict_types=1);

use App\Domain\Operations\Support\AttentionItems;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Χρειάζονται προσοχή»: the two decisions taken in place (2026-09-17)
|--------------------------------------------------------------------------
|
| «Ουσιαστικά δεν παίρνω καμία απόφαση, απλά βλέπω.» A sailing short of its
| minimum is cancelled or sails from the row itself, and an overdue balance is
| marked paid there — each through the path the full screens already use.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-09-08 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function decisionsOwner(Role $role = Role::Owner): User
{
    return OperatorUser::withRole($role, Tenant::factory()->create(['timezone' => 'Europe/Athens']));
}

/** A sailing tomorrow evening with 2 of the 8 it needs. */
function shortDeparture(User $user): Departure
{
    return Tenancy::forTenant($user->tenant, function (): Departure {
        $vessel = Vessel::factory()->create();
        $departure = Departure::factory()->for($vessel)->at('2026-09-09', '18:00')->withSeats(2)->create();
        $departure->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 8, 'seats_sold' => 2])->save();

        return $departure;
    });
}

function decisionKeys(User $user): array
{
    return Tenancy::forTenant($user->tenant, fn (): array => array_map(
        static fn ($item): string => $item->key,
        (new AttentionItems('Europe/Athens'))->everything(),
    ));
}

it('cancels a short sailing from the row, through the departure cancellation path', function (): void {
    $owner = decisionsOwner();
    $departure = shortDeparture($owner);

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertSee(__('attention.decide.cancel'))
        ->callAction('cancelDeparture', arguments: ['departure' => $departure->getKey()])
        ->assertNotified();

    $fresh = $departure->fresh();

    expect($fresh->status)->toBe(DepartureStatus::Cancelled)
        ->and($fresh->cancel_reason)->toBe(DepartureCancelReason::MinPax)
        ->and($fresh->cancelled_by_user_id)->toBe($owner->getKey())
        ->and(decisionKeys($owner))->not->toContain('departure:' . $departure->getKey());
})->group('fast');

it('lets a short sailing go ahead, and does not ask again', function (): void {
    $owner = decisionsOwner();
    $departure = shortDeparture($owner);

    expect(decisionKeys($owner))->toContain('departure:' . $departure->getKey());

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertSee(__('attention.decide.sail'))
        ->callAction('sailAnyway', arguments: ['departure' => $departure->getKey()])
        ->assertNotified(__('attention.decide.sailed'));

    // Stored as the promise it is, and on the trail as the choice it was.
    expect($departure->fresh()->status)->toBe(DepartureStatus::Guaranteed)
        ->and(decisionKeys($owner))->not->toContain('departure:' . $departure->getKey())
        ->and(AuditLog::query()->where('action', AuditAction::OverrideApplied->value)
            ->where('context->kind', 'min_pax_waived')->exists())->toBeTrue();

    // The next morning it is still gone.
    Carbon::setTestNow('2026-09-09 08:00:00');
    expect(decisionKeys($owner))->not->toContain('departure:' . $departure->getKey());
})->group('fast');

it('marks an overdue balance paid from the row, as a real payment', function (): void {
    $owner = decisionsOwner();

    $booking = Tenancy::forTenant($owner->tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 12000,
        'paid_cents' => 0,
        'balance_cents' => 12000,
        'balance_due_at' => Carbon::parse('2026-09-01 12:00:00'),
    ]));

    expect(decisionKeys($owner))->toContain('balance:' . $booking->getKey());

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertSee(__('attention.decide.paid'))
        ->callAction('markPaid', data: [
            'amount' => '120.00',
            'gateway' => PaymentGatewayName::Cash->value,
        ], arguments: ['booking' => $booking->getKey()])
        ->assertHasNoActionErrors();

    expect(Payment::query()->where('booking_id', $booking->getKey())->where('amount_cents', 12000)->exists())->toBeTrue()
        ->and($booking->fresh()->balance_cents)->toBe(0)
        ->and(decisionKeys($owner))->not->toContain('balance:' . $booking->getKey());
})->group('fast');

it('draws no decision buttons for somebody who may not take them', function (): void {
    $crew = decisionsOwner(Role::Crew);
    shortDeparture($crew);

    tenancy()->initialize($crew->tenant);
    $this->actingAs($crew);

    expect(NeedsAttention::canDecide())->toBeFalse();

    foreach ((new AttentionItems('Europe/Athens'))->everything() as $item) {
        expect(NeedsAttention::actionFor($item)['decide'] ?? null)->toBeNull();
    }
})->group('fast');

it('calls them things to do, not decisions', function (): void {
    app()->setLocale('el');

    expect(trans_choice('dashboard.home.boxes.attention_count', 3, ['count' => 3]))->toBe('3 εκκρεμότητες');
})->group('fast');
