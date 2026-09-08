<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SEC-6 rate limits (`docs/api.md` §3.6).
 *
 * ## Two buckets per class, and the per-IP one is the smaller
 *
 * Every class is limited **per key** and **per IP** at once. The key limit
 * protects the platform from one operator; the per-IP limit protects that
 * operator from one visitor — a scraper walking their whole calendar holds a
 * legitimate publishable key, because the key is in the page source. Only the
 * IP tells the two apart.
 *
 * The numbers come from the contract's table and are not invented here. Class A
 * is 600 per key and 120 per IP; class B — availability — is double, because a
 * calendar mount fires one call per month view and a busy operator's homepage
 * must not throttle its own visitors.
 *
 * ## A limiter is defined for every class now, used as endpoints land
 *
 * Defining them together keeps the table in one readable place next to the
 * document it comes from. Adding a limiter beside an endpoint six issues later
 * is how two endpoints in one class end up with different numbers.
 */
final class ApiRateLimitServiceProvider extends ServiceProvider
{
    /**
     * The contract's §3.6 table: class => [per key, per IP].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const CLASSES = [
        'api-catalog' => [600, 120],
        'api-availability' => [1200, 240],
        'api-pricing' => [600, 120],
        'api-booking-writes' => [60, 20],
        'api-guest' => [120, 60],
        'api-enquiries' => [120, 5],
        'api-vouchers' => [120, 10],
        'api-sync' => [120, 120],
    ];

    public function boot(): void
    {
        $this->registerWebhookLimiter();
        $this->registerIcalLimiter();

        foreach (self::CLASSES as $name => [$perKey, $perIp]) {
            RateLimiter::for($name, function (Request $request) use ($perKey, $perIp): array {
                $key = $request->attributes->get('api_key');

                // The 429 body and its `Retry-After` come from one closure
                // for every bucket and every class. Laravel's default
                // `ThrottleRequestsException` renders without the envelope, so
                // a client would get a different shape from this endpoint than
                // from every other error in the API.
                $response = static fn ($request, array $headers) => self::tooManyRequests(
                    (int) ($headers['Retry-After'] ?? 60),
                );

                return [
                    // Keyed by the key's own id rather than the presented
                    // secret: the secret is hashed at rest and the id is what
                    // survives rotation, so a rotated key does not hand an
                    // abuser a fresh bucket.
                    Limit::perMinute($perKey)
                        ->by('key:' . ($key instanceof ApiKey ? $key->getKey() : 'anonymous'))
                        ->response($response),
                    Limit::perMinute($perIp)->by('ip:' . $request->ip())->response($response),
                ];
            });
        }

    }

    /** The response every limiter returns, so the shape cannot drift by class. */
    public static function tooManyRequests(int $retryAfterSeconds): Response
    {
        return ApiErrorResponse::fromKey(
            'errors.rate_limited',
            'rate_limited',
            Response::HTTP_TOO_MANY_REQUESTS,
            details: ['retry_after_seconds' => $retryAfterSeconds],
        )->withHeaders(['Retry-After' => (string) $retryAfterSeconds]);
    }

    /**
     * The inbound webhook limiter (spec PAY-7).
     *
     * **Not in the §3.6 table above**, and kept out of it on purpose: that table
     * is the public API's contract, every class in it is documented for
     * integrators, and a gateway callback is neither. Mixing them would put a
     * number in the contract that no integrator can act on.
     *
     * Keyed on the **IP alone**, because a gateway presents no API key — which
     * is also the whole reason this limiter exists rather than reusing one.
     *
     * The number is generous. Both gateways retry until they get a 2xx, a busy
     * Saturday can produce a genuine burst, and throttling a real webhook means
     * refusing to hear that somebody paid. The cap is there to bound a forged
     * stream, not to police a real one.
     */
    /**
     * The vessel calendar feed (OPS-14: *"rate-limited"*).
     *
     * **Per IP only, and that is forced rather than chosen.** There is no API
     * key on this route and no session — the subscriber is Google's fetcher or
     * a marina's desktop client — so the address is the only thing to count
     * against.
     *
     * Sixty a minute is deliberately generous for the legitimate case, which
     * polls hourly. The limit is not there to police subscribers; it is there
     * so that walking the 40-hex-character token space costs an attacker real
     * time, and so one misconfigured client in a retry loop cannot generate
     * calendar renders for the whole platform.
     */
    private function registerIcalLimiter(): void
    {
        RateLimiter::for('ical', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) $request->ip()));
    }

    private function registerWebhookLimiter(): void
    {
        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->ip()));
    }
}
