<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Events\BookingConfirmed;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Api\BookingApiScenario;

/*
|--------------------------------------------------------------------------
| BKG-34: a booking that happened somewhere else
|--------------------------------------------------------------------------
|
| > Imported bookings (source `import`) are created in **`confirmed`** with a
| > synthetic price snapshot derived from the source data, are flagged as
| > imported in the panel, and **MUST NOT trigger confirmation notifications,
| > invoices or webhooks**.
|
| The last clause is the whole design. An operator migrating a season out of a
| spreadsheet would otherwise email four hundred guests a confirmation for a
| trip they booked in March, text them all, issue four hundred myDATA invoices
| and fire four hundred webhooks at whatever their old system still has running.
|
| That is not a bad first day. It is the kind of first day an operator leaves
| over — and the only way to guarantee it is **not to dispatch the event**, so
| the assertion here is about the absence of a line rather than the behaviour of
| nine listeners.
|
*/

beforeEach(function (): void {
    // Inline, deliberately: a faked queue would make "nothing was dispatched"
    // true by construction. The point is that nothing *tries*.
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param array<string, mixed> $fixture */
function importDraft(array $fixture): BookingDraftData
{
    return new BookingDraftData(
        product: $fixture['product'],
        date: $fixture['departure']->local_date->copy(),
        guestName: 'Ελένη Δημητρίου',
        guestEmail: 'eleni@example.gr',
        guestPhone: '+306945555555',
        paxByCode: ['adult' => 2],
    );
}

it('creates an imported booking confirmed', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->confirmed_at)->not->toBeNull()
            // "flagged as imported in the panel" — the source is the flag, and
            // `BookingResource`'s table badges it.
            ->and($booking->source)->toBe(BookingSource::Import);
    });
})->group('fast');

it('dispatches nothing at all', function (): void {
    $fixture = BookingApiScenario::bookable();

    // `Event::fake([BookingConfirmed::class])`, and **not** a bare
    // `Event::fake()`. Faking every event also silences Eloquent's own model
    // events — including the `creating` hook `BelongsToTenant` stamps
    // `tenant_id` in — so the bare form fails on a NOT NULL constraint before
    // it can assert anything. A test that is "too strict" in the wrong place
    // stops testing the thing it was written for.
    Event::fake([BookingConfirmed::class]);
    Queue::fake();
    Bus::fake();
    Mail::fake();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );
    });

    // Not "no confirmation email was sent" — **nothing**. Every one of BKG-13's
    // nine listeners hangs off `BookingConfirmed`, so a flag each of them had to
    // check would be nine places to remember and nine places for the tenth
    // listener to forget.
    Event::assertNotDispatched(BookingConfirmed::class);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
})->group('fast');

it('keeps the figures it was given rather than recomputing them', function (): void {
    // The fixture prices an adult at €65, so the engine would say €130 for two.
    // The source system says €90 — a rate plan that no longer exists, in a
    // season that has been edited since.
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 4500,
            departure: $fixture['departure'],
        );

        // Running today's engine over last March's booking would produce a
        // total that is confidently wrong. The guest paid what they paid.
        expect($booking->total_cents)->toBe(9000)
            ->and($booking->paid_cents)->toBe(4500)
            ->and($booking->balance_cents)->toBe(4500);
    });
})->group('fast');

it('says in the snapshot that the figures were given, not derived', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
            sourceData: ['system' => 'FareHarbor', 'reference' => 'FH-88213'],
        );

        $snapshot = $booking->price_snapshot;

        // `"source": "import"` is not decoration. The snapshot is read later by
        // the invoice, by the refund calculator and by whoever is working out
        // why a total is what it is — and all three behave differently once
        // they know the figures were given.
        expect($snapshot['source'])->toBe('import')
            ->and($snapshot['total_cents'])->toBe(9000)
            // The old system's own reference, kept verbatim. An operator
            // reconciling an import a month later needs it, and it exists
            // nowhere else.
            ->and($snapshot['imported_from']['reference'])->toBe('FH-88213');
    });
})->group('fast');

it('leaves the VAT split at zero rather than inventing one', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        // VAT on a booking taken elsewhere is whatever the other system
        // charged. Inventing a split here would put a number the operator never
        // agreed to into a myDATA invoice.
        expect($booking->vat_cents)->toBe(0)
            ->and($booking->vat_rate_bp)->toBe(0);
    });
})->group('fast');

it('creates the manifest rows a confirmed booking always has', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        $guests = BookingGuest::query()->where('booking_id', $booking->getKey())->orderBy('position')->get();

        // A confirmed booking with no manifest rows is one the check-in page
        // cannot show and the manifest export skips — a hole that only appears
        // on the morning of the trip.
        expect($guests)->toHaveCount(2)
            ->and($guests->first()->is_lead)->toBeTrue()
            ->and($guests->first()->full_name)->toBe('Ελένη Δημητρίου')
            // #88's QR payload. A guest whose ticket has no code cannot be
            // checked in.
            ->and(strlen((string) $guests->first()->ticket_code))->toBe(24);
    });
})->group('fast');

it('occupies the seats it describes', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 12);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        // An imported booking describes a trip somebody is actually going on.
        // If it did not take its seats, the website would keep selling them.
        expect(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_sold)->toBe(2);
    });
})->group('fast');

it('lands the whole import even where it oversells a departure', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 1);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        // An operator migrating last season's spreadsheet needs their history
        // to land. Half an import is worse than an import that shows a
        // departure over its capacity, which the departure list already
        // surfaces — and an import is a statement about what happened, not a
        // request for permission.
        expect($booking->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('mints a manage token so the guest can still reach their booking', function (): void {
    $fixture = BookingApiScenario::bookable();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        // TOK-2's forty characters. The import sends nothing, but an operator
        // will want to send the link by hand — and a booking with no token is
        // one the guest can never open.
        expect(strlen((string) $booking->manage_token))->toBe(40);
    });
})->group('fast');

it('takes the departure window rather than guessing at one', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-08-14 06:30:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(ImportBooking::class)(
            importDraft($fixture),
            totalCents: 9000,
            paidCents: 9000,
            departure: $fixture['departure'],
        );

        // CNV-3: `local_date`, `local_time` and `starts_at_utc` must always
        // agree, and the departure is the row that already has all three right.
        expect($booking->starts_at_utc->toIso8601ZuluString())
            ->toBe($fixture['departure']->starts_at_utc->toIso8601ZuluString())
            ->and($booking->local_date->toDateString())
            ->toBe($fixture['departure']->local_date->toDateString());
    });
})->group('fast');
