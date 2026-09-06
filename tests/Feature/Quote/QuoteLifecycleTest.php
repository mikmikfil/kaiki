<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\DeclineQuote;
use App\Domain\Booking\Actions\ExpireQuotes;
use App\Domain\Booking\Actions\SendQuote;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\QuoteStatus;
use App\Events\QuoteAccepted;
use App\Events\QuoteDeclined;
use App\Events\QuoteSent;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| BKG-24…27, §4.4: every transition, and the one that is not what it looks like
|--------------------------------------------------------------------------
|
| §4.4 draws `sent → draft: revise` and then says, in its own transition table,
| that the row *"never returns to `draft` in place — the diagram edge is the
| operator-visible action, the implementation is create-and-supersede, so the
| audit trail is intact."*
|
| That is the test in this file that matters. Editing the sent row would be
| simpler, would pass a naive lifecycle test, and would destroy the record of
| what the guest was actually offered — while silently changing the price under
| the link they are already holding.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('starts at version one, in draft, holding nothing', function (): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $quote = app(BuildQuote::class)($booking);

        expect($quote->version)->toBe(1)
            ->and($quote->status)->toBe(QuoteStatus::Draft)
            ->and($quote->quote_token)->toHaveLength(40)
            // BKG-24: no price is shown at any point before the operator
            // writes one, so a fresh quote genuinely has none.
            ->and($quote->total_cents)->toBe(0)
            // Building a quote changes nothing about the booking. The guest has
            // learned nothing yet.
            ->and($booking->refresh()->status)->toBe(BookingStatus::QuoteRequested);
    });
})->group('fast');

it('refuses to build a quote on a booking that is not asking for one', function (): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();

        // §4.4's guard. Quoting a confirmed booking is not a revision; it is a
        // different conversation, and letting it through would overwrite a
        // price a guest has already paid.
        expect(fn () => app(BuildQuote::class)($booking->refresh()))
            ->toThrow(RuntimeException::class);
    });
})->group('fast');

it('sends a quote and moves the booking to quote_sent', function (): void {
    Event::fake([QuoteSent::class]);

    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        $sent = app(SendQuote::class)($quote);

        expect($sent->status)->toBe(QuoteStatus::Sent)
            ->and($sent->sent_at)->not->toBeNull()
            ->and($booking->refresh()->status)->toBe(BookingStatus::QuoteSent);

        Event::assertDispatched(
            QuoteSent::class,
            fn (QuoteSent $event): bool => $event->quoteId === $quote->getKey(),
        );
    });
})->group('fast');

it('refuses to send an empty quote, a free one, or one already expired', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        // Each of these is an operator's own slip and each has a different
        // consequence: an email with nothing in it, a free charter, and an
        // offer the guest cannot accept and cannot be told why.
        $empty = app(BuildQuote::class)($booking);
        expect(fn () => app(SendQuote::class)($empty))->toThrow(RuntimeException::class);

        $quote->forceFill(['total_cents' => 0])->save();
        expect(fn () => app(SendQuote::class)($quote->refresh()))->toThrow(RuntimeException::class);

        $quote->forceFill(['total_cents' => 95000, 'valid_until' => now()->subDay()])->save();
        expect(fn () => app(SendQuote::class)($quote->refresh()))->toThrow(RuntimeException::class);
    });
})->group('fast');

it('creates a new version and supersedes, rather than reopening the sent one', function (): void {
    [$tenant, $booking, $first] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $first): void {
        app(SendQuote::class)($first);

        // The operator revises. §4.4: a **new row**, not an edit.
        $second = app(BuildQuote::class)($booking->refresh());

        QuoteLineItem::factory()->create([
            'quote_id' => $second->getKey(),
            'unit_price_cents' => 89000,
            'total_cents' => 89000,
        ]);

        app(BuildQuote::class)->recomputeTotals($second);
        app(SendQuote::class)($second->refresh());

        expect($second->refresh()->version)->toBe(2)
            ->and($second->status)->toBe(QuoteStatus::Sent)
            // The old row is `expired`, **never deleted** — the guest may still
            // have the old link open, and `/q/{token}` has to be able to say
            // "this quote was replaced".
            ->and($first->refresh()->status)->toBe(QuoteStatus::Expired)
            ->and($first->expired_at)->not->toBeNull()
            // A new token, so the old link cannot silently show the new price.
            ->and($second->quote_token)->not->toBe($first->quote_token);
    });
})->group('fast');

it('accepts a quote, freezing both snapshots at that instant', function (): void {
    Event::fake([QuoteAccepted::class]);

    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);

        $accepted = app(AcceptQuote::class)($quote->refresh());

        expect($accepted->status)->toBe(BookingStatus::PendingPayment)
            ->and($accepted->total_cents)->toBe(95000)
            ->and($accepted->balance_cents)->toBe(95000)
            // CXL-2: *"at quote acceptance for quote bookings."* Both, at the
            // same instant — a booking with a price and no terms is a booking
            // nobody can refund.
            ->and($accepted->price_snapshot['source'] ?? null)->toBe('quote')
            ->and($accepted->policy_snapshot)->not->toBeNull()
            ->and($quote->refresh()->status)->toBe(QuoteStatus::Accepted);

        Event::assertDispatched(QuoteAccepted::class);
    });
})->group('fast');

it('declines a quote and cancels the booking with its own reason', function (): void {
    Event::fake([QuoteDeclined::class]);

    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        app(DeclineQuote::class)($quote->refresh(), 'Too expensive for us this year');

        expect($quote->refresh()->status)->toBe(QuoteStatus::Declined)
            ->and($quote->decline_reason)->toBe('Too expensive for us this year')
            // BKG-26's own word, and §2.5 did not have it until #85 added it.
            // Folding it into `guest_request` would destroy the one number a
            // quote-mode operator most wants: how many offers get turned down.
            ->and($booking->refresh()->cancel_reason)->toBe(CancelReason::QuoteDeclined)
            ->and($booking->status)->toBe(BookingStatus::Cancelled);

        Event::assertDispatched(QuoteDeclined::class);
    });
})->group('fast');

it('expires a lapsed quote and the booking with it', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);
    });

    // Eight days on: past the seven-day default.
    Carbon::setTestNow(now()->addDays(8));

    expect(app(ExpireQuotes::class)())->toBe(1);

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        expect($quote->refresh()->status)->toBe(QuoteStatus::Expired)
            ->and($quote->expired_at)->not->toBeNull()
            ->and($booking->refresh()->status)->toBe(BookingStatus::Expired);
    });
})->group('fast');

it('leaves the booking alone when a newer quote is still live', function (): void {
    [$tenant, $booking, $first] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $first): void {
        app(SendQuote::class)($first);

        $second = app(BuildQuote::class)($booking->refresh());

        QuoteLineItem::factory()->create([
            'quote_id' => $second->getKey(),
            'unit_price_cents' => 89000,
            'total_cents' => 89000,
        ]);

        app(BuildQuote::class)->recomputeTotals($second);
        // Valid for a month, so it outlives the first by a long way.
        $second->forceFill(['valid_until' => now()->addDays(30)])->save();
        app(SendQuote::class)($second->refresh());
    });

    Carbon::setTestNow(now()->addDays(8));

    app(ExpireQuotes::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // §4.4: *"booking → `expired` unless a newer quote exists."* Expiring
        // it here would cancel the conversation the operator is in the middle
        // of. The first version was already superseded when the second was sent.
        expect($booking->refresh()->status)->toBe(BookingStatus::QuoteSent);
    });
})->group('fast');

it('refuses a lapsed quote at the moment of acceptance, sweeper or no sweeper', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);

        // The read path is the guarantee and the sweeper is the tidier — the
        // same division AVL-38 draws for seat holds. A backlogged queue must
        // never make a stale price acceptable.
        Carbon::setTestNow(now()->addDays(8));

        expect($quote->refresh()->status)->toBe(QuoteStatus::Sent)
            ->and($quote->canBeAccepted())->toBeFalse()
            ->and(fn () => app(AcceptQuote::class)($quote))->toThrow(RuntimeException::class);
    });
})->group('fast');

it('honours a decline once, however many times the guest clicks', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        app(SendQuote::class)($quote);

        app(DeclineQuote::class)($quote->refresh(), 'No thanks');
        app(DeclineQuote::class)($quote->refresh(), 'Changed my mind about declining');

        // The first answer stands. Idempotent by status, the same posture every
        // other terminal transition in this codebase takes.
        expect($quote->refresh()->decline_reason)->toBe('No thanks');
    });
})->group('fast');

it('keeps one row per version, enforced by the index rather than by discipline', function (): void {
    [$tenant, $booking] = QuoteScenario::requested();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $first = app(BuildQuote::class)($booking);

        expect(fn () => Quote::factory()->create([
            'booking_id' => $booking->getKey(),
            'version' => $first->version,
        ]))->toThrow(QueryException::class);
    });
})->group('fast');
