<?php

declare(strict_types=1);

use App\Domain\Operations\Support\AttentionItems;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Port;
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
        // A port too: without one the dashboard is still on its first steps,
        // and the attention list is not shown at all (25/9: the port is step one).
        Port::factory()->create();
        $vessel = Vessel::factory()->create();
        $departure = Departure::factory()->for($vessel)->at('2026-09-09', '18:00')->withSeats(2)->create();
        $departure->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 8, 'seats_sold' => 2])->save();

        return $departure;
    });
}

/** @return list<string> */
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
    actingAs($crew);

    expect(NeedsAttention::canDecide())->toBeFalse();

    foreach ((new AttentionItems('Europe/Athens'))->everything() as $item) {
        expect(NeedsAttention::actionFor($item)['decide'] ?? null)->toBeNull();
    }
})->group('fast');

it('calls them things to do, not decisions', function (): void {
    app()->setLocale('el');

    expect(trans_choice('dashboard.home.boxes.attention_count', 3, ['count' => 3]))->toBe('3 εκκρεμότητες');
})->group('fast');

/*
| «Κάνω ενέργειες αλλά παραμένουν χωρίς να γίνεται τίποτα» (2026-09-23): the
| count stopped at 8 and the next row slid into the answered one's place.
*/

it('counts every item, not the first eight', function (): void {
    $owner = decisionsOwner();

    Tenancy::forTenant($owner->tenant, function (): void {
        // A port too: without one the dashboard is still on its first steps,
        // and the attention list is not shown at all (25/9: the port is step one).
        Port::factory()->create();
        $vessel = Vessel::factory()->create();

        foreach (range(0, 11) as $i) {
            $departure = Departure::factory()->for($vessel)->at('2026-09-09', sprintf('%02d:00', 6 + $i))->withSeats(2)->create();
            $departure->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 8, 'seats_sold' => 2])->save();
        }
    });

    $count = Tenancy::forTenant($owner->tenant, fn (): int => (new AttentionItems('Europe/Athens'))->count());

    expect($count)->toBe(12)
        ->and(decisionKeys($owner))->toHaveCount(12);
})->group('fast');

it('leaves the answer where the row stood for a moment, then lets it go', function (): void {
    $owner = decisionsOwner();
    $departure = shortDeparture($owner);
    $key = 'departure:' . $departure->getKey();

    tenancy()->initialize($owner->tenant);

    $widget = Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->callAction('sailAnyway', arguments: ['departure' => $departure->getKey()])
        ->assertSet('answered.' . $key . '.outcome', __('attention.done.sailed'))
        ->assertSeeHtml('attention-done-' . $key)
        ->assertSee(__('attention.done.sailed'))
        ->assertDispatched('attention-answered');

    // The list is empty now. The next request used to be a 403 — Filament
    // re-asks canView() on every hydrate — which the browser showed as a black
    // error box, and which ate this very call (2026-09-23).
    $widget->call('forgetAnswer', $key)
        ->assertOk()
        ->assertDontSeeHtml('attention-done-' . $key);

    expect($widget->instance()->answered)->toBe([]);

    // Where an answer goes back: the spot its row stood in.
    $live = static fn (string $k): array => ['item' => (object) ['key' => $k]];
    $widget->instance()->answered = [$key => ['title' => 'Σπηλιές', 'outcome' => 'Φεύγει κανονικά', 'position' => 1]];

    expect(array_map(
        static fn (array $row): string => $row['key'] ?? $row['item']->key,
        $widget->instance()->withAnswered([$live('a'), $live('b')]),
    ))->toBe(['a', $key, 'b'])
        // Still on the list — paid only in part — so no «done» beside it.
        ->and($widget->instance()->withAnswered([$live($key)]))->toHaveCount(1);
})->group('fast');

it('puts cash to hand back on the list, and «Επιστράφηκε» settles it', function (): void {
    $owner = decisionsOwner();

    $refund = Tenancy::forTenant($owner->tenant, function (): Payment {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'total_cents' => 8000,
            'paid_cents' => 8000,
            'balance_cents' => 0,
        ]);

        $cash = Payment::factory()->for($booking)->create([
            'gateway' => PaymentGatewayName::Cash,
            'amount_cents' => 8000,
        ]);

        return Payment::factory()->for($booking)->create([
            'gateway' => PaymentGatewayName::Cash,
            'kind' => PaymentKind::Refund,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 8000,
            'refunds_payment_id' => $cash->getKey(),
        ]);
    });

    $key = 'refund:' . $refund->getKey();

    expect(decisionKeys($owner))->toContain($key);

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertSee(__('attention.decide.refunded'))
        ->callAction('markRefunded', arguments: ['refund' => $refund->getKey()])
        ->assertNotified(__('attention.decide.refunded_done'))
        ->assertSee(__('attention.done.refunded'));

    expect($refund->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($refund->booking->fresh()->status)->toBe(BookingStatus::Refunded)
        ->and(decisionKeys($owner))->not->toContain($key);
})->group('fast');
