<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\AnalyticsFigures;
use App\Domain\Analytics\Support\LocalRange;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The statistics page, figure by figure
|--------------------------------------------------------------------------
|
| Every expected number here is arithmetic a reader can check by hand, for the
| reason `DashboardFiguresTest` gives and that applies twice as hard to a page
| of reports: the failure mode is not an exception, it is a number that is
| wrong by a little and looks exactly like a number that is right.
|
| The three bases are what these tests mostly pin, because they are what an
| operator will trip over. The same booking can be a June sale, an August
| sailing and a July payment, and each block on the page has to pick one and
| say which:
|
| - revenue is money received, by `paid_at`, minus refunds;
| - bookings and passengers are sales made, by `created_at`;
| - occupancy is boats that sailed, by the departure's local date.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-20 10:00:00');
});

/** @return array{0: Tenant, 1: AnalyticsFigures} */
function analytics(?string $timezone = null): array
{
    $tenant = Tenant::factory()->create(['timezone' => $timezone ?? 'Europe/Athens']);

    return [$tenant, new AnalyticsFigures($tenant->timezone)];
}

function august(): LocalRange
{
    return LocalRange::between('2026-08-01', '2026-08-31', 'Europe/Athens');
}

it('counts revenue as payments received minus refunds, in the operator calendar month', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create();

        // 1 August at 00:30 in Athens is 31 July at 21:30 UTC. It is August for
        // the operator and July for a naive boundary, and the operator is the
        // one reconciling against a bank statement.
        Payment::factory()->for($booking)->create([
            'amount_cents' => 20000,
            'paid_at' => Carbon::parse('2026-07-31 21:30:00', 'UTC'),
        ]);

        Payment::factory()->for($booking)->create([
            'amount_cents' => 5000,
            'paid_at' => Carbon::parse('2026-08-10 09:00:00', 'UTC'),
        ]);

        // Subtracted, not excluded: it is what the bank will show for August.
        Payment::factory()->for($booking)->create([
            'kind' => PaymentKind::Refund,
            'amount_cents' => 3000,
            'paid_at' => Carbon::parse('2026-08-12 09:00:00', 'UTC'),
        ]);

        // Outside the month, and a payment that never succeeded.
        Payment::factory()->for($booking)->create([
            'amount_cents' => 90000,
            'paid_at' => Carbon::parse('2026-09-02 09:00:00', 'UTC'),
        ]);

        Payment::factory()->for($booking)->create([
            'status' => PaymentStatus::Failed,
            'amount_cents' => 70000,
            'paid_at' => null,
        ]);
    });

    expect(Tenancy::forTenant($tenant, fn (): int => $figures->revenue(august())))->toBe(22000);
})->group('fast');

it('keeps test bookings out of the money, which is SAA-12 and the worst morning to get wrong', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        $real = Booking::factory()->create(['is_test' => false]);
        $sandbox = Booking::factory()->create(['is_test' => true]);

        foreach ([$real, $sandbox] as $booking) {
            Payment::factory()->for($booking)->create([
                'amount_cents' => 10000,
                'paid_at' => Carbon::parse('2026-08-10 09:00:00', 'UTC'),
            ]);
        }
    });

    expect(Tenancy::forTenant($tenant, fn (): int => $figures->revenue(august())))->toBe(10000);
})->group('fast');

it('counts sales by when the booking was made, and only the ones that are real sales', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'pax_total' => 4,
            'total_cents' => 12000,
            'created_at' => Carbon::parse('2026-08-03 08:00:00', 'UTC'),
        ]);

        Booking::factory()->create([
            'status' => BookingStatus::Completed,
            'pax_total' => 2,
            'total_cents' => 8000,
            'created_at' => Carbon::parse('2026-08-19 08:00:00', 'UTC'),
        ]);

        // A fifteen-minute hold and a checkout in flight. Neither is a sale,
        // and a report that counted them would climb every time somebody
        // opened the booking form.
        Booking::factory()->create([
            'status' => BookingStatus::Draft,
            'pax_total' => 9,
            'total_cents' => 90000,
            'created_at' => Carbon::parse('2026-08-05 08:00:00', 'UTC'),
        ]);

        Booking::factory()->create([
            'status' => BookingStatus::PendingPayment,
            'pax_total' => 9,
            'total_cents' => 90000,
            'created_at' => Carbon::parse('2026-08-05 08:00:00', 'UTC'),
        ]);

        // Made in July, for an August sailing. It is a July sale.
        Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'pax_total' => 6,
            'total_cents' => 30000,
            'created_at' => Carbon::parse('2026-07-30 08:00:00', 'UTC'),
        ]);
    });

    $sales = Tenancy::forTenant($tenant, fn (): array => $figures->sales(august()));

    expect($sales)->toBe(['bookings' => 2, 'pax' => 6, 'value' => 20000])
        ->and(Tenancy::forTenant($tenant, fn (): ?int => $figures->averageBooking(august())))->toBe(10000);
})->group('fast');

it('has no average to report when nothing was sold', function (): void {
    [$tenant, $figures] = analytics();

    // Null rather than zero: "nobody booked" and "the average was nothing" are
    // different facts, and a zero on a page of money reads as the second.
    expect(Tenancy::forTenant($tenant, fn (): ?int => $figures->averageBooking(august())))->toBeNull();
})->group('fast');

it('draws the quiet days as zeros rather than leaving them out', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create();

        Payment::factory()->for($booking)->create([
            'amount_cents' => 4000,
            'paid_at' => Carbon::parse('2026-08-03 09:00:00', 'UTC'),
        ]);
    });

    $series = Tenancy::forTenant($tenant, fn (): array => $figures->series(august()));

    // Thirty-one days, one of them with money on it. A chart that omitted the
    // other thirty would draw a straight line through the quiet fortnight the
    // operator most wants to see.
    expect($series)->toHaveCount(31)
        ->and($series[0]['bucket'])->toBe('2026-08-01')
        ->and($series[2]['revenue'])->toBe(4000)
        ->and($series[3]['revenue'])->toBe(0);
})->group('fast');

it('groups a long range into weeks and a very long one into months', function (): void {
    $quarter = LocalRange::between('2026-01-01', '2026-06-30', 'Europe/Athens');
    $threeYears = LocalRange::between('2024-01-01', '2026-12-31', 'Europe/Athens');

    expect(august()->grain())->toBe(LocalRange::GRAIN_DAY)
        ->and($quarter->grain())->toBe(LocalRange::GRAIN_WEEK)
        ->and($threeYears->grain())->toBe(LocalRange::GRAIN_MONTH)
        // Mondays, because a boundary that follows the locale moves when
        // somebody changes a config and last week stops matching itself.
        ->and($quarter->bucketOf('2026-01-08'))->toBe('2026-01-05')
        ->and($threeYears->bucketOf('2024-03-17'))->toBe('2024-03-01');
})->group('fast');

it('splits the money per trip without multiplying the passengers by the instalments', function (): void {
    [$tenant, $figures] = analytics();

    // The label follows the operator's own language, so the test says which.
    app()->setLocale('el');

    Tenancy::forTenant($tenant, function (): void {
        $sunset = Product::factory()->create(['title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset']]);
        $morning = Product::factory()->create(['title' => ['el' => 'Πρωινή', 'en' => 'Morning']]);

        $first = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'product_id' => $sunset->getKey(),
            'pax_total' => 4,
            'created_at' => Carbon::parse('2026-08-04 08:00:00', 'UTC'),
        ]);

        // Two instalments on one booking. The classic fan-out: joined naively,
        // this booking's four passengers would be counted twice.
        Payment::factory()->for($first)->create([
            'amount_cents' => 6000,
            'paid_at' => Carbon::parse('2026-08-04 09:00:00', 'UTC'),
        ]);
        Payment::factory()->for($first)->create([
            'amount_cents' => 4000,
            'paid_at' => Carbon::parse('2026-08-14 09:00:00', 'UTC'),
        ]);

        $second = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'product_id' => $morning->getKey(),
            'pax_total' => 2,
            'created_at' => Carbon::parse('2026-08-06 08:00:00', 'UTC'),
        ]);

        Payment::factory()->for($second)->create([
            'amount_cents' => 3000,
            'paid_at' => Carbon::parse('2026-08-06 09:00:00', 'UTC'),
        ]);
    });

    $rows = Tenancy::forTenant($tenant, fn (): array => $figures->byProduct(august()));

    expect($rows)->toHaveCount(2)
        // Sorted by what they earned.
        ->and($rows[0]['label'])->toBe('Ηλιοβασίλεμα')
        ->and($rows[0]['revenue'])->toBe(10000)
        ->and($rows[0]['bookings'])->toBe(1)
        ->and($rows[0]['pax'])->toBe(4)
        ->and($rows[1]['revenue'])->toBe(3000);
})->group('fast');

it('measures occupancy on what sailed, and leaves cancelled boats out of it', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->at('2026-08-05', '09:00')->withSeats(6)->create(['capacity' => 10]);
        Departure::factory()->at('2026-08-06', '09:00')->withSeats(2)->create(['capacity' => 10]);
        // A boat that did not go has no empty seats to account for: counting
        // its capacity would make a cancelled week look like a badly sold one.
        Departure::factory()->at('2026-08-07', '09:00')->withSeats(0)->cancelled()->create(['capacity' => 10]);
        // Outside the month.
        Departure::factory()->at('2026-09-01', '09:00')->withSeats(0)->create(['capacity' => 10]);
    });

    $occupancy = Tenancy::forTenant($tenant, fn (): array => $figures->occupancy(august()));

    expect($occupancy['departures'])->toBe(2)
        ->and($occupancy['capacity'])->toBe(20)
        ->and($occupancy['sold'])->toBe(8)
        ->and($occupancy['rate'])->toBe(0.4);
})->group('fast');

it('leaves boats that have not sailed yet out of occupancy', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->at('2026-08-05', '09:00')->withSeats(8)->create(['capacity' => 10]);
        // Ten days ahead, inside the month, and almost empty because nobody has
        // booked it yet. Counted, it drags August down to 40% and every
        // forward-looking period reads as a disaster — which is how this was
        // found, on a page showing 0.4% for a nearly full summer.
        Departure::factory()->at('2026-08-30', '09:00')->withSeats(0)->create(['capacity' => 10]);
    });

    $occupancy = Tenancy::forTenant($tenant, fn (): array => $figures->occupancy(august()));

    expect($occupancy['departures'])->toBe(1)
        ->and($occupancy['capacity'])->toBe(10)
        ->and($occupancy['rate'])->toBe(0.8);

    // And the same filter keeps next week's empty boats off the list of things
    // to act on, which would otherwise be a schedule rather than a finding.
    expect(Tenancy::forTenant($tenant, fn (): array => $figures->quietSailings(august())))->toBe([]);
})->group('fast');

it('says nothing sailed rather than saying the boats were empty', function (): void {
    [$tenant, $figures] = analytics();

    expect(Tenancy::forTenant($tenant, fn (): array => $figures->occupancy(august()))['rate'])->toBeNull();
})->group('fast');

it('lists the emptiest sailings first, because an empty seat cannot be sold afterwards', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        Departure::factory()->at('2026-08-05', '09:00')->withSeats(1)->create(['capacity' => 10]);
        Departure::factory()->at('2026-08-06', '18:00')->withSeats(4)->create(['capacity' => 10]);
        // Better than half full: not what this list is for.
        Departure::factory()->at('2026-08-07', '09:00')->withSeats(9)->create(['capacity' => 10]);
    });

    $quiet = Tenancy::forTenant($tenant, fn (): array => $figures->quietSailings(august()));

    expect($quiet)->toHaveCount(2)
        ->and($quiet[0]['date'])->toBe('2026-08-05')
        ->and($quiet[0]['sold'])->toBe(1)
        ->and($quiet[0]['time'])->toBe('09:00')
        ->and($quiet[1]['date'])->toBe('2026-08-06');
})->group('fast');

it('groups bookings by the channel they came through, with no tracking involved', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        foreach ([BookingSource::Widget, BookingSource::Widget, BookingSource::Hosted] as $source) {
            Booking::factory()->create([
                'status' => BookingStatus::Confirmed,
                'source' => $source,
                'pax_total' => 2,
                'total_cents' => 5000,
                'created_at' => Carbon::parse('2026-08-04 08:00:00', 'UTC'),
            ]);
        }
    });

    $rows = Tenancy::forTenant($tenant, fn (): array => $figures->bySource(august()));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['source'])->toBe('widget')
        ->and($rows[0]['bookings'])->toBe(2)
        ->and($rows[0]['value'])->toBe(10000)
        ->and($rows[1]['source'])->toBe('hosted');
})->group('fast');

it('reduces a referrer to the site it came from, not the page', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        foreach ([
            'https://www.travel-blog.example/posts/aegina-in-a-day',
            'https://travel-blog.example/posts/another',
            'https://maps.example/place/42',
        ] as $referrer) {
            Booking::factory()->create([
                'status' => BookingStatus::Confirmed,
                'referrer_url' => $referrer,
                'created_at' => Carbon::parse('2026-08-04 08:00:00', 'UTC'),
            ]);
        }
    });

    $rows = Tenancy::forTenant($tenant, fn (): array => $figures->byReferrer(august()));

    // Two pages of one blog are one site, and `www.` is not a different one.
    expect($rows[0])->toBe(['value' => 'travel-blog.example', 'bookings' => 2])
        ->and($rows[1]['value'])->toBe('maps.example');
})->group('fast');

it('computes the cancellation rate against the bookings that were cancelled too', function (): void {
    [$tenant, $figures] = analytics();

    Tenancy::forTenant($tenant, function (): void {
        foreach ([BookingStatus::Confirmed, BookingStatus::Confirmed, BookingStatus::Completed] as $status) {
            Booking::factory()->create([
                'status' => $status,
                'created_at' => Carbon::parse('2026-08-04 08:00:00', 'UTC'),
            ]);
        }

        Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'created_at' => Carbon::parse('2026-08-05 08:00:00', 'UTC'),
        ]);
    });

    $cancellations = Tenancy::forTenant($tenant, fn (): array => $figures->cancellations(august()));

    // One in four, not one in three: a rate computed against the survivors
    // would fall as cancellations rose.
    expect($cancellations['cancelled'])->toBe(1)
        ->and($cancellations['total'])->toBe(4)
        ->and($cancellations['rate'])->toBe(0.25);
})->group('fast');

it('compares a period against the one before it and the same dates last year', function (): void {
    $range = august();

    expect($range->days())->toBe(31)
        ->and($range->previous()->startLocalDate)->toBe('2026-07-01')
        ->and($range->previous()->endLocalDate)->toBe('2026-07-31')
        ->and($range->lastYear()->startLocalDate)->toBe('2025-08-01')
        ->and($range->lastYear()->endLocalDate)->toBe('2025-08-31');
})->group('fast');

it('reads a range given backwards as the range somebody meant', function (): void {
    $range = LocalRange::between('2026-08-31', '2026-08-01', 'Europe/Athens');

    // An empty page would leave the operator wondering which of their two dates
    // was wrong.
    expect($range->startLocalDate)->toBe('2026-08-01')
        ->and($range->endLocalDate)->toBe('2026-08-31');
})->group('fast');
