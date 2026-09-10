<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Γράφοντας την πρώτη προσφορά
|--------------------------------------------------------------------------
|
| `BuildQuote` was reachable from exactly one place in the application —
| **Revise**, on a quote that already existed. An operator could rewrite an
| offer and could not write one, so a guest asking for a price on a charter had
| their request sit in `quote_requested` with no way forward from any screen.
|
| Nothing caught it because every quote test builds the first quote by calling
| the Action directly, which is precisely what no screen did.
|
*/

/**
 * A booking that has asked for a price and not been given one.
 *
 * @return array{0: User, 1: Booking}
 */
function quoteRequestedBooking(): array
{
    $owner = OperatorUser::withRole(Role::Owner);

    $booking = null;

    Tenancy::forTenant($owner->tenant, function () use (&$booking): void {
        $booking = Booking::factory()->create(['status' => BookingStatus::QuoteRequested]);
    });

    return [$owner, $booking];
}

it('writes the first offer from the booking', function (): void {
    [$owner, $booking] = quoteRequestedBooking();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($booking): void {
        expect(Quote::query()->where('booking_id', $booking->getKey())->count())->toBe(0);

        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('build_quote');

        $quote = Quote::query()->where('booking_id', $booking->getKey())->firstOrFail();

        expect($quote->version)->toBe(1)
            ->and($quote->status)->toBe(QuoteStatus::Draft)
            ->and($quote->quote_token)->not->toBeEmpty()
            // BKG-25: building an offer holds nothing and moves nothing. The
            // booking learns something only when the guest does, which is
            // `SendQuote`.
            ->and($booking->refresh()->status)->toBe(BookingStatus::QuoteRequested);
    });
});

it('offers to open the offer once one exists, rather than to write a second', function (): void {
    [$owner, $booking] = quoteRequestedBooking();

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function () use ($booking): void {
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction('build_quote');

        // A second «Δημιουργία» would mint version 2 behind the operator's
        // back. Revising is the Revise action, on the quote, and it says so.
        Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('build_quote')
            ->assertActionVisible('open_quote');

        expect(Quote::query()->where('booking_id', $booking->getKey())->count())->toBe(1);
    });
});

it('does not offer to quote a booking that is not awaiting one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner);

    Tenancy::forTenant($owner->tenant, function (): void {
        // §4.4's guard: quoting a confirmed booking is not a revision, it is a
        // different conversation.
        $confirmed = Booking::factory()->create(['status' => BookingStatus::Confirmed]);

        Livewire::test(ViewBooking::class, ['record' => $confirmed->getRouteKey()])
            ->assertActionHidden('build_quote')
            ->assertActionHidden('open_quote');
    });
});

it('keeps the button away from crew', function (): void {
    $tenant = OperatorUser::withRole(Role::Owner)->tenant;
    $crew = OperatorUser::withRole(Role::Crew, $tenant);

    $booking = null;

    Tenancy::forTenant($tenant, function () use (&$booking): void {
        $booking = Booking::factory()->create(['status' => BookingStatus::QuoteRequested]);
    });

    actingAs($crew);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // TEN-8, and it turns out to hold one level earlier than the button:
        // `CrewWindow` scopes the bookings a crew member can resolve at all, so
        // the page never finds the record. The offer is unreachable by
        // construction rather than by a hidden control, which is the stronger
        // of the two and worth asserting as what actually happens.
        expect(fn () => Livewire::test(ViewBooking::class, ['record' => $booking->getRouteKey()]))
            ->toThrow(ModelNotFoundException::class);

        expect(Quote::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
});
