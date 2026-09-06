<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CheckInGuest;
use App\Domain\Booking\Actions\MarkNoShow;
use App\Domain\Booking\Data\CheckInOverride;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Events\BookingCheckedIn;
use App\Exceptions\CheckInRefused;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\Booking\CheckInScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| BKG-21, BKG-22, BKG-23: the window, its override, and the trail
|--------------------------------------------------------------------------
|
| > **BKG-22** Check-in is possible from `check_in_offset_minutes` before
| > departure until `ends_at_utc`; earlier check-in requires an explicit
| > operator override which is logged.
|
| Both edges are asserted, and they behave differently on purpose: early is an
| operator decision with a reason attached, late is refused outright. A
| check-in recorded after the boat came back is not an early judgement call —
| it is a false manifest, and BKG-22 offers an override for one edge only.
|
| The audit row is the half most easily left out. "Which is logged" is not a
| log line — a log line rotates away and no operator can read it. It is an
| `override.applied` row beside the refund overrides, which is where somebody
| asking *"why does the manifest say this guest boarded an hour early"* looks.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The scanning crew member, in the booking's own tenant.
 *
 * `checked_in_by_user_id` is a foreign key to `users`, so a crew member from
 * some other tenant would satisfy the column and be a cross-tenant write —
 * exactly what #8's gate exists to catch.
 */
function crewFor(Tenant $tenant): User
{
    return OperatorUser::withRole(Role::Crew, $tenant);
}

it('checks a guest in inside the window', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    // Departure at 09:00 UTC with a thirty-minute offset: the window opened at
    // 08:30... so 08:00 is early. Move to the moment it opens.
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), offsetMinutes: 30);

    Carbon::setTestNow('2026-07-03 08:45:00');

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();

        expect(app(CheckInGuest::class)($guest, crewFor($tenant)))->toBeTrue()
            ->and($guest->refresh()->checked_in_at)->not->toBeNull()
            // BKG-21: *at least one* guest moves the booking.
            ->and($booking->refresh()->status)->toBe(BookingStatus::CheckedIn);
    });
})->group('fast');

it('opens exactly at the offset and not a minute before', function (): void {
    Carbon::setTestNow('2026-07-03 06:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), offsetMinutes: 45);

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();
        $crew = crewFor($tenant);

        // 08:14 — one minute short of the 08:15 opening.
        Carbon::setTestNow('2026-07-03 08:14:00');
        expect(fn () => app(CheckInGuest::class)($guest, $crew))->toThrow(CheckInRefused::class);

        // 08:15 exactly. The boundary is inclusive, because a crew member
        // watching the clock tick over should not have to tap twice.
        Carbon::setTestNow('2026-07-03 08:15:00');
        expect(app(CheckInGuest::class)($guest->refresh(), $crew))->toBeTrue();
    });
})->group('fast');

it('closes when the trip ends, with no way through', function (): void {
    Carbon::setTestNow('2026-07-03 08:00:00');

    // 09:00 start, four hours: ends at 13:00.
    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), durationMinutes: 240);

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();
        $crew = crewFor($tenant);

        Carbon::setTestNow('2026-07-03 13:00:00');
        expect(app(CheckInGuest::class)($guest, $crew))->toBeTrue();

        $second = $booking->guests()->where('position', 2)->first();

        Carbon::setTestNow('2026-07-03 13:00:01');

        // And an override does not rescue it — the parameter exists and the
        // late edge ignores it, which is the asymmetry BKG-22 asks for.
        expect(fn () => app(CheckInGuest::class)($second, $crew, new CheckInOverride('boat came back late')))
            ->toThrow(CheckInRefused::class);
    });
})->group('fast');

it('refuses an early check-in that carries no override', function (): void {
    Carbon::setTestNow('2026-07-03 06:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), offsetMinutes: 30);

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();

        // Caught rather than `->toThrow()`, because the *message* is half the
        // assertion — and `$this->fail()` is not available inside a Pest
        // closure (`$this` is a `TestCall` at analysis time), which is the
        // lesson #80 paid for.
        $refused = null;

        try {
            app(CheckInGuest::class)($guest, crewFor($tenant));
        } catch (CheckInRefused $caught) {
            $refused = $caught;
        }

        expect($refused)->toBeInstanceOf(CheckInRefused::class)
            ->and($refused?->reason)->toBe('window_not_open')
            // CNV-11: a sentence from a lang file, telling the crew member
            // what to do next — not "check-in failed".
            ->and($refused?->getMessage())->not->toBe('')
            // 08:30 UTC, and the sentence says **11:30** — CNV-2, the tenant's
            // own clock. A crew member on a pier in Naxos reads the time on
            // their watch, and a message in UTC would send them away three
            // hours early.
            ->and($refused?->getMessage())->toContain('11:30');
    });
})->group('fast');

it('writes an override.applied row when crew check in early', function (): void {
    Carbon::setTestNow('2026-07-03 06:00:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), offsetMinutes: 30);

    // 07:30 — one hour before the window opens at 08:30.
    Carbon::setTestNow('2026-07-03 07:30:00');

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();

        app(CheckInGuest::class)(
            $guest,
            crewFor($tenant),
            new CheckInOverride('Ο επιβάτης ήρθε νωρίς και το σκάφος είναι δεμένο'),
        );

        $row = AuditLog::query()->where('action', AuditAction::OverrideApplied->value)->sole();

        expect($row->reason)->toContain('νωρίς')
            // The minutes are the number worth keeping: "four minutes" and
            // "four hours" are different decisions, and neither is legible from
            // a timestamp pair somebody has to subtract by hand a year later.
            ->and($row->context['minutes_early'] ?? null)->toBe(60)
            ->and($row->context['kind'] ?? null)->toBe('check_in_early');
    });
})->group('fast');

it('refuses to build an override with no reason', function (): void {
    // BKG-22's "explicit" enforced by the constructor rather than by a form —
    // the API and the console command skip a Filament validation rule.
    expect(fn () => new CheckInOverride('   '))->toThrow(InvalidArgumentException::class);
})->group('fast');

it('is a no-op on a second scan of the same ticket', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();
        $crew = crewFor($tenant);

        app(CheckInGuest::class)($guest, $crew);

        $first = $guest->refresh()->checked_in_at;

        Carbon::setTestNow('2026-07-03 08:52:00');

        // A phone camera fires as fast as it focuses. The second scan must not
        // move the only record of when somebody actually boarded.
        expect(app(CheckInGuest::class)($guest->refresh(), $crew))->toBeFalse()
            ->and($guest->refresh()->checked_in_at->toDateTimeString())->toBe($first->toDateTimeString());
    });
})->group('fast');

it('fires the booking event once however many guests are scanned', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), pax: 2);

    Event::fake([BookingCheckedIn::class]);

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $crew = crewFor($tenant);

        foreach ($booking->guests()->orderBy('position')->get() as $guest) {
            app(CheckInGuest::class)($guest, $crew);
        }
    });

    // BKG-21 transitions on the *first* guest. A second event would mean every
    // listener — a manifest export, an outbound webhook — ran twice.
    Event::assertDispatchedTimes(BookingCheckedIn::class, 1);
})->group('fast');

it('refuses a booking that is not confirmed', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(
        Carbon::parse('2026-07-03 09:00:00'),
        status: BookingStatus::Cancelled,
    );

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();

        $refused = null;

        try {
            app(CheckInGuest::class)($guest, crewFor($tenant));
        } catch (CheckInRefused $caught) {
            $refused = $caught;
        }

        expect($refused)->toBeInstanceOf(CheckInRefused::class)
            ->and($refused?->reason)->toBe('status');
    });
})->group('fast');

it('marks a no-show per guest and moves no money', function (): void {
    Carbon::setTestNow('2026-07-03 13:30:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), pax: 2);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $guest = $booking->guests()->where('position', 2)->first();

        app(MarkNoShow::class)->forGuest($guest);

        expect($guest->refresh()->no_show)->toBeTrue()
            // BKG-23: *"does not itself trigger any refund logic"*. The other
            // guest, the booking's status and its money are all untouched.
            ->and($booking->refresh()->no_show)->toBeFalse()
            ->and($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->refunded_cents)->toBe(0)
            ->and($booking->guests()->where('position', 1)->first()->no_show)->toBeFalse();
    });
})->group('fast');

it('reverses a no-show, which is the whole of BKG-23s reversibility', function (): void {
    Carbon::setTestNow('2026-07-03 13:30:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $guest = $booking->guests()->first();

        app(MarkNoShow::class)->forGuest($guest);
        app(MarkNoShow::class)->forGuest($guest->refresh(), false);

        expect($guest->refresh()->no_show)->toBeFalse();
    });
})->group('fast');

it('marks the booking and everybody on it when nobody came', function (): void {
    Carbon::setTestNow('2026-07-03 13:30:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'), pax: 2);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(MarkNoShow::class)->forBooking($booking);

        // The two halves cannot disagree: a booking flagged as a no-show whose
        // guests are not is a manifest that contradicts itself, and the
        // manifest is what a coastguard inspection reads.
        expect($booking->refresh()->no_show)->toBeTrue()
            ->and(BookingGuest::query()->where('booking_id', $booking->getKey())->where('no_show', false)->count())
            ->toBe(0);
    });
})->group('fast');

it('clears a no-show when the guest turns up after all', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $guest = $booking->guests()->first();

        app(MarkNoShow::class)->forGuest($guest);

        app(CheckInGuest::class)($guest->refresh(), crewFor($tenant));

        // A guest who boards is not a no-show, whatever anybody marked
        // earlier — the one reversal that needs no operator.
        expect($guest->refresh()->no_show)->toBeFalse()
            ->and($guest->checked_in_at)->not->toBeNull();
    });
})->group('fast');

it('records who scanned the ticket', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, function () use ($tenant, $booking): void {
        $crew = crewFor($tenant);

        app(CheckInGuest::class)($booking->guests()->first(), $crew);

        // §2.5's `checked_in_by_user_id`. Without it "who let this person
        // aboard" has no answer, and it is the first question after an
        // incident.
        expect(Booking::query()->find($booking->getKey())->guests()->first()->checked_in_by_user_id)
            ->toBe($crew->getKey());
    });
})->group('fast');
