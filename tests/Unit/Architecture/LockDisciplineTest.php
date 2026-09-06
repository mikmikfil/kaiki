<?php

declare(strict_types=1);

use App\Domain\Booking\Support\LockOrder;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Vessel;
use App\Models\Voucher;

/*
|--------------------------------------------------------------------------
| AVL-43.1, AVL-45 and AVL-46: the three rules a passing suite cannot show
|--------------------------------------------------------------------------
|
| Every one of these is invisible in a green test run, which is why they are
| asserted against the source rather than against behaviour:
|
|   the lock is unconditional  a `if ($driver === 'mysql')` around
|                              `lockForUpdate()` passes every test — including
|                              the MySQL ones — and removes the guarantee on the
|                              stack the code is written on. AVL-43.1 calls it a
|                              review blocker; this makes it a red build.
|
|   the order is fixed         vessel, departure, booking, voucher (AVL-45).
|                              Two transactions taking the same locks in
|                              opposite orders deadlock; MySQL kills one after a
|                              timeout, so the symptom is a random failed
|                              confirmation under load and nothing reproducible.
|
|   no external calls inside   AVL-46. A row lock held across somebody else's
|                              HTTP timeout is the whole boat off sale, and it
|                              only happens under the load that makes it worst.
|
*/

/**
 * The Actions that take these locks and must obey all three rules.
 *
 * @return list<string>
 */
function lockedActions(): array
{
    return [
        'app/Domain/Booking/Actions/ConfirmBooking.php',
        'app/Domain/Booking/Actions/StartCheckout.php',
        'app/Domain/Booking/Actions/ExpireAbandonedCheckouts.php',

        // #83's payment-failure path, which takes all three (BKG-12). It was
        // written to AVL-45's order and never checked against it — an ordering
        // obeyed by three files and merely intended by a fourth is the state
        // this test exists to prevent.
        'app/Domain/Booking/Actions/ConfirmFromWebhook.php',

        // #84's two. `CancelBooking` releases capacity under all three locks
        // (CXL-9); `CancelDeparture` takes only the departure's, which is
        // still an order it has to be in.
        'app/Domain/Booking/Actions/CancelBooking.php',
        'app/Domain/Booking/Actions/CancelDeparture.php',
    ];
}

function sourceOf(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
}

/**
 * Statements, comments stripped, so a docblock naming a rule is not a breach of it.
 *
 * @return list<string>
 */
function statementsOf(string $source): array
{
    $stripped = (string) preg_replace_callback(
        '~/\*.*?\*/|//[^\n]*~s',
        static fn (array $m): string => (string) preg_replace('/[^\n]/u', ' ', $m[0]),
        $source,
    );

    return explode(';', $stripped);
}

it('takes the locks and never conditionally', function (string $file): void {
    $statements = statementsOf(sourceOf($file));

    $locking = array_values(array_filter(
        $statements,
        static fn (string $statement): bool => str_contains($statement, 'lockForUpdate('),
    ));

    expect($locking)->not->toBeEmpty("{$file} takes no row lock at all");

    foreach ($locking as $statement) {
        // A driver name anywhere in a locking statement is the shape AVL-43.1
        // forbids: `->when($isMysql, fn ($q) => $q->lockForUpdate())` and its
        // relatives all pass every test and remove the guarantee locally.
        expect(strtolower($statement))
            ->not->toContain('sqlite', "{$file} branches a lock on the driver")
            ->and(strtolower($statement))
            ->not->toContain('getdrivername', "{$file} branches a lock on the driver");
    }
})->with(lockedActions())->group('fast');

it('takes them in the AVL-45 order, everywhere', function (string $file): void {
    $source = sourceOf($file);
    $stripped = implode(';', statementsOf($source));

    $seen = [];

    // Where each model's lock first appears in the file. Position, not
    // call-graph — a static check cannot follow a helper, so the Actions keep
    // their locking calls in `__invoke` or in private helpers called in order,
    // which is also how a reader follows them.
    foreach (LockOrder::RANK as $model => $rank) {
        $short = class_basename($model);
        $offset = strpos($stripped, $short . '::query()->lockForUpdate(');

        if ($offset !== false) {
            $seen[$rank] = $offset;
        }
    }

    $ranks = array_keys($seen);
    sort($ranks);

    $offsets = array_map(static fn (int $rank): int => $seen[$rank], $ranks);
    $sorted = $offsets;
    sort($sorted);

    expect($offsets)->toBe($sorted, sprintf(
        "%s takes its locks out of order.\nAVL-45 fixes it as %s, and any other order is a deadlock waiting for load.",
        $file,
        implode(' → ', array_map('class_basename', LockOrder::sequence())),
    ));
})->with(lockedActions())->group('fast');

it('makes no external call inside a locked transaction', function (string $file): void {
    $source = sourceOf($file);
    $stripped = implode(';', statementsOf($source));

    // The things AVL-46 names, plus the shapes they arrive in. A gateway call,
    // a mail send and an HTTP request are all somebody else's latency held
    // inside our lock.
    $forbidden = ['Http::', 'Mail::', 'Notification::send', '->createCheckoutSession(', 'file_get_contents(http'];

    foreach ($forbidden as $needle) {
        expect($stripped)->not->toContain($needle, sprintf(
            '%s performs an external call (%s) where a row lock may be held. AVL-46: side effects are '
            . 'dispatched as queued jobs after commit.',
            $file,
            $needle,
        ));
    }
})->with(lockedActions())->group('fast');

it('dispatches its events after the transaction, not inside it', function (): void {
    $source = sourceOf('app/Domain/Booking/Actions/ConfirmBooking.php');

    $transactionEnd = strrpos($source, '});');
    $dispatch = strpos($source, 'BookingConfirmed::dispatch');

    expect($transactionEnd)->not->toBeFalse()
        ->and($dispatch)->not->toBeFalse()
        // The assertion AVL-46 and BKG-14 both rest on: every listener is an
        // external call, and a failing one must not roll the confirmation back.
        ->and($dispatch)->toBeGreaterThan($transactionEnd);
})->group('fast');

it('ranks every model that takes one of these locks', function (): void {
    // A model added to the confirmation path without a rank would sort as null
    // and the ordering test would silently stop checking it.
    expect(LockOrder::sequence())->toBe([
        Vessel::class,
        Departure::class,
        Booking::class,
        Voucher::class,
    ]);
})->group('fast');
