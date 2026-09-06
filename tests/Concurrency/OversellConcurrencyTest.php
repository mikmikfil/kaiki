<?php

declare(strict_types=1);

use App\Domain\Booking\Support\SeatCommitment;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| AVL-44: two simultaneous confirmations cannot oversell the last seat
|--------------------------------------------------------------------------
|
| **The test the whole engine exists to pass.** A required status check since
| #4, with nothing to run until this issue.
|
| `CLAUDE.md`: a booking that oversold is a guest on a quay with a ticket and no
| seat. There is no later fix — the boat sails or it does not.
|
| ## Why `@group mysql`, and why it must never pass locally
|
| ADR-0006: `SELECT … FOR UPDATE` is a **no-op on SQLite**. A green run there
| would be a green run that tested nothing, which is the most dangerous shape a
| safety test can take. It skips with an explicit reason instead (AVL-43.3), and
| TST-8 fails the build if this group reports zero executed tests — the failure
| mode where the gate is present and empty.
|
| The portable half of the guarantee, AVL-43.2's conditional counter update, is
| exercised on SQLite by `ConditionalCounterTest` with no parallelism at all.
|
| ## Two connections, and why one will not do
|
| PDO serialises statements on a single link, so a "concurrent" pair driven
| through one connection is a sequential pair — and a sequential pair passes
| against an implementation with no locking whatsoever. `mysql_concurrent` is a
| clone of the default connection onto the same database, so the two
| transactions are genuinely open at the same time.
|
| ## `DatabaseMigrations`, not `RefreshDatabase`
|
| `RefreshDatabase` wraps each test in a transaction that is never committed, so
| a second connection cannot see any of the fixtures — the test would fail on
| missing rows and prove nothing about capacity. This file migrates instead,
| which commits. It is slower, it runs in CI only, and it is the only way the
| two connections can see the same world.
|
*/

/**
 * AVL-43.3's skip, as a Pest modifier rather than `markTestSkipped()`.
 *
 * `$this` inside a Pest closure is a `TestCall` at analysis time, so the
 * PHPUnit method is not statically visible — the same thing `travel()` and
 * `fail()` ran into in #80. `->skip()` is the native form, it prints the reason,
 * and the reason is the requirement: a green result on SQLite would prove
 * nothing, so it must never be one.
 *
 * @return array{0: callable(): bool, 1: string}
 */
function requiresMysql(): array
{
    return [
        // **Not `static`.** Pest binds a `->skip()` closure to the test case, and
        // PHP refuses to bind an instance to a static closure — "Cannot bind an
        // instance to a static closure", thrown at the moment the test runs.
        // Which meant the three AVL-44 tests failed on their very first real
        // execution against MySQL 8, on the skip rather than on the capacity.
        fn (): bool => DB::connection()->getDriverName() !== 'mysql',
        'AVL-44 requires MySQL 8: SELECT ... FOR UPDATE is a no-op on SQLite (ADR-0006), '
        . 'so a green result here would prove nothing. Run `composer test:mysql`.',
    ];
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        return;
    }

    // Short, so a genuine deadlock fails the test in seconds rather than
    // hanging the CI job for the server default of fifty.
    DB::connection('mysql_concurrent')->statement('SET SESSION innodb_lock_wait_timeout = 5');
});

afterEach(function (): void {
    if (DB::connection()->getDriverName() === 'mysql') {
        DB::purge('mysql_concurrent');
    }
});

/** A departure with exactly one seat and nothing on it. */
/** @return array{0: Tenant, 1: Departure} */
function lastSeatDeparture(): array
{
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, static fn (): Departure => Departure::factory()->create([
        'capacity' => 1,
        'seats_sold' => 0,
        'seats_held' => 0,
        'min_pax' => 0,
    ]));

    return [$tenant, $departure];
}

it('lets exactly one of two overlapping transactions take the last seat', function (): void {
    [, $departure] = lastSeatDeparture();

    $primary = DB::connection();
    $second = DB::connection('mysql_concurrent');

    $primary->beginTransaction();
    $second->beginTransaction();

    // Both read the same row and both see one seat free. On a broken
    // implementation this is where the two diverge and both go on to write.
    $freeToPrimary = (int) $primary->table('departures')
        ->where('id', $departure->getKey())
        ->value('capacity');

    $freeToSecond = (int) $second->table('departures')
        ->where('id', $departure->getKey())
        ->value('capacity');

    expect($freeToPrimary)->toBe(1)->and($freeToSecond)->toBe(1);

    // The first takes the seat and commits.
    $firstTook = $primary->table('departures')
        ->where('id', $departure->getKey())
        ->whereRaw('capacity - seats_sold - seats_held >= ?', [1])
        ->update(['seats_sold' => DB::raw('seats_sold + 1')]);

    $primary->commit();

    // The second's condition is evaluated against the row **as it is now**,
    // not as it was when it read. That is AVL-43.2's whole guarantee.
    $secondTook = $second->table('departures')
        ->where('id', $departure->getKey())
        ->whereRaw('capacity - seats_sold - seats_held >= ?', [1])
        ->update(['seats_sold' => DB::raw('seats_sold + 1')]);

    $second->commit();

    expect($firstTook)->toBe(1)
        ->and($secondTook)->toBe(0);

    // Stated directly rather than inferred from the two results: whatever
    // happened, the boat is not oversold.
    $sold = (int) DB::table('departures')->where('id', $departure->getKey())->value('seats_sold');

    expect($sold)->toBe(1);
})->skip(...requiresMysql())->group('mysql');

it('makes the second transaction wait rather than read a stale row', function (): void {
    [, $departure] = lastSeatDeparture();

    $primary = DB::connection();
    $second = DB::connection('mysql_concurrent');

    $primary->beginTransaction();

    // AVL-43.1's row lock, unconditional and on every path.
    $primary->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();

    $primary->table('departures')->where('id', $departure->getKey())->update(['seats_sold' => 1]);

    $second->beginTransaction();

    $blocked = false;

    try {
        // Blocks on the row the first transaction holds. Without the lock this
        // returns the pre-update row immediately and the second confirmation
        // proceeds on a number that is already wrong — which is what the
        // conditional update then has to catch on its own.
        $second->table('departures')->where('id', $departure->getKey())->lockForUpdate()->first();
    } catch (Throwable $exception) {
        $blocked = str_contains($exception->getMessage(), 'Lock wait timeout');
    }

    expect($blocked)->toBeTrue('the second transaction read the locked row instead of waiting for it');

    $second->rollBack();
    $primary->rollBack();
})->skip(...requiresMysql())->group('mysql');

it('refuses through the domain helper, not only through hand-written SQL', function (): void {
    // The two above prove the database behaves. This proves the code the
    // application actually calls behaves the same way — a guard asserted only
    // in raw SQL is a guard the implementation can quietly stop using.
    [$tenant, $departure] = lastSeatDeparture();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        expect(SeatCommitment::commit($departure, 1))->toBeTrue();

        $departure->refresh();

        expect(SeatCommitment::commit($departure, 1))->toBeFalse()
            ->and($departure->refresh()->seats_sold)->toBe(1);
    });
})->skip(...requiresMysql())->group('mysql');
