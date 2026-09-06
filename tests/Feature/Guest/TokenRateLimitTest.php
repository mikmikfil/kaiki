<?php

declare(strict_types=1);

use App\Domain\Booking\Support\GuestTokenResolver;
use App\Http\Middleware\ThrottleTokenLookups;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| TOK-4: two limits, counting two different things
|--------------------------------------------------------------------------
|
| Thirty lookups a minute is a **usability** limit — it stops a runaway client.
| Ten *failures* a minute is the **security** limit, and it is what makes
| guessing a forty-character token pointless in practice rather than only in
| theory.
|
| The test that matters most here is the one asserting a legitimate guest is
| **not** caught. One combined limit of ten would throttle somebody who opened
| their booking in three tabs while waiting for a bank app; one combined limit
| of thirty would give an attacker thirty guesses a minute. The two questions
| are different and so are the numbers.
|
*/

beforeEach(function (): void {
    RateLimiter::clear(ThrottleTokenLookups::lookupKey('127.0.0.1'));
    RateLimiter::clear(ThrottleTokenLookups::failureKey('127.0.0.1'));
});

it('lets a guest refresh their own booking well past the failure limit', function (): void {
    [, $booking] = GuestPageScenario::booking();

    // Twelve refreshes — comfortably over the ten-failure budget and inside the
    // thirty-lookup one. A guest waiting for a bank app to come back must never
    // be locked out of their own booking for being impatient.
    for ($i = 0; $i < 12; $i++) {
        get('/b/' . $booking->manage_token)->assertOk();
    }
});

it('refuses the thirty-first lookup in a minute', function (): void {
    [, $booking] = GuestPageScenario::booking();

    for ($i = 0; $i < ThrottleTokenLookups::LOOKUPS_PER_MINUTE; $i++) {
        get('/b/' . $booking->manage_token)->assertOk();
    }

    get('/b/' . $booking->manage_token)
        ->assertStatus(429)
        // The header carries the number; the page does not. A page that told a
        // guesser exactly how long their budget lasts would be a small gift.
        ->assertHeader('Retry-After');
});

it('refuses the eleventh failure in a minute, well before the lookup limit', function (): void {
    // Ten wrong tokens, each a real lookup that came back empty.
    for ($i = 0; $i < ThrottleTokenLookups::FAILURES_PER_MINUTE; $i++) {
        get('/b/' . GuestTokenResolver::mint())->assertStatus(404);
    }

    // The eleventh is refused rather than looked up — and this is well inside
    // the thirty-lookup budget, which is the whole point of having two limits.
    get('/b/' . GuestTokenResolver::mint())->assertStatus(429);
});

it('refuses a good token once the failure budget is spent', function (): void {
    [, $booking] = GuestPageScenario::booking();

    for ($i = 0; $i < ThrottleTokenLookups::FAILURES_PER_MINUTE; $i++) {
        get('/b/' . GuestTokenResolver::mint())->assertStatus(404);
    }

    // The security limit is checked **before** the lookup. Somebody who has
    // already spent ten guesses this minute does not get an eleventh, however
    // good the token they finally arrive at happens to be — which is exactly
    // what stops a guesser confirming a hit.
    get('/b/' . $booking->manage_token)->assertStatus(429);
});

it('counts a success against the lookup budget and not the failure one', function (): void {
    [, $booking] = GuestPageScenario::booking();

    get('/b/' . $booking->manage_token)->assertOk();

    expect(RateLimiter::attempts(ThrottleTokenLookups::lookupKey('127.0.0.1')))->toBe(1)
        // Counting successes as failures would lock a guest out of their own
        // booking for refreshing it, which is the failure this split avoids.
        ->and(RateLimiter::attempts(ThrottleTokenLookups::failureKey('127.0.0.1')))->toBe(0);
});

it('limits the voucher page on the same budget, which is the harder one for a short code', function (): void {
    // TOK-2 singles this out: `/v/{voucher}` uses the code itself, so it is
    // *"rate-limited harder"* rather than made longer. Ten wrong guesses a
    // minute is what makes an eight-character code impractical to walk.
    for ($i = 0; $i < ThrottleTokenLookups::FAILURES_PER_MINUTE; $i++) {
        get('/v/GIFT-' . str_pad((string) $i, 8, 'A'))->assertStatus(404);
    }

    get('/v/GIFT-ANYTHING')->assertStatus(429);
});

it('states TOK-4 two numbers in one place', function (): void {
    // Not config: these are a security posture rather than a preference, and a
    // per-environment knob here would be a per-environment way to switch the
    // second one off.
    expect(ThrottleTokenLookups::LOOKUPS_PER_MINUTE)->toBe(30)
        ->and(ThrottleTokenLookups::FAILURES_PER_MINUTE)->toBe(10);
});
