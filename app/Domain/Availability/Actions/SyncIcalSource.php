<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\Support\IcalEvent;
use App\Domain\Availability\Support\IcalSyncResult;
use App\Enums\BlockReason;
use App\Models\IcalSource;
use App\Models\VesselBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull one external calendar in, and make the blocks agree with it
 * (spec OPS-13, OPS-15, AVL-3).
 *
 * ## The two failure modes are not symmetrical, and that shapes everything
 *
 * Getting this wrong in one direction leaves a boat blocked for a charter that
 * was cancelled — annoying, visible, and fixed by an operator in ten seconds.
 * Getting it wrong in the other direction sells a boat that is already out on
 * somebody else's booking, on a day the operator cannot fix anything, in front
 * of guests standing on a quay.
 *
 * So every ambiguous case here resolves toward *keeping the boat blocked*:
 *
 * - **A feed that will not parse changes nothing.** An empty calendar and a
 *   broken one are the same bytes to a naive reader, and treating a fetch
 *   failure as "no events" would delete every block the source ever created.
 *   Not knowing must leave yesterday's answer alone.
 * - **A block converted into a real booking is never removed**, even when the
 *   event vanishes from the source. OPS-15 says so in as many words, and the
 *   reason is that the booking outranks the calendar it came from.
 * - **A 304 is a success that touches nothing.** It means the source is telling
 *   us it has not changed, and rebuilding from a body we did not fetch is not
 *   possible anyway.
 *
 * ## Idempotency is `(source, external_uid)`, enforced by the index
 *
 * OPS-15: *"idempotent by external UID"*. `vblocks_source_uid_uq` is a unique
 * index on `(tenant_id, ical_source_id, external_uid)`, so the guarantee is the
 * database's rather than this method's — two syncs racing produce one row and a
 * constraint violation, not two blocks. `updateOrCreate` against that key is
 * what makes a re-poll of an unchanged feed a no-op.
 *
 * ## Deletions are scoped to this source, and never reach into the past
 *
 * A source that stops listing an event means the event is gone. But a feed only
 * ever covers the window its publisher chose, and most trim the past — "from
 * today forward" is the common shape. So a block earlier than the feed's own
 * earliest event is left alone: it is absent because it aged out of somebody's
 * horizon, not because the charter never happened.
 *
 * The guard is **one-sided**, deliberately. Bounding it by the latest live event
 * too looks symmetrical and is wrong — the window would shrink as events vanish,
 * so anything disappearing from the end of the feed falls outside its own range
 * and survives for ever. The future needs no guard: time only moves events
 * closer to a horizon, never back out of one.
 */
final class SyncIcalSource
{
    /**
     * How long to wait for somebody else's server.
     *
     * Short. This runs every fifteen minutes across every source on the
     * platform, and a property-management system having a bad afternoon must
     * not hold a queue worker for a minute per feed.
     */
    private const TIMEOUT_SECONDS = 15;

    /** A feed larger than this is refused rather than parsed. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __invoke(IcalSource $source, ?Carbon $now = null): IcalSyncResult
    {
        $now ??= Carbon::now();

        try {
            $response = Http::withHeaders($this->conditionalHeaders($source))
                ->timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent('Kaiki/1.0 (+calendar sync)')
                ->get($source->url);
        } catch (Throwable $e) {
            return $this->fail($source, 'ical.errors.unreachable', $e->getMessage(), $now);
        }

        if ($response->status() === 304) {
            // Nothing changed at the source. A success, and deliberately not a
            // reason to touch a single block.
            return $this->succeed($source, new IcalSyncResult(unchanged: true), $now, $source->etag);
        }

        if (! $response->successful()) {
            return $this->fail($source, 'ical.errors.http_status', 'HTTP ' . $response->status(), $now);
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            return $this->fail($source, 'ical.errors.too_large', 'size=' . strlen($body), $now);
        }

        try {
            $events = IcalEvent::parse($body, $this->timezone($source));
        } catch (Throwable $e) {
            return $this->fail($source, 'ical.errors.unparseable', $e->getMessage(), $now);
        }

        $result = $this->apply($source, $events, $now);

        return $this->succeed($source, $result, $now, $response->header('ETag') ?: null);
    }

    /**
     * Write the events, and remove what the source no longer lists.
     *
     * @param  list<IcalEvent>  $events
     */
    private function apply(IcalSource $source, array $events, Carbon $now): IcalSyncResult
    {
        $live = array_values(array_filter($events, static fn (IcalEvent $e): bool => ! $e->isCancelled));

        $created = 0;
        $updated = 0;

        $seen = [];

        DB::transaction(function () use ($source, $live, &$created, &$updated, &$seen): void {
            foreach ($live as $event) {
                $seen[] = $event->uid;

                $block = VesselBlock::query()->firstOrNew([
                    'ical_source_id' => $source->getKey(),
                    'external_uid' => $event->uid,
                ]);

                $existed = $block->exists;

                $block->fill([
                    'vessel_id' => $source->vessel_id,
                    'starts_at_utc' => $event->startUtc,
                    'ends_at_utc' => $event->endUtc,
                    'local_date' => $event->startUtc->copy()->setTimezone($this->timezone($source))->toDateString(),
                    // Inclusive on screen, exclusive in the maths — the same
                    // convention `CreateVesselBlock` uses. The stored window
                    // ends at the start of the next day; the operator is shown
                    // the last day the boat is actually out.
                    'local_end_date' => $event->endUtc->copy()
                        ->setTimezone($this->timezone($source))
                        ->subSecond()
                        ->toDateString(),
                    'is_all_day' => $event->isAllDay,
                    'reason' => BlockReason::ExternalIcal,
                    // The source's own words, truncated. Useful to an operator
                    // looking at their calendar wondering what the bar is —
                    // "Reserved — Airbnb" is the difference between a block
                    // they trust and one they delete.
                    'title' => $event->summary === null ? null : mb_substr($event->summary, 0, 190),
                ]);

                $block->save();

                $existed ? $updated++ : $created++;
            }
        });

        $removed = $this->removeVanished($source, $seen, $live);

        return new IcalSyncResult(
            created: $created,
            updated: $updated,
            removed: $removed,
            total: count($live),
        );
    }

    /**
     * Delete this source's blocks that the feed no longer lists.
     *
     * Two guards, and both are load-bearing:
     *
     * - **A block with a `booking_id` survives.** OPS-15 is explicit: a block
     *   *"converted into a booking"* is not removed when its event disappears.
     *   Somebody has paid; the calendar that suggested the boat was busy no
     *   longer gets a say.
     * - **Nothing earlier than the feed's own earliest event.** A publisher
     *   sends the range it chooses, and most trim the past — "from today
     *   forward" is the common shape. A block from last season is absent
     *   because it aged out of somebody's horizon, not because the charter
     *   never happened, and deleting it would rewrite history on every poll.
     *
     * The guard is deliberately **one-sided**, and the first version was not.
     * Bounding the deletion by the *latest* live event as well looks symmetrical
     * and is wrong: the window then shrinks as events vanish, so anything
     * disappearing from the end of the feed falls outside its own range and
     * survives for ever. A cancelled charter would stay blocking the boat, which
     * a test caught and no operator would have.
     *
     * The future needs no guard, because time only moves events *closer* to the
     * horizon. A block far ahead was inside the publisher's range when it was
     * created and cannot drift back out of it.
     *
     * @param  list<string>  $seen
     * @param  list<IcalEvent>  $live
     */
    private function removeVanished(IcalSource $source, array $seen, array $live): int
    {
        if ($live === []) {
            // The feed parsed and contained nothing. That is a legitimate
            // answer — an operator with an empty Airbnb calendar — but it is
            // also what a subtly broken publisher emits, and the two are
            // indistinguishable from here. Removing every block on the strength
            // of it is the one action that could free a boat that is not free,
            // so nothing is removed until a feed says something.
            return 0;
        }

        $earliest = $live[0]->startUtc;

        foreach ($live as $event) {
            if ($event->startUtc->lessThan($earliest)) {
                $earliest = $event->startUtc;
            }
        }

        return VesselBlock::query()
            ->where('ical_source_id', $source->getKey())
            ->whereNotIn('external_uid', $seen)
            ->whereNull('booking_id')
            // One-sided on purpose. See the docblock.
            ->where('starts_at_utc', '>=', $earliest)
            ->delete();
    }

    /**
     * `If-None-Match`, so an unchanged feed costs a round trip and no body.
     *
     * Most publishers honour it and the ones that do not simply return 200.
     *
     * @return array<string, string>
     */
    private function conditionalHeaders(IcalSource $source): array
    {
        $etag = $source->etag;

        return is_string($etag) && $etag !== '' ? ['If-None-Match' => $etag] : [];
    }

    private function succeed(IcalSource $source, IcalSyncResult $result, Carbon $now, ?string $etag): IcalSyncResult
    {
        $source->forceFill([
            'last_synced_at' => $now,
            'last_success_at' => $now,
            'last_error' => null,
            // Reset, not decremented. Three failures followed by a success is a
            // feed that works; carrying the count forward would eventually
            // disable a source that has been fine for a month.
            'consecutive_failures' => 0,
            'etag' => $etag,
            'events_imported' => $result->unchanged ? $source->events_imported : $result->total,
        ])->save();

        return $result;
    }

    /**
     * Record a failure, in the operator's own language.
     *
     * The exception text goes to the structured log for us; `last_error` holds
     * a translated sentence, because the operator reading it cannot act on a
     * cURL error number (NFR-8).
     *
     * OPS-15's *"surfaced to the operator after three consecutive failures"* is
     * {@see IcalSource::needsAttention()} — the panel shows the row as needing
     * attention from the third failure, and the source switches itself off at
     * ten.
     */
    private function fail(IcalSource $source, string $key, string $detail, Carbon $now): IcalSyncResult
    {
        $failures = $source->consecutive_failures + 1;

        Log::warning('ical.sync_failed', [
            'ical_source_id' => $source->getKey(),
            'tenant_id' => $source->tenant_id,
            'vessel_id' => $source->vessel_id,
            'reason' => $key,
            'detail' => $detail,
            'consecutive_failures' => $failures,
        ]);

        $source->forceFill([
            'last_synced_at' => $now,
            'last_error' => (string) __($key),
            'consecutive_failures' => $failures,
            // Ten strikes and it stops asking. A feed that has failed ten times
            // in a row is not coming back on its own, and a poller that keeps
            // hammering a dead URL every fifteen minutes for ever is a bill
            // somebody else pays.
            'is_active' => $failures < IcalSource::FAILURE_LIMIT,
        ])->save();

        return new IcalSyncResult(failed: true);
    }

    private function timezone(IcalSource $source): string
    {
        $timezone = $source->tenant?->timezone;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }
}
