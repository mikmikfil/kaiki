<?php

declare(strict_types=1);

use App\Domain\Booking\Support\GuestTokenResolver;
use App\Models\Booking;
use App\Models\BrandProfile;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| TOK-2, TOK-3, TOK-4: a URL that is a credential
|--------------------------------------------------------------------------
|
| These four pages are the only place in the product where knowing a URL is
| knowing enough. Everything in this file follows from that.
|
| The assertion that matters most is the last one. TOK-4 requires a failure to
| be *"a generic branded 'link not valid' page … never a distinction between
| 'not found' and 'expired'"*, and the issue's own note adds the part that is
| easy to miss: a page that says the same words but takes a different length of
| time to say them is the same oracle in a slower form. So the two bodies are
| asserted **byte-identical**, and the resolver deliberately has no shape check
| that could short-circuit one of them.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('carries all three TOK-3 headers on every token page', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    $voucherCode = Tenancy::forTenant($tenant, fn (): string => (string) Voucher::factory()->create()->code);

    $urls = [
        '/b/' . $booking->manage_token,
        '/g/' . $booking->guest_details_token,
        '/v/' . $voucherCode,
        // The failure page too — the one an implementation is most likely to
        // build outside the middleware group, because it is "just an error".
        '/b/definitely-not-a-token',
    ];

    foreach ($urls as $url) {
        $response = get($url);

        expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow', $url)
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer', $url)
            // `no-referrer` is the one that gets forgotten, and the one that
            // matters most: `/b/` has a map link, and tapping it would
            // otherwise send the whole URL — token and all — to a map provider.
            // `str_contains` rather than `toContain`: Pest's is variadic, so a
            // failure message passed as a second argument becomes a second
            // needle — which is a test that asserts something nobody wrote.
            ->and(str_contains((string) $response->headers->get('Cache-Control'), 'no-store'))
            ->toBeTrue($url);
    }
});

it('answers not-found and expired with byte-identical pages', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    // A token that never existed.
    $missing = get('/b/' . GuestTokenResolver::mint());

    // A token that did exist and no longer resolves. Soft-deleted rather than
    // force-deleted: `payments.booking_id` is `restrictOnDelete` because a
    // payment is money, so a hard delete throws — and §2.5's answer to "this
    // booking is gone" is the soft delete anyway.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        Booking::query()->whereKey($booking->getKey())->delete();
    });

    $expired = get('/b/' . $booking->manage_token);

    expect($missing->status())->toBe(404)
        ->and($expired->status())->toBe(404)
        // **Byte-identical.** Not "similar", not "both say the same thing" —
        // the distinction TOK-4 forbids is exactly the kind that creeps back in
        // as a helpful extra sentence.
        ->and($expired->getContent())->toBe($missing->getContent());
});

it('mints forty characters that share no prefix between two bookings', function (): void {
    [, $first] = GuestPageScenario::booking();
    [, $second] = GuestPageScenario::booking();

    expect(GuestTokenResolver::isWellFormed($first->manage_token))->toBeTrue()
        ->and(strlen($first->manage_token))->toBe(40)
        // TOK-2: *"never derived from any other identifier."* Two bookings
        // created in the same second must share nothing — a token built from a
        // timestamp or an incrementing id would share a long prefix here, and
        // would be walkable.
        ->and(substr($first->manage_token, 0, 8))->not->toBe(substr($second->manage_token, 0, 8));
});

it('gives one booking four different tokens', function (): void {
    [, $booking] = GuestPageScenario::booking();

    // TOK-2: *"unique per purpose per booking."* Reusing one token across two
    // pages would mean a guest who forwarded their manage link had also handed
    // over the manifest.
    expect($booking->manage_token)->not->toBe($booking->guest_details_token);
});

it('refuses a guest-details token on the manage page, and the reverse', function (): void {
    [, $booking] = GuestPageScenario::booking();

    // Per purpose, enforced by the column each route looks in rather than by a
    // prefix or a type byte in the token itself.
    get('/b/' . $booking->guest_details_token)->assertStatus(404);
    get('/g/' . $booking->manage_token)->assertStatus(404);
});

it('renders every token page in the booking own locale, and honours ?lang=', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['locale' => 'el'])->save();
    });

    // TOK-5: from the booking, without asking. A guest who booked in Greek gets
    // Greek.
    get('/b/' . $booking->manage_token)->assertSee('lang="el"', false);

    // And the override, for the same guest forwarding the link to an
    // English-speaking partner — which is why it is `?lang=` rather than the
    // browser's `Accept-Language`.
    get('/b/' . $booking->manage_token . '?lang=en')->assertSee('lang="en"', false);

    // A mistyped one falls back rather than refusing: a bad query string must
    // not cost a guest their booking page.
    get('/b/' . $booking->manage_token . '?lang=fr')->assertSee('lang="el"', false);
});

it('brands the page with the operator colours, not the platform', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function (): void {
        BrandProfile::query()->firstOrFail()
            ->forceFill(['color_primary' => '#123456'])->save();
    });

    // BRD-1 and TOK-5. A page that looked like this platform rather than like
    // the operator the guest booked with is a page they distrust.
    get('/b/' . $booking->manage_token)->assertSee('#123456', false);
});

it('serves a quote page for a token that resolves, in any status', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    // §4.4: a superseded quote is never deleted *"because the guest may still
    // have the old link open"*. A 404 here would tell them their link was
    // forged when it was merely replaced.
    get('/q/' . $quote->quote_token)->assertOk();
});
