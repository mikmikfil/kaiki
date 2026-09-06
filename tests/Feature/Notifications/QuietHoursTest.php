<?php

declare(strict_types=1);

use App\Domain\Notifications\Support\QuietHours;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| BKG-18 (RESOLVED): the night, and the trip that has already left
|--------------------------------------------------------------------------
|
| > *…not sent between 21:00 and 08:00 local; a reminder that would fall in that
| > window is delivered at 08:00, **unless doing so would place it after the
| > event it warns about, in which case it is dropped and logged**.*
|
| The requirement's own note gives the reason for the first half: an SMS at
| 03:00 is a support incident. The second half is the one that gets skipped, and
| its failure mode is worse — a "your trip is tomorrow" message arriving while
| the guest is standing on the boat.
|
| Every assertion here is in **tenant-local** wall-clock time, including one
| pair that brackets a clock change. In Athens the summer offset is three hours
| and the winter offset is two: a rule applied to a UTC hour is right for half
| the year and silently wrong for the other half, and 05:00 UTC in July is
| 08:00 local — the exact edge this protects.
|
*/

function athens(): Tenant
{
    return Tenant::factory()->make(['timezone' => 'Europe/Athens']);
}

it('sends straight away in the middle of the afternoon', function (): void {
    // 14:00 Athens.
    $at = Carbon::parse('2026-07-04 11:00:00', 'UTC');

    $decision = QuietHours::decide(athens(), $at);

    expect($decision->deliver)->toBeTrue()
        ->and($decision->at?->toIso8601String())->toBe($at->toIso8601String())
        ->and($decision->wasDeferred($at))->toBeFalse();
})->group('fast');

it('holds a three in the morning message until eight', function (): void {
    // 03:00 Athens, in summer — 00:00 UTC.
    $at = Carbon::parse('2026-07-04 00:00:00', 'UTC');

    $decision = QuietHours::decide(athens(), $at, Carbon::parse('2026-07-20 06:00:00', 'UTC'));

    expect($decision->deliver)->toBeTrue()
        ->and($decision->wasDeferred($at))->toBeTrue()
        // 08:00 Athens is 05:00 UTC in July.
        ->and($decision->at?->setTimezone('Europe/Athens')->format('H:i'))->toBe('08:00')
        ->and($decision->at?->toDateString())->toBe('2026-07-04');
})->group('fast');

it('holds a late-evening message until the next morning, not the same one', function (): void {
    // 22:30 Athens on the 4th.
    $at = Carbon::parse('2026-07-04 19:30:00', 'UTC');

    $decision = QuietHours::decide(athens(), $at, Carbon::parse('2026-07-20 06:00:00', 'UTC'));

    // The **5th**, not the 4th. Sending at an 08:00 that has already gone would
    // defer a message into the past, and the scheduler would send it instantly
    // — at half past ten at night.
    expect($decision->at?->setTimezone('Europe/Athens')->toDateString())->toBe('2026-07-05')
        ->and($decision->at?->setTimezone('Europe/Athens')->format('H:i'))->toBe('08:00');
})->group('fast');

it('measures the window in local hours across a clock change', function (): void {
    // Both of these are 07:30 **Athens** — inside the window, on either side of
    // the October clock change. In UTC they are 04:30 and 05:30, which is why
    // a rule written against UTC hours is right for half the year.
    $summer = Carbon::parse('2026-07-04 04:30:00', 'UTC');
    $winter = Carbon::parse('2026-11-10 05:30:00', 'UTC');

    foreach ([$summer, $winter] as $at) {
        $decision = QuietHours::decide(athens(), $at, $at->copy()->addMonth());

        expect($decision->wasDeferred($at))->toBeTrue($at->toIso8601String())
            ->and($decision->at?->setTimezone('Europe/Athens')->format('H:i'))
            ->toBe('08:00', $at->toIso8601String());
    }
})->group('fast');

it('sends at 08:00 exactly, which is outside the window', function (): void {
    // 08:00 Athens in summer. The window is 21:00 **to** 08:00, so eight
    // o'clock itself is open — an off-by-one here defers every message by a
    // day.
    $at = Carbon::parse('2026-07-04 05:00:00', 'UTC');

    expect(QuietHours::decide(athens(), $at)->wasDeferred($at))->toBeFalse();
})->group('fast');

it('holds at 21:00 exactly, which is inside it', function (): void {
    // 21:00 Athens. The other edge, and the other off-by-one.
    $at = Carbon::parse('2026-07-04 18:00:00', 'UTC');

    expect(QuietHours::decide(athens(), $at, $at->copy()->addWeek())->wasDeferred($at))->toBeTrue();
})->group('fast');

it('drops the message rather than delivering it after the trip', function (): void {
    // 02:00 Athens on the day of a 06:00 Athens departure. Eight o'clock is
    // two hours *after* the boat leaves.
    $at = Carbon::parse('2026-07-04 23:00:00', 'UTC');
    $departure = Carbon::parse('2026-07-05 03:00:00', 'UTC');

    $decision = QuietHours::decide(athens(), $at, $departure);

    // **The half that gets skipped.** A "your trip is tomorrow" text arriving
    // while the guest is on the boat is worse than no text at all.
    expect($decision->deliver)->toBeFalse()
        ->and($decision->at)->toBeNull();
})->group('fast');

it('sends a message that warns about nothing, however late it is', function (): void {
    $at = Carbon::parse('2026-07-04 23:00:00', 'UTC');

    // A voucher expiry has no departure behind it, so there is no "after the
    // event" to be after. It is still deferred to the morning — nobody wants a
    // 02:00 text — but never dropped.
    $decision = QuietHours::decide(athens(), $at, null);

    expect($decision->deliver)->toBeTrue()
        ->and($decision->wasDeferred($at))->toBeTrue();
})->group('fast');

it('uses the tenant timezone rather than the server one', function (): void {
    // 23:00 in Athens is 21:00 in London and 13:00 in Los Angeles. A tenant in
    // each would get three different answers, and only the tenant's own is
    // right — BKG-18 says "local" and means theirs.
    $at = Carbon::parse('2026-07-04 20:00:00', 'UTC');

    $athens = QuietHours::decide(Tenant::factory()->make(['timezone' => 'Europe/Athens']), $at);
    $angeles = QuietHours::decide(Tenant::factory()->make(['timezone' => 'America/Los_Angeles']), $at);

    expect($athens->wasDeferred($at))->toBeTrue()
        ->and($angeles->wasDeferred($at))->toBeFalse();
})->group('fast');
