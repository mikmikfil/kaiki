<?php

declare(strict_types=1);

use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\AttentionSeverity;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\GuestDetailsStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| OPS-1: the decisions waiting on a person
|--------------------------------------------------------------------------
|
| Two properties matter more than the individual rows.
|
| **The order is by deadline, never by severity.** A "decide" badge on something
| due Friday, sitting above a boat that sails in three hours, makes a list you
| have to read in full — which is the same as not having one.
|
| **The definitions match the dashboard figures.** A figure saying "3 at risk"
| above a list showing four is worse than either alone, so the predicates here
| are the ones `DashboardFigures` already uses.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
});

function attentionFixture(): Tenant
{
    return Tenant::factory()->create(['timezone' => 'Europe/Athens']);
}

/** @return list<AttentionItem> */
function itemsFor(Tenant $tenant): array
{
    return Tenancy::forTenant($tenant, fn (): array => (new AttentionItems($tenant->timezone))->all());
}

it('lists a sailing that will not reach its minimum', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα']);

        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->create();

        $departure->forceFill([
            'status' => DepartureStatus::Scheduled,
            'min_pax' => 6,
            'seats_sold' => 2,
        ])->save();
    });

    $items = itemsFor($tenant);

    expect($items)->toHaveCount(1)
        // A boat sails or does not, and only a person can say which.
        ->and($items[0]->severity)->toBe(AttentionSeverity::Critical)
        ->and($items[0]->detail)->toContain('2')
        ->and($items[0]->detail)->toContain('6');
});

it('leaves a short sailing alone once it is far enough away', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();

        // Three weeks out. Still short, and still nobody's decision today —
        // the same 48-hour window the dashboard figure uses.
        $departure = Departure::factory()->for($vessel)->at('2026-09-29', '18:00')->create();

        $departure->forceFill([
            'status' => DepartureStatus::Scheduled,
            'min_pax' => 6,
            'seats_sold' => 2,
        ])->save();
    });

    expect(itemsFor($tenant))->toBe([]);
});

it('orders by deadline and not by severity', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();

        // A "decide" row, but not until tomorrow evening.
        $late = Departure::factory()->for($vessel)->at('2026-09-09', '20:00')->create();
        $late->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 6, 'seats_sold' => 1])->save();

        // A "chase" row, overdue since last week.
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Anna Rossi',
            'balance_cents' => 4000,
            'balance_due_at' => Carbon::parse('2026-09-01 12:00:00'),
        ]);
    });

    $items = itemsFor($tenant);

    // The overdue money is first even though the short sailing is louder.
    expect($items)->toHaveCount(2)
        ->and($items[0]->severity)->toBe(AttentionSeverity::Warning)
        ->and($items[1]->severity)->toBe(AttentionSeverity::Critical);
});

it('sorts an item with no deadline last, never first', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα']);

        IcalSource::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'url' => 'https://example.test/broken.ics',
            'consecutive_failures' => 5,
        ]);

        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->create();
        $departure->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 6, 'seats_sold' => 1])->save();
    });

    $items = itemsFor($tenant);

    // A null deadline sorting as zero would put the calendar above a boat
    // leaving in nine hours — the exact inversion the list exists to avoid.
    expect($items)->toHaveCount(2)
        ->and($items[0]->deadline)->not->toBeNull()
        ->and($items[1]->deadline)->toBeNull();
});

it('chases passenger details only when the boat is about to leave', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        // Sailing tomorrow, details still missing: the reminders have not
        // worked and somebody has to pick up a phone.
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'guest_details_status' => GuestDetailsStatus::Pending,
            'starts_at_utc' => Carbon::parse('2026-09-09 06:00:00'),
        ]);

        // Sailing next month. The automatic reminders own this one.
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'guest_details_status' => GuestDetailsStatus::Pending,
            'starts_at_utc' => Carbon::parse('2026-10-09 06:00:00'),
        ]);
    });

    expect(itemsFor($tenant))->toHaveCount(1);
});

it('never lists a test booking', function (): void {
    $tenant = attentionFixture();

    Tenancy::forTenant($tenant, function (): void {
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'is_test' => true,
            'balance_cents' => 9900,
            'balance_due_at' => Carbon::parse('2026-09-01 12:00:00'),
        ]);
    });

    // The same exclusion every dashboard figure makes. A list that disagreed
    // with the figure above it would discredit both.
    expect(itemsFor($tenant))->toBe([]);
});

it('shows one operator nothing of another\'s', function (): void {
    $mine = attentionFixture();
    $theirs = attentionFixture();

    Tenancy::forTenant($theirs, function (): void {
        $vessel = Vessel::factory()->create();
        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->create();
        $departure->forceFill(['status' => DepartureStatus::Scheduled, 'min_pax' => 6, 'seats_sold' => 1])->save();
    });

    expect(itemsFor($mine))->toBe([])
        ->and(itemsFor($theirs))->toHaveCount(1);
});
