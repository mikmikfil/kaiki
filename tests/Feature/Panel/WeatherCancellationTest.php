<?php

declare(strict_types=1);

use App\Domain\Operations\Support\WeatherCancellationPreview;
use App\Enums\BookingStatus;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Enums\Role;
use App\Enums\WeatherChoice;
use App\Filament\App\Resources\DepartureResource\Pages\ListDepartures;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-6, OPS-7: the preview, and then one confirmed action
|--------------------------------------------------------------------------
|
| The sending has existed since #84. What this issue adds is the screen an
| operator needs at nine in the evening with a forecast on their phone — and the
| thing that makes it worth building is CXL-6: **each booking's own policy
| snapshot**. Two guests on the same boat, booked in different months under a
| policy since edited, are owed different proportions of what they paid. A
| preview that read the current policy would show one figure for both, the
| operator would approve it, and the refunds would disagree with the screen they
| approved.
|
| The other thing that must hold is idempotency. A double click, a browser retry
| or a re-selected sailing must not open a second entitlement.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-08 21:00:00');
});

function departuresPageAs(User $user): Testable
{
    tenancy()->initialize($user->tenant);

    return Livewire::actingAs($user)->test(ListDepartures::class);
}

it('shows each booking its own entitlement, from its own snapshot', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(4)->create();

        // Booked under a policy that gave 100% back for weather.
        $generous = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Anna',
            'total_cents' => 10000,
            'paid_cents' => 10000,
            'balance_cents' => 0,
            'pax_total' => 2,
        ]);

        // And one booked under a policy that gave half.
        $mean = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Boris',
            'total_cents' => 10000,
            'paid_cents' => 10000,
            'balance_cents' => 0,
            'pax_total' => 1,
        ]);

        $snapshot = fn (int $percent): array => [
            'name' => ['el' => 'Πολιτική', 'en' => 'Policy'],
            'weather_refund_percent' => $percent,
            'free_cancellation_hours' => 0,
            'tiers' => [],
        ];

        $generous->forceFill(['policy_snapshot' => $snapshot(100)])->save();
        $mean->forceFill(['policy_snapshot' => $snapshot(50)])->save();

        return $sailing;
    });

    $preview = Tenancy::forTenant(
        $user->tenant,
        fn (): WeatherCancellationPreview => WeatherCancellationPreview::for(new Collection([$departure])),
    );

    expect($preview->guests())->toBe(2)
        ->and($preview->pax())->toBe(3)
        ->and($preview->paidCents())->toBe(20000);

    $byGuest = [];

    foreach ($preview->rows as $row) {
        $byGuest[$row['guest']] = $row['refund_cents'];
    }

    // The whole point of CXL-6: two guests on one boat, two different answers.
    expect($byGuest['Anna'])->toBe(10000)
        ->and($byGuest['Boris'])->toBe(5000)
        ->and($preview->refundCents())->toBe(15000);
});

it('counts an already-cancelled sailing as affecting nobody rather than as an error', function (): void {
    // Selecting yesterday's cancelled sailing along with tomorrow's is an
    // ordinary mis-click on a list.
    $user = OperatorUser::withRole(Role::Owner);

    $departures = Tenancy::forTenant($user->tenant, function (): Collection {
        $gone = Departure::factory()->at('2026-07-07', '09:00')->cancelled()->create();
        $live = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        Booking::factory()->create([
            'departure_id' => $gone->getKey(),
            'status' => BookingStatus::Confirmed,
            'paid_cents' => 5000,
        ]);

        Booking::factory()->create([
            'departure_id' => $live->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Chris',
            'paid_cents' => 5000,
        ]);

        return new Collection([$gone, $live]);
    });

    $preview = Tenancy::forTenant(
        $user->tenant,
        fn (): WeatherCancellationPreview => WeatherCancellationPreview::for($departures),
    );

    expect($preview->departures)->toBe(1)
        ->and($preview->alreadyCancelled)->toBe(1)
        ->and($preview->guests())->toBe(1)
        ->and($preview->rows[0]['guest'])->toBe('Chris');
});

it('never lists a guest the cancellation would skip', function (): void {
    // A guest on the screen who then hears nothing is worse than one who was
    // never on it, so the preview uses the same predicate the action does.
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Cancelled,
            'guest_name' => 'Already gone',
            'paid_cents' => 5000,
        ]);

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Still coming',
            'paid_cents' => 5000,
        ]);

        return $sailing;
    });

    $preview = Tenancy::forTenant(
        $user->tenant,
        fn (): WeatherCancellationPreview => WeatherCancellationPreview::for(new Collection([$departure])),
    );

    expect($preview->guests())->toBe(1)
        ->and($preview->rows[0]['guest'])->toBe('Still coming');
});

it('cancels the selection, opens the choice, and moves no money yet', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'total_cents' => 8000,
            'paid_cents' => 8000,
            'balance_cents' => 0,
        ]);

        return $sailing;
    });

    departuresPageAs($user)
        ->callTableBulkAction('cancel_weather', [$departure], [
            'default_choice' => WeatherChoice::Voucher->value,
            'note' => '7 Beaufort',
        ]);

    Tenancy::forTenant($user->tenant, function () use ($departure): void {
        $departure->refresh();

        expect($departure->status)->toBe(DepartureStatus::Cancelled)
            ->and($departure->cancel_reason)->toBe(DepartureCancelReason::Weather)
            ->and($departure->cancellation_note)->toBe('7 Beaufort');

        $booking = Booking::query()->where('departure_id', $departure->getKey())->firstOrFail();

        expect($booking->status)->toBe(BookingStatus::Cancelled)
            // CXL-7: the clock is started and the money is not moved. Nothing is
            // refunded until the guest answers or the deadline answers for them.
            ->and($booking->weather_choice_due_at)->not->toBeNull()
            ->and(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });

    // The operator's answer to "what if they never reply", saved where the
    // guest's own page reads it.
    expect($user->tenant->refresh()->weather_choice_default)->toBe(WeatherChoice::Voucher->value);
});

it('does the same thing twice without opening a second entitlement', function (): void {
    // A double click, a browser retry, or the same sailing selected again in a
    // second sweep of the list.
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'total_cents' => 8000,
            'paid_cents' => 8000,
            'balance_cents' => 0,
        ]);

        return $sailing;
    });

    $call = fn () => departuresPageAs($user)->callTableBulkAction('cancel_weather', [$departure], [
        'default_choice' => WeatherChoice::Refund->value,
    ]);

    $call();

    $due = Tenancy::forTenant(
        $user->tenant,
        fn (): ?Carbon => Booking::query()->where('departure_id', $departure->getKey())->first()?->weather_choice_due_at,
    );

    $call();

    $again = Tenancy::forTenant(
        $user->tenant,
        fn (): ?Carbon => Booking::query()->where('departure_id', $departure->getKey())->first()?->weather_choice_due_at,
    );

    // The same deadline, not a fresh fourteen days — and still no payment rows.
    expect($again?->toDateTimeString())->toBe($due?->toDateTimeString())
        ->and(Tenancy::forTenant($user->tenant, fn (): int => Payment::query()->count()))->toBe(0);
});

it('gives crew no way to cancel anybody else trip', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    departuresPageAs($crew)->assertTableBulkActionHidden('cancel_weather');
});

it('puts the names and the figures in front of the operator before the button', function (): void {
    // The modal itself, rendered. `callTableBulkAction` skips it entirely, so a
    // preview that threw on render would pass every test above — and the preview
    // is the whole feature.
    $user = OperatorUser::withRole(Role::Owner);

    $departure = Tenancy::forTenant($user->tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(2)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Δήμητρα Νικολάου',
            'total_cents' => 9000,
            'paid_cents' => 9000,
            'balance_cents' => 0,
        ]);

        return $sailing;
    });

    departuresPageAs($user)
        ->mountTableBulkAction('cancel_weather', [$departure])
        ->assertSee('Δήμητρα Νικολάου')
        ->assertSee(__('availability.departure.weather.what_happens'));
});
