<?php

declare(strict_types=1);

namespace App\Data\Availability;

use Illuminate\Support\Carbon;
use LogicException;
use Spatie\LaravelData\Data;

/**
 * The result of converting a local date and time to a UTC instant
 * (ADR-0016, spec AVL-15 to AVL-18).
 *
 * Three outcomes, not one, and that is the point:
 *
 * - **Existent and unambiguous** — the ordinary case, one instant.
 * - **Non-existent** — the local time is inside the spring-forward gap and
 *   never happens on that date. ADR-0016 Option A refuses to invent one, so
 *   `instant` is null and the caller records an issue for the operator.
 * - **Ambiguous** — the local time happens twice on the autumn fall-back date.
 *   The instant is the **earlier** of the two and `ambiguous` is true, so the
 *   panel can badge the row.
 *
 * A resolver that returned a bare `Carbon` would have to invent a time in the
 * gap and pick silently in the overlap, which is exactly what ADR-0016 rejected:
 * *"never silently moves a departure a guest has booked."*
 */
final class LocalInstantData extends Data
{
    private function __construct(
        public readonly ?Carbon $instant,
        public readonly bool $existent,
        public readonly bool $ambiguous,
    ) {}

    public static function found(Carbon $instant, bool $ambiguous = false): self
    {
        return new self($instant->copy()->utc(), true, $ambiguous);
    }

    /** The spring-forward gap: this local time does not happen on this date. */
    public static function nonExistent(): self
    {
        return new self(null, false, false);
    }

    /**
     * The instant, for a caller that has already checked.
     *
     * Throws rather than returning null, so a caller who skipped `existent`
     * fails here instead of writing a departure at whatever `?? now()` gave
     * them.
     */
    public function instantOrFail(): Carbon
    {
        return $this->instant ?? throw new LogicException(
            'This local time does not exist on that date (DST spring forward).',
        );
    }
}
