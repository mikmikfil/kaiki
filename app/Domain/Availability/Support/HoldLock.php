<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Exceptions\HoldLockUnavailable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The five-second mutex around a hold write (spec AVL-37.2, ADR-0005).
 *
 * ## The lock is not the hold, and conflating them is the whole failure mode
 *
 * AVL-37 opens by saying so in capitals. The **hold** is a database row that
 * lives for fifteen minutes and survives a Redis restart. The **lock** is a
 * mutex held for the milliseconds it takes to read a counter and write it back,
 * and if it vanishes nothing is lost but a moment of serialisation.
 *
 * A cache-resident hold is the design ADR-0005 rejected, and its failure is
 * quiet: the cache restarts, every hold evaporates, and the boat is sold twice
 * with no error anywhere.
 *
 * ## The driver is configuration, not code (ENV-7)
 *
 * `Cache::lock` resolves to the database store locally and to Redis in CI and
 * production, with no code difference. **No domain code may name `Redis::` or a
 * Redis store**, and `NoDirectRedisTest` scans `app/` to keep that true rather
 * than merely intended — a direct Redis call works perfectly in production and
 * cannot run at all on the SQLite stack this project develops on.
 *
 * ## A contended lock times out loudly
 *
 * `block()` waits three seconds and then throws. The alternative — waiting
 * forever — is a request that hangs until the web server kills it, which an
 * operator experiences as "the site is broken" rather than as "somebody else is
 * booking that seat". {@see HoldLockUnavailable} carries a sentence saying the
 * second thing.
 */
final class HoldLock
{
    /** One departure's seat counter. */
    public static function forDeparture(int $departureId): string
    {
        return "kaiki:hold:departure:{$departureId}";
    }

    /**
     * One vessel on one local date.
     *
     * Keyed by date rather than by window: two guests proposing overlapping
     * afternoon charters on the same boat must contend, and a key derived from
     * their two different start times would let them both through.
     */
    public static function forVessel(int $vesselId, string $localDate): string
    {
        return "kaiki:hold:vessel:{$vesselId}:{$localDate}";
    }

    /**
     * Run `$critical` under the named lock.
     *
     * @template T
     *
     * @param  Closure(): T  $critical
     * @return T
     *
     * @throws HoldLockUnavailable when another writer held it longer than the wait
     */
    public static function run(string $key, Closure $critical): mixed
    {
        $lock = Cache::lock($key, (int) config('kaiki.booking.hold_lock_seconds'));

        try {
            // `block()` rather than `get()`: a guest who arrives half a second
            // after somebody else should wait and then succeed, not be told the
            // seat is gone when it is not.
            return $lock->block((int) config('kaiki.booking.hold_lock_wait_seconds'), $critical);
        } catch (LockTimeoutException) {
            throw HoldLockUnavailable::forKey($key);
        }
        // No `finally { $lock->release(); }`: the closure form of `block()`
        // releases the lock itself, and releasing twice on the database store
        // throws away a lock a *different* request has since acquired.
    }
}
