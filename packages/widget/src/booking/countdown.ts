/**
 * The hold countdown (WGT-19, ADR-0005).
 *
 * A draft holds seats for `hold_minutes`, and the guest is filling in a form
 * while the clock runs. Three things have to happen and all three are here as
 * pure functions, so the tests need no timers:
 *
 * 1. the remaining time is shown while the hold is alive;
 * 2. **at two minutes** the widget warns — early enough to finish, late enough
 *    not to rush somebody who has four minutes left;
 * 3. on expiry it **re-fetches availability and explains**, rather than letting
 *    the guest press "pay" and meet a refusal they cannot interpret.
 *
 * The third is the one that matters. The seats are gone either way; the
 * difference is whether the guest finds out from the widget, in their own
 * language, with fresh dates behind it — or from a `409` after they typed their
 * card details in on somebody else's page.
 */

/** WGT-19's threshold. */
export const WARN_AT_MS = 2 * 60 * 1000;

export type HoldPhase = 'live' | 'warning' | 'expired';

export interface HoldState {
    readonly phase: HoldPhase;
    readonly remainingMs: number;
    /** `m:ss`, which is how a countdown reads. Never negative. */
    readonly label: string;
}

export function holdState(expiresAt: string | null, now: number = Date.now()): HoldState {
    if (expiresAt === null) {
        return { phase: 'live', remainingMs: Number.POSITIVE_INFINITY, label: '' };
    }

    const deadline = Date.parse(expiresAt);

    // An unparseable deadline is treated as "no hold" rather than as "expired":
    // a widget that told a guest their seats were gone because of a date format
    // would be inventing the one thing it must never invent.
    if (Number.isNaN(deadline)) {
        return { phase: 'live', remainingMs: Number.POSITIVE_INFINITY, label: '' };
    }

    const remainingMs = Math.max(0, deadline - now);

    return {
        phase: remainingMs === 0 ? 'expired' : remainingMs <= WARN_AT_MS ? 'warning' : 'live',
        remainingMs,
        label: format(remainingMs),
    };
}

function format(remainingMs: number): string {
    const total = Math.floor(remainingMs / 1000);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}:${seconds.toString().padStart(2, '0')}`;
}
