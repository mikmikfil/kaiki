<?php

declare(strict_types=1);

use App\Data\Pricing\CancellationPolicyData;
use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\SendQuote;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Pricing\Support\RefundCalculator;
use App\Enums\QuoteLineKind;
use App\Models\QuoteLineItem;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| BKG-27, §3.4: an operator's free text, in the same shape as every other price
|--------------------------------------------------------------------------
|
| BKG-27 allows free-text lines and then constrains everything else: *"the total
| is still stored as integer cents and still produces a `price_snapshot`."*
|
| The reason is refunds. `RefundCalculator` takes a frozen policy and a
| `paid_cents`, and CXL-1 makes that the only route to a refund — so a quoted
| booking carrying its totals in some other shape would be a booking the
| cancellation path could not price. The last test in this file is the one that
| matters: an accepted quote's snapshot is fed to the same calculator every
| other booking uses, unmodified.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * One snapshot line by its §3.4 `kind`.
 *
 * A plain function rather than a `collect()->firstWhere()` because PHPStan
 * cannot resolve `collect()`'s template types from an array read out of a JSON
 * column, and an annotation that told it to is an annotation asserting
 * something the column cannot guarantee.
 *
 * @param  array<int, array<string, mixed>>  $lines
 * @return array<string, mixed>|null
 */
function snapshotLineOfKind(array $lines, string $kind): ?array
{
    foreach ($lines as $line) {
        if (($line['kind'] ?? null) === $kind) {
            return $line;
        }
    }

    return null;
}

it('folds free-text lines into the §3.4 shape', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        QuoteLineItem::factory()->fee(12000)->create(['quote_id' => $quote->getKey()]);
        QuoteLineItem::factory()->discount(7000)->create(['quote_id' => $quote->getKey()]);

        app(BuildQuote::class)->recomputeTotals($quote);
        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        $snapshot = $accepted->price_snapshot;

        expect($snapshot['version'] ?? null)->toBe(1)
            // §3.4's own enumeration: the field exists so a reader a year later
            // knows the number came from a person rather than a rate plan.
            ->and($snapshot['source'] ?? null)->toBe('quote')
            // Honestly null. There was no rate plan and no season, and the
            // provenance for "why this price" is the quote row itself.
            // `array_key_exists`, not `??`: the assertion is that the key is
            // present and null, and `??` cannot tell those two apart.
            ->and(array_key_exists('rate_plan_id', $snapshot))->toBeTrue()
            ->and($snapshot['rate_plan_id'])->toBeNull()
            ->and($snapshot['season'])->toBeNull()
            ->and($snapshot['lines'])->toHaveCount(3)
            ->and($snapshot['rounding'] ?? null)->toBe('HALF_UP');
    });
})->group('fast');

it('keeps §3.4 adding up, with the discount positive and the sign in the kind', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        QuoteLineItem::factory()->fee(12000)->create(['quote_id' => $quote->getKey()]);
        QuoteLineItem::factory()->discount(7000)->create(['quote_id' => $quote->getKey()]);

        app(BuildQuote::class)->recomputeTotals($quote);
        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        $snapshot = $accepted->price_snapshot;

        // §3.4's invariant, which `PriceSnapshotData` enforces by construction:
        // subtotal + extras − discount = total.
        expect($snapshot['subtotal_cents'])->toBe(95000)
            ->and($snapshot['extras_cents'])->toBe(12000)
            ->and($snapshot['discount_cents'])->toBe(7000)
            ->and($snapshot['total_cents'])->toBe(100000)
            // §1.4: positive even for a discount. A negative amount would make
            // every SUM in the system a question about which rows were included.
            ->and(snapshotLineOfKind($snapshot['lines'], 'discount')['total_cents'] ?? null)->toBe(7000);

        // And the columns on the booking equal the snapshot's fields, which
        // §3.4 requires of every booking however its price was arrived at.
        expect($accepted->total_cents)->toBe($snapshot['total_cents'])
            ->and($accepted->discount_cents)->toBe($snapshot['discount_cents']);
    });
})->group('fast');

it('carries the operator own words into the line labels, in both locales', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        QuoteLineItem::factory()->create([
            'quote_id' => $quote->getKey(),
            'label' => ['el' => 'Σκάφος με πλήρωμα, 8 ώρες', 'en' => 'Boat with crew, 8 hours'],
            'kind' => QuoteLineKind::Fee,
            'unit_price_cents' => 30000,
            'total_cents' => 30000,
            'sort_order' => 5,
        ]);

        app(BuildQuote::class)->recomputeTotals($quote);
        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        // The snapshot has to *"explain the total to a guest a year later
        // without touching any other table"*, so a line carries the label at
        // the time rather than a reference to fetch one — and an operator
        // renaming the line next spring must not rewrite what a guest was shown.
        $written = array_values(array_filter(
            $accepted->price_snapshot['lines'],
            static fn (array $line): bool => ($line['label']['el'] ?? null) === 'Σκάφος με πλήρωμα, 8 ώρες'
                && ($line['label']['en'] ?? null) === 'Boat with crew, 8 hours'
                // And the provenance: `quote_line:{id}`, which is the honest
                // answer to "why this price" when there was no rate plan.
                && str_starts_with((string) ($line['ref'] ?? ''), 'quote_line:'),
        ));

        expect($written)->toHaveCount(1);
    });
})->group('fast');

it('treats the price as VAT-inclusive, so net plus tax is the total', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 100000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        // A rate written by the test rather than by the source: statutory
        // percentages live in `vat_rates` and never in PHP (ADR-0002).
        $quote->forceFill(['vat_rate_bp' => 2400])->save();

        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        $vat = $accepted->price_snapshot['vat'];

        // §3.4: Greek passenger transport prices are VAT-inclusive, so the net
        // is the total *less* the tax. Getting that backwards inflates every
        // quoted invoice by the rate.
        expect($vat['included'])->toBeTrue()
            ->and($vat['net_cents'] + $vat['vat_cents'])->toBe($accepted->total_cents)
            ->and($vat['net_cents'])->toBeLessThan($accepted->total_cents);
    });
})->group('fast');

it('states no vat_category rather than inventing one', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($quote): void {
        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        // CAT-11a and ADR-0002: the AADE category lives on the `vat_rates` row,
        // and the myDATA client *"contains no percent→category mapping"*.
        // A quote carries a rate in basis points and no category — inventing
        // one here would put the mapping back, in the one place nobody looks.
        expect($accepted->price_snapshot['vat'])->not->toHaveKey('vat_category');
    });
})->group('fast');

it('records a fixed deposit rather than a percentage of a charter', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        $quote->forceFill(['deposit_cents' => 20000])->save();

        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        // An operator quoting a €950 charter types the deposit they want in
        // euros. Recording it as a percentage would turn €200 into 21.05% and
        // then round it back to something else.
        expect($accepted->price_snapshot['deposit'])->toBe(['type' => 'fixed', 'amount_cents' => 20000])
            ->and($accepted->deposit_cents)->toBe(20000);
    });
})->group('fast');

it('produces a booking the ordinary refund calculator can price', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted(charterCents: 95000);

    Tenancy::forTenant($tenant, function () use ($quote): void {
        $accepted = app(AcceptQuote::class)(app(SendQuote::class)($quote->refresh()));

        // Paid in full, as though the gateway had settled.
        $accepted->forceFill(['paid_cents' => 95000, 'balance_cents' => 0])->save();

        // **This is the test BKG-27 is really about.** The snapshot goes into
        // the same calculator every other booking uses, with no quote-shaped
        // branch anywhere — CXL-1 makes the frozen policy the only route to a
        // refund, so a quoted booking that could not be read here would be a
        // booking nobody could cancel.
        $policy = CancellationPolicyData::fromSnapshot($accepted->refresh()->policy_snapshot ?? []);

        expect($policy->tiers)->not->toBeEmpty();

        $percent = RefundCalculator::percentFor(
            $policy,
            $accepted->starts_at_utc,
            $accepted->starts_at_utc->copy()->subDays(20),
        );

        expect($percent)->toBe(100)
            ->and(RefundEntitlement::forCancellation(
                $accepted,
                $accepted->starts_at_utc->copy()->subDays(20),
            )->totalCents)->toBe(95000);
    });
})->group('fast');
