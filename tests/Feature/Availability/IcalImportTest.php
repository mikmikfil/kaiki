<?php

declare(strict_types=1);

use App\Domain\Availability\Actions\SyncIcalSource;
use App\Domain\Availability\Support\IcalSyncResult;
use App\Enums\BlockReason;
use App\Jobs\PollIcalSourcesJob;
use App\Jobs\SyncIcalSourceJob;
use App\Models\IcalSource;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| OPS-13, OPS-15: pulling somebody else's calendar in
|--------------------------------------------------------------------------
|
| The two failure modes are not symmetrical. Leaving a boat blocked for a
| cancelled charter is annoying and an operator fixes it in ten seconds. Freeing
| a boat that is actually out is a double booking discovered on a quay.
|
| So the tests that matter here are the ones proving the ambiguous cases resolve
| toward *keeping the boat blocked*: a feed that will not parse changes nothing,
| an empty feed deletes nothing, and a block somebody has paid against is never
| removed no matter what the source says.
|
| The other half is the format itself. `DTEND` on an all-day event is
| **exclusive** — Airbnb's 4th–6th is two nights, not three days — and reading
| it as inclusive blocks a boat on a day it is free. That is the single most
| common iCal bug in the accommodation trade.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    // One fake, reading a holder the tests reassign.
    //
    // `Http::fake()` **appends** a stub rather than replacing the previous one,
    // and the first matching stub wins — so a second `Http::fake(['*' => …])`
    // in the same test is silently ignored and every assertion after it runs
    // against the first response. That cost this file five green-looking
    // failures before it was spotted.
    icalResponder()->response = Http::response('', 500);

    Http::fake(static fn () => icalResponder()->response);
});

/**
 * The one mutable thing the fake reads.
 *
 * A holder object rather than a property on the test case: Pest's `$this` is a
 * different class at registration time than at assertion time, and a dynamic
 * property there is invisible to static analysis — which is how the fake bug
 * above went unnoticed in the first place.
 */
function icalResponder(): object
{
    static $holder = null;

    $holder ??= new class
    {
        public mixed $response = null;
    };

    return $holder;
}

/**
 * Set what the next fetch returns.
 *
 * @param  array<string, string>  $headers
 */
function respond(string $body, int $status = 200, array $headers = []): void
{
    icalResponder()->response = Http::response($body, $status, $headers);
}

/** @return array{0: Tenant, 1: IcalSource, 2: Vessel} */
function sourceFixture(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    [$source, $vessel] = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα']);

        $source = IcalSource::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'name' => 'Airbnb',
            'url' => 'https://example.test/calendar.ics',
            'is_active' => true,
        ]);

        return [$source, $vessel];
    });

    return [$tenant, $source, $vessel];
}

function ics(string $events): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . $events . "END:VCALENDAR\r\n";
}

function vevent(string $uid, string $start, string $end, string $summary = 'Reserved', string $extra = ''): string
{
    return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20260901T000000Z\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\nSUMMARY:{$summary}\r\n{$extra}END:VEVENT\r\n";
}

function sync(Tenant $tenant, IcalSource $source): IcalSyncResult
{
    /** @var IcalSyncResult $result */
    $result = Tenancy::forTenant($tenant, fn (): IcalSyncResult => app(SyncIcalSource::class)($source));

    return $result;
}

/** @return Collection<int, VesselBlock> */
function blocksFor(Tenant $tenant, IcalSource $source): Collection
{
    /** @var Collection<int, VesselBlock> $blocks */
    $blocks = Tenancy::forTenant($tenant, fn (): Collection => VesselBlock::query()
        ->where('ical_source_id', $source->getKey())
        ->orderBy('starts_at_utc')
        ->get());

    return $blocks;
}

it('imports an event as a block, keeping the source\'s own words', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z', 'Reserved — Airbnb')
    ));

    $result = sync($tenant, $source);

    $blocks = blocksFor($tenant, $source);

    expect($result->created)->toBe(1)
        ->and($blocks)->toHaveCount(1)
        ->and($blocks[0]->reason)->toBe(BlockReason::ExternalIcal)
        ->and($blocks[0]->external_uid)->toBe('evt-1')
        // The operator looking at a bar on their calendar needs to know what it
        // is. "Reserved — Airbnb" is the difference between a block they trust
        // and one they delete.
        ->and($blocks[0]->title)->toBe('Reserved — Airbnb');
});

it('reads an all-day DTEND as exclusive, the way the format defines it', function (): void {
    [$tenant, $source] = sourceFixture();

    // Airbnb's two-night booking: arrive the 4th, leave the 6th.
    respond(ics(
        "BEGIN:VEVENT\r\nUID:stay-1\r\nDTSTAMP:20260901T000000Z\r\nDTSTART;VALUE=DATE:20260704\r\nDTEND;VALUE=DATE:20260706\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\n"
    ));

    sync($tenant, $source);

    $block = blocksFor($tenant, $source)->first();

    expect($block)->not->toBeNull()
        ->and($block->is_all_day)->toBeTrue()
        // The 4th and the 5th. Reading DTEND as inclusive would block the 6th
        // as well, on a boat that is free that day.
        ->and($block->local_date->toDateString())->toBe('2026-07-04')
        ->and($block->local_end_date->toDateString())->toBe('2026-07-05');
});

it('is idempotent: the same feed twice is one block', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(vevent('evt-1', '20260920T070000Z', '20260920T130000Z')));

    sync($tenant, $source);
    $second = sync($tenant, $source);

    expect(blocksFor($tenant, $source))->toHaveCount(1)
        ->and($second->created)->toBe(0)
        ->and($second->updated)->toBe(1);
});

it('removes a block whose event vanished from the source', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-2', '20260921T070000Z', '20260921T130000Z')
    ));

    sync($tenant, $source);
    expect(blocksFor($tenant, $source))->toHaveCount(2);

    // The window the second feed covers still contains evt-2's start, so its
    // disappearance is a real deletion rather than a feed that shortened its
    // horizon.
    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-3', '20260922T070000Z', '20260922T130000Z')
    ));

    $result = sync($tenant, $source);

    expect($result->removed)->toBe(1)
        ->and(blocksFor($tenant, $source)->pluck('external_uid')->all())
        ->toBe(['evt-1', 'evt-3']);
});

it('never removes a block that has become a booking', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-2', '20260921T070000Z', '20260921T130000Z')
    ));

    sync($tenant, $source);

    // Somebody paid for the second one.
    Tenancy::forTenant($tenant, function () use ($source): void {
        VesselBlock::query()
            ->where('ical_source_id', $source->getKey())
            ->where('external_uid', 'evt-2')
            ->update(['booking_id' => 4242]);
    });

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-3', '20260922T070000Z', '20260922T130000Z')
    ));

    sync($tenant, $source);

    // OPS-15, word for word: the booking outranks the calendar it came from.
    expect(blocksFor($tenant, $source)->pluck('external_uid')->all())
        ->toContain('evt-2');
});

it('changes nothing when the feed will not parse', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(vevent('evt-1', '20260920T070000Z', '20260920T130000Z')));
    sync($tenant, $source);

    respond('<html>we moved, please log in</html>');

    $result = sync($tenant, $source);

    // An empty calendar and a broken one are the same bytes to a naive reader.
    // Not knowing must leave yesterday's answer alone.
    expect($result->failed)->toBeTrue()
        ->and(blocksFor($tenant, $source))->toHaveCount(1)
        ->and($source->refresh()->consecutive_failures)->toBe(1)
        ->and($source->last_error)->not->toBeNull();
});

it('deletes nothing when a feed comes back empty', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(vevent('evt-1', '20260920T070000Z', '20260920T130000Z')));
    sync($tenant, $source);

    respond(ics(''));

    $result = sync($tenant, $source);

    // A legitimately empty calendar and a subtly broken publisher look the
    // same from here, and only one of the two readings can free a boat that is
    // not free.
    expect($result->removed)->toBe(0)
        ->and(blocksFor($tenant, $source))->toHaveCount(1);
});

it('treats a cancelled event as one that disappeared', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-2', '20260921T070000Z', '20260921T130000Z')
    ));

    sync($tenant, $source);

    respond(ics(
        vevent('evt-1', '20260920T070000Z', '20260920T130000Z') .
        vevent('evt-2', '20260921T070000Z', '20260921T130000Z', 'Reserved', "STATUS:CANCELLED\r\n")
    ));

    sync($tenant, $source);

    // Keeping it would hold a boat for a charter that no longer exists.
    expect(blocksFor($tenant, $source)->pluck('external_uid')->all())->toBe(['evt-1']);
});

it('treats a 304 as a success that touches nothing', function (): void {
    [$tenant, $source] = sourceFixture();

    respond(
        ics(vevent('evt-1', '20260920T070000Z', '20260920T130000Z')),
        200,
        ['ETag' => '"abc"'],
    );

    sync($tenant, $source);

    expect($source->refresh()->etag)->toBe('"abc"');

    respond('', 304);

    $result = sync($tenant, $source);

    expect($result->unchanged)->toBeTrue()
        ->and($result->failed)->toBeFalse()
        ->and(blocksFor($tenant, $source))->toHaveCount(1)
        ->and($source->refresh()->consecutive_failures)->toBe(0);
});

it('surfaces a source to the operator on the third consecutive failure', function (): void {
    [$tenant, $source] = sourceFixture();

    respond('nope', 500);

    sync($tenant, $source);
    expect($source->refresh()->needsAttention())->toBeFalse();

    sync($tenant, $source);
    expect($source->refresh()->needsAttention())->toBeFalse();

    sync($tenant, $source);

    // OPS-15. Not the first — a property-management system has a bad afternoon
    // roughly every month, and warning on one failure teaches an operator to
    // ignore the warning.
    expect($source->refresh()->needsAttention())->toBeTrue()
        ->and($source->is_active)->toBeTrue();
});

it('switches a source off after ten failures in a row', function (): void {
    [$tenant, $source] = sourceFixture();

    respond('nope', 500);

    for ($i = 0; $i < 10; $i++) {
        sync($tenant, $source);
    }

    // A feed that has failed ten times is not coming back on its own, and a
    // poller hammering a dead URL every fifteen minutes for ever is a bill
    // somebody else pays.
    expect($source->refresh()->is_active)->toBeFalse()
        ->and($source->needsAttention())->toBeTrue();
});

it('clears the failure count on the first success', function (): void {
    [$tenant, $source] = sourceFixture();

    respond('nope', 500);
    sync($tenant, $source);
    sync($tenant, $source);

    expect($source->refresh()->consecutive_failures)->toBe(2);

    respond(ics(vevent('evt-1', '20260920T070000Z', '20260920T130000Z')));
    sync($tenant, $source);

    // Reset rather than decremented: carrying the count forward would
    // eventually disable a feed that has worked for a month.
    expect($source->refresh()->consecutive_failures)->toBe(0)
        ->and($source->last_error)->toBeNull();
});

it('dispatches one job per due source and skips the fresh ones', function (): void {
    Queue::fake();

    [$tenant, $source] = sourceFixture();

    $fresh = Tenancy::forTenant($tenant, fn (): IcalSource => IcalSource::factory()->create([
        'vessel_id' => $source->vessel_id,
        'url' => 'https://example.test/other.ics',
        'is_active' => true,
        'last_synced_at' => Carbon::now()->subMinutes(2),
    ]));

    (new PollIcalSourcesJob)->handle();

    // The never-synced one is due; the one checked two minutes ago is not.
    Queue::assertPushed(SyncIcalSourceJob::class, 1);
    Queue::assertPushed(
        SyncIcalSourceJob::class,
        static fn (SyncIcalSourceJob $job): bool => $job->icalSourceId === $source->getKey(),
    );

    expect($fresh->exists)->toBeTrue();
});
