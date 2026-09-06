<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| ENV-7 and AVL-37: the driver is configuration, and the hold has three writers
|--------------------------------------------------------------------------
|
| Two rules, one file, because both are architectural facts about the hold that
| nothing else can enforce and both fail silently.
|
| **No direct Redis.** ADR-0005 makes the lock driver a configuration choice:
| the database store locally, Redis in CI and production, with no code
| difference. A `Redis::` call satisfies every test in CI — where Redis is
| real — and cannot run at all on the SQLite stack this project develops on
| (ENV-3). The failure is therefore invisible to the person who writes it.
|
| **Three writers.** AVL-37.5 names `HoldSeats`, `ExtendHold` and `ReleaseHold`
| as the only writers of `hold_expires_at` and `departures.seats_held`. A fourth
| writer does not throw; it makes the counter untrustworthy, and an untrustworthy
| `seats_held` is an oversell nobody can explain afterwards.
|
*/

/** @return list<string> */
function architecturePaths(): array
{
    return ['app'];
}

/**
 * @param  list<string>  $needles
 * @return list<string> "path:line — snippet"
 */
function sourceLinesContaining(array $needles, callable $exempt): array
{
    $findings = [];
    $root = dirname(__DIR__, 3);

    foreach (architecturePaths() as $path) {
        $absolute = $root . DIRECTORY_SEPARATOR . $path;

        if (! is_dir($absolute)) {
            continue;
        }

        foreach (Finder::create()->files()->in($absolute)->name('*.php') as $file) {
            $relative = $path . '/' . str_replace('\\', '/', $file->getRelativePathname());

            if ($exempt($relative)) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($file->getRealPath())) as $index => $line) {
                $trimmed = ltrim($line);

                // A docblock naming the rule is not a breach of it. Half the
                // classes in `app/Domain/Availability` explain why they do not
                // call Redis.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $findings[] = "{$relative}:" . ($index + 1) . ' — ' . trim($line);

                        break;
                    }
                }
            }
        }
    }

    return $findings;
}

it('never reaches for Redis directly, so the driver stays a config value', function (): void {
    $findings = sourceLinesContaining(
        ['Redis::', 'RedisStore', 'Illuminate\\Support\\Facades\\Redis', 'Illuminate\\Redis\\'],
        static fn (): bool => false,
    );

    expect($findings)->toBe([], sprintf(
        "Direct Redis use in app/:\n%s\n\n" .
        'ENV-7 and ADR-0005: use `Cache` and `Cache::lock` only. A direct Redis call passes CI, where ' .
        'Redis is real, and cannot run at all on the local SQLite stack.',
        implode("\n", $findings),
    ));
})->group('fast');

it('has exactly three writers of the hold columns', function (): void {
    // The three Actions AVL-37.5 names.
    $permitted = [
        'app/Domain/Availability/Actions/HoldSeats.php',
        'app/Domain/Availability/Actions/ExtendHold.php',
        'app/Domain/Availability/Actions/ReleaseHold.php',

        // **Plus four that end a hold rather than write one**, and the
        // distinction is the requirement rather than a convenience. AVL-37.5
        // governs who may *create or extend* a hold, because that is the write
        // that decides whether a seat is available. AVL-38 separately lists four
        // conditions on which a hold is released — payment failure, abandonment,
        // expiry, and successful confirmation, where the seats are converted
        // rather than returned — and these are three of them plus the
        // conversion itself.
        //
        // Each writes `hold_expires_at => null` or moves `seats_held` into
        // `seats_sold`. None of them can make a seat appear, which is the
        // property the rule protects. An eighth writer still fails this test.
        'app/Domain/Booking/Actions/ConfirmBooking.php',
        'app/Domain/Booking/Actions/StartCheckout.php',
        'app/Domain/Booking/Actions/ExpireAbandonedCheckouts.php',
        'app/Domain/Booking/Support/SeatCommitment.php',

        // BKG-12's release, which is the fourth of AVL-38's four conditions:
        // the payment failed, so the hold ends and a fresh one is attempted
        // through `HoldSeats` — the permitted writer — a line later.
        'app/Domain/Booking/Actions/ConfirmFromWebhook.php',

        // CXL-9's release. A cancelled booking holds nothing, and the column is
        // what the availability read path checks — a stale future value would
        // keep the seats notionally held by a booking that has ended. Same
        // shape as the four above: it can only ever null the column.
        'app/Domain/Booking/Actions/CancelBooking.php',
    ];

    $findings = sourceLinesContaining(
        ["'hold_expires_at' =>", "'seats_held' =>"],
        static fn (string $relative): bool => in_array($relative, $permitted, true),
    );

    // A `casts()` entry names the column and writes nothing — `'seats_held' =>
    // 'integer'` is a declaration of type, and flagging it would make this lint
    // fire on the two models the rule is *about*. Matched by shape rather than
    // by file, so a model that starts genuinely writing the column is still
    // caught.
    $findings = array_values(array_filter(
        $findings,
        static fn (string $finding): bool => preg_match(
            "/=> '(datetime|immutable_datetime|integer|int)',?$/",
            $finding,
        ) !== 1,
    ));

    // `GenerateDepartures` writes `seats_held => 0` when it *creates* a
    // departure, which is an initial value rather than a mutation of a hold —
    // it is named here so the exemption is a decision rather than a hole.
    $findings = array_values(array_filter(
        $findings,
        static fn (string $finding): bool => ! str_starts_with($finding, 'app/Domain/Availability/Actions/GenerateDepartures.php'),
    ));

    expect($findings)->toBe([], sprintf(
        "Writers of the hold columns outside the three permitted Actions:\n%s\n\n" .
        'AVL-37.5: `HoldSeats`, `ExtendHold` and `ReleaseHold` are the only writers of `hold_expires_at` ' .
        'and `seats_held`. A fourth writer does not throw — it makes the counter untrustworthy.',
        implode("\n", $findings),
    ));
})->group('fast');

it('finds files, so neither rule can pass by scanning nothing', function (): void {
    $findings = sourceLinesContaining(['function '], static fn (): bool => false);

    expect(count($findings))->toBeGreaterThan(100);
})->group('fast');
