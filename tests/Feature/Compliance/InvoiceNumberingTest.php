<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Models\Invoice;
use App\Models\SeriesCounter;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Invoice numbering — spec MYD-4, ADR-0022 Option A
|--------------------------------------------------------------------------
|
| Two documents sharing a number in a Greek invoicing series is not a bug an
| operator reports. It is a question they answer to their accountant, and then
| possibly to somebody else. Everything here is shaped by that.
|
| **[LOCK]** The row lock is a no-op on SQLite (`docs/data-model.md` §0), so on
| a developer's machine the unique index and the allocator's retry are the only
| guarantees running. That is deliberate — the layer that has to hold is the one
| that holds everywhere — but it means the genuinely concurrent test belongs in
| the MySQL-only CI job, and `it refuses a duplicate number at the database`
| below is the part that can be proved here.
|
*/

function numberingTenant(): Tenant
{
    return Tenant::factory()->create();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
});

it('starts a series at one without anything being seeded', function (): void {
    // A missing counter row means the series has issued nothing this year. An
    // operator who starts trading in July must not begin at a number their
    // books cannot explain.
    $tenant = numberingTenant();

    $number = Tenancy::forTenant($tenant, function (): int {
        $invoice = Invoice::factory()->create(['series' => 'A']);

        return app(AllocateInvoiceNumber::class)($invoice);
    });

    expect($number)->toBe(1);
})->group('fast');

it('hands out consecutive numbers and moves the counter with them', function (): void {
    $tenant = numberingTenant();

    [$numbers, $counter] = Tenancy::forTenant($tenant, function (): array {
        $allocate = app(AllocateInvoiceNumber::class);

        $numbers = collect(range(1, 5))
            ->map(fn (): int => $allocate(Invoice::factory()->create(['series' => 'A'])))
            ->all();

        return [$numbers, SeriesCounter::query()->where('series', 'A')->first()];
    });

    expect($numbers)->toBe([1, 2, 3, 4, 5])
        ->and($counter?->last_number)->toBe(5)
        ->and($counter?->last_allocated_at)->not->toBeNull();
})->group('fast');

it('keeps two series apart', function (): void {
    // «Α» and «Β» are independent sequences. An operator running a second series
    // for a second activity must not find it starting at 40.
    $tenant = numberingTenant();

    [$a, $b] = Tenancy::forTenant($tenant, function (): array {
        $allocate = app(AllocateInvoiceNumber::class);

        $allocate(Invoice::factory()->create(['series' => 'A']));
        $allocate(Invoice::factory()->create(['series' => 'A']));

        return [
            $allocate(Invoice::factory()->create(['series' => 'A'])),
            $allocate(Invoice::factory()->create(['series' => 'B'])),
        ];
    });

    expect($a)->toBe(3)->and($b)->toBe(1);
})->group('fast');

it('resets at the turn of the year', function (): void {
    // MYD-4.1. A Greek series restarts each January, so the same number exists
    // in two years and the year is part of the uniqueness key.
    $tenant = numberingTenant();

    // Midday UTC, deliberately. 22:00 UTC on 31 December is already 1 January
    // in Athens, so the obvious "late on the last day" clock would have put
    // both allocations in the same year and quietly proved nothing — which is
    // the timezone bug the next test exists for.
    $december = Tenancy::forTenant($tenant, function (): int {
        Carbon::setTestNow('2026-12-31 12:00:00');

        return app(AllocateInvoiceNumber::class)(Invoice::factory()->create(['series' => 'A']));
    });

    $january = Tenancy::forTenant($tenant, function (): int {
        Carbon::setTestNow('2027-01-01 09:00:00');

        return app(AllocateInvoiceNumber::class)(Invoice::factory()->create(['series' => 'A']));
    });

    expect($december)->toBe(1)->and($january)->toBe(1);
})->group('fast');

it('reads the year in the operator’s timezone, not the server’s', function (): void {
    // 23:30 on 31 December in Athens is already 1 January in UTC. An operator
    // whose last document of the year landed in next year's series would be
    // explaining that to an accountant.
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $invoice = Tenancy::forTenant($tenant, function (): Invoice {
        // 21:30 UTC on 31 December = 23:30 Athens, same day.
        Carbon::setTestNow('2026-12-31 21:30:00');

        $invoice = Invoice::factory()->create(['series' => 'A']);
        app(AllocateInvoiceNumber::class)($invoice);

        return $invoice->refresh();
    });

    expect($invoice->year)->toBe(2026);
})->group('fast');

it('does not give one document two numbers', function (): void {
    // A retry that re-allocated would burn a second number for one document,
    // which is exactly the failure the whole class exists to prevent.
    $tenant = numberingTenant();

    [$first, $second] = Tenancy::forTenant($tenant, function (): array {
        $allocate = app(AllocateInvoiceNumber::class);
        $invoice = Invoice::factory()->create(['series' => 'A']);

        return [$allocate($invoice), $allocate($invoice)];
    });

    expect($first)->toBe(1)->and($second)->toBe(1);
})->group('fast');

it('keeps each operator’s series to themselves', function (): void {
    // `series_counters` is tenant-owned, so «Α/2026/1» exists once per operator.
    $one = numberingTenant();
    $two = numberingTenant();

    $first = Tenancy::forTenant($one, fn (): int => app(AllocateInvoiceNumber::class)(
        Invoice::factory()->create(['series' => 'A']),
    ));

    $second = Tenancy::forTenant($two, fn (): int => app(AllocateInvoiceNumber::class)(
        Invoice::factory()->create(['series' => 'A']),
    ));

    expect($first)->toBe(1)->and($second)->toBe(1);
})->group('fast');

it('refuses a duplicate number at the database, whatever the application thinks', function (): void {
    /*
     * The guarantee that actually holds.
     *
     * The lock makes a collision rare and this index makes it impossible — and
     * on SQLite, where the lock does nothing, this is the *only* thing standing
     * between two documents and one number. Asserted by writing the duplicate
     * by hand rather than by racing two processes, because a race that passes
     * once has proved nothing.
     */
    $tenant = numberingTenant();

    Tenancy::forTenant($tenant, function (): void {
        app(AllocateInvoiceNumber::class)(Invoice::factory()->create(['series' => 'A']));

        expect(fn () => Invoice::factory()->create([
            'series' => 'A',
            'number' => 1,
            'year' => (int) now()->format('Y'),
        ]))->toThrow(QueryException::class);
    });
})->group('fast');

it('leaves a pending invoice without a number until somebody allocates one', function (): void {
    // MYD-4.2. A document written and never submitted must not burn a number,
    // and this is the state that makes that true.
    $tenant = numberingTenant();

    $invoice = Tenancy::forTenant($tenant, fn (): Invoice => Invoice::factory()->create());

    expect($invoice->hasNumber())->toBeFalse()
        ->and($invoice->number)->toBeNull()
        // And it still reads as something in the panel rather than as a blank.
        ->and($invoice->reference())->toBe('ΑΛΠ');
})->group('fast');

it('reads back as the reference an operator says down the telephone', function (): void {
    $tenant = numberingTenant();

    $invoice = Tenancy::forTenant($tenant, function (): Invoice {
        $invoice = Invoice::factory()->create(['series' => 'A']);
        app(AllocateInvoiceNumber::class)($invoice);

        return $invoice->refresh();
    });

    expect($invoice->reference())->toBe('ΑΛΠ A/2026/1');
})->group('fast');
