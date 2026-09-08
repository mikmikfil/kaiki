<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalFeed;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| OPS-13, OPS-14: the feed, and everything it must not say
|--------------------------------------------------------------------------
|
| The URL is unauthenticated by necessity — Google Calendar will not send a
| header — so in practice everything in this file is public to anybody who ever
| sees the link. It is pasted into third-party services and forwarded between
| colleagues.
|
| So the test that matters is a subtraction: a real booking with a real guest on
| it, and none of that guest anywhere in the bytes. `ATTENDEE` is checked by name
| because it is the property an implementer adds without thinking — it is what
| an attendee field is for.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
});

/** @return array{0: Tenant, 1: IcalFeed, 2: Vessel} */
function feedFixture(bool $active = true): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    [$feed, $vessel] = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα', 'capacity_max' => 12]);

        $feed = IcalFeed::factory()->create([
            'vessel_id' => $vessel->getKey(),
            'include_departures' => true,
            'include_blocks' => true,
        ]);

        return [$feed, $vessel];
    });

    if (! $active) {
        $feed->forceFill(['is_active' => false])->save();
    }

    return [$tenant, $feed, $vessel];
}

it('publishes a busy period and not one word about the guest', function (): void {
    [$tenant, $feed, $vessel] = feedFixture();

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        $sailing = Departure::factory()->for($vessel)->at('2026-09-20', '10:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Anna Rossi',
            'guest_email' => 'anna@example.com',
            'guest_phone' => '+306900000000',
            'pax_total' => 4,
        ]);
    });

    $body = get('/ical/' . $feed->token . '.ics')
        ->assertOk()
        ->assertHeader('content-type', 'text/calendar; charset=UTF-8')
        ->getContent();

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->and($body)->toContain('BEGIN:VEVENT')
        // The boat is busy, and that is the whole message.
        ->and($body)->toContain('TRANSP:OPAQUE')
        // OPS-14, the subtraction that is the point of the feature.
        ->and($body)->not->toContain('Anna Rossi')
        ->and($body)->not->toContain('anna@example.com')
        ->and($body)->not->toContain('+306900000000')
        ->and($body)->not->toContain('ATTENDEE');
});

it('leaves a cancelled sailing out, because a cancelled trip is not an occupation', function (): void {
    [$tenant, $feed, $vessel] = feedFixture();

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        $sailing = Departure::factory()->for($vessel)->at('2026-09-21', '10:00')->withSeats(4)->create();
        $sailing->forceFill(['status' => DepartureStatus::Cancelled])->save();
    });

    $body = get('/ical/' . $feed->token . '.ics')->assertOk()->getContent();

    // A cancelled trip left in the feed tells a subscriber the boat is busy on
    // a day it is free — the direction of error that loses a charter.
    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(0);
});

it('publishes sold seats and not held ones', function (): void {
    [$tenant, $feed, $vessel] = feedFixture();

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        // Somebody is thinking about it. Fifteen minutes, and possibly nothing.
        $held = Departure::factory()->for($vessel)->at('2026-09-22', '10:00')->create();
        $held->forceFill(['seats_sold' => 0, 'seats_held' => 4])->save();
    });

    $body = get('/ical/' . $feed->token . '.ics')->assertOk()->getContent();

    // A hold in the feed makes the boat busy for a quarter of an hour and then
    // not, which is the flapping that teaches a subscriber to ignore it.
    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(0);
});

it('answers 404 for a revoked feed, the same as for an unknown token', function (): void {
    [, $feed] = feedFixture(active: false);

    // The same answer for both, so a 403 cannot confirm that a token was once
    // real and that its neighbours are worth trying.
    get('/ical/' . $feed->token . '.ics')->assertNotFound();
    get('/ical/' . str_repeat('a', 40) . '.ics')->assertNotFound();
});

it('stops answering the old address the moment the token is rotated', function (): void {
    [$tenant, $feed] = feedFixture();

    $old = $feed->token;

    get('/ical/' . $old . '.ics')->assertOk();

    $new = Tenancy::forTenant($tenant, fn (): string => $feed->rotateToken());

    expect($new)->not->toBe($old);

    get('/ical/' . $old . '.ics')->assertNotFound();
    get('/ical/' . $new . '.ics')->assertOk();
});

it('keeps UIDs stable across two reads of an unchanged calendar', function (): void {
    [$tenant, $feed, $vessel] = feedFixture();

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        Departure::factory()->for($vessel)->at('2026-09-23', '10:00')->withSeats(2)->create();
    });

    $first = get('/ical/' . $feed->token . '.ics')->assertOk()->getContent();
    $second = get('/ical/' . $feed->token . '.ics')->assertOk()->getContent();

    preg_match_all('/^UID:(.+)$/m', $first, $a);
    preg_match_all('/^UID:(.+)$/m', $second, $b);

    // A UID that moved between fetches deletes and recreates every event in
    // somebody's calendar — a notification storm there, and silence here.
    expect($a[1])->not->toBeEmpty()->and($a[1])->toBe($b[1]);
});

it('counts a read on the row without turning a poll into an event storm', function (): void {
    [, $feed] = feedFixture();

    get('/ical/' . $feed->token . '.ics')->assertOk();

    $feed->refresh();

    expect($feed->access_count)->toBe(1)
        ->and($feed->last_accessed_at)->not->toBeNull();
});

it('honours the two include switches', function (): void {
    [$tenant, $feed, $vessel] = feedFixture();

    Tenancy::forTenant($tenant, function () use ($vessel): void {
        Departure::factory()->for($vessel)->at('2026-09-24', '10:00')->withSeats(2)->create();
    });

    $feed->forceFill(['include_departures' => false])->save();

    $body = get('/ical/' . $feed->token . '.ics')->assertOk()->getContent();

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(0);
});
