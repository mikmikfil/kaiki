<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * TOK-4's two limits, which count different things.
 *
 * > *Token lookups are rate-limited to 30 requests per minute per IP and 10
 * > failed lookups per minute per IP.*
 *
 * ## Why two, and why the second one is the real one
 *
 * Thirty a minute is a **usability** limit: it stops a runaway client and a
 * hammering script, and it is generous enough that a guest refreshing their
 * booking page while they wait for a bank app is never caught. Ten *failures* a
 * minute is the **security** limit, and it is what makes guessing a
 * forty-character token pointless in practice rather than only in theory.
 *
 * Keeping them separate is the point. One limit of thirty would let an attacker
 * make thirty guesses a minute; one limit of ten would throttle a guest who
 * opened the page in three tabs. The two questions are different and so are the
 * numbers.
 *
 * ## A failure is counted after the response, not before
 *
 * The failed counter is incremented only when the page actually came back as
 * the generic "link not valid" — a 404 from this group. Counting on the way in
 * would count successes too, and a guest with a good link refreshing eleven
 * times would be locked out of their own booking.
 *
 * ## Both keys are the IP alone
 *
 * There is nothing else to key on. A token page carries no API key and no
 * session, and keying on the token would let an attacker reset their own budget
 * with every guess — which is the opposite of a rate limit.
 */
class ThrottleTokenLookups
{
    /** TOK-4's numbers, per minute per IP. */
    public const LOOKUPS_PER_MINUTE = 30;

    public const FAILURES_PER_MINUTE = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        if (RateLimiter::tooManyAttempts(self::failureKey($ip), self::FAILURES_PER_MINUTE)) {
            // The security limit, checked first: somebody who has already spent
            // ten guesses this minute does not get a lookup at all, however
            // good the eleventh token happens to be.
            return $this->refuse($ip, self::failureKey($ip));
        }

        if (RateLimiter::tooManyAttempts(self::lookupKey($ip), self::LOOKUPS_PER_MINUTE)) {
            return $this->refuse($ip, self::lookupKey($ip));
        }

        RateLimiter::hit(self::lookupKey($ip), 60);

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
            // Only a failure. See the class docblock: counting on the way in
            // would lock a guest out of their own booking for refreshing it.
            RateLimiter::hit(self::failureKey($ip), 60);
        }

        return $response;
    }

    private function refuse(string $ip, string $key): Response
    {
        return response()->view(
            'guest.too-many',
            // No `Retry-After` computed from the *failure* counter in the body:
            // the header carries it, and a page that told a guesser exactly how
            // long their budget lasts would be a small gift.
            [],
            Response::HTTP_TOO_MANY_REQUESTS,
        )->header('Retry-After', (string) RateLimiter::availableIn($key));
    }

    public static function lookupKey(string $ip): string
    {
        return 'guest-token:' . $ip;
    }

    public static function failureKey(string $ip): string
    {
        return 'guest-token-failed:' . $ip;
    }
}
