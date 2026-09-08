<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

/**
 * `Kaiki-Signature: v1=<hex>` (`docs/api.md` §8.3).
 *
 * ## The timestamp is inside the signature, which is the whole point
 *
 * `HMAC-SHA256(secret, "{timestamp}.{raw body}")`, not of the body alone. A
 * signature over the body only is replayable for ever: capture one delivery,
 * post it again next month, and it verifies. Binding the timestamp in means a
 * receiver can refuse anything more than five minutes from its own clock and
 * know the timestamp was not edited to suit.
 *
 * ## Rotation publishes two, comma-separated
 *
 * §8.3: `v1=<new>,v1=<old>` for twenty-four hours, so an operator can roll a
 * secret without dropping a delivery. The consumer's rule is *any* signature in
 * the header matching — which is why {@see verify()} exists here at all. Kaiki
 * is the sender, but the WordPress plugin's cache-bust endpoint is a receiver
 * of this same scheme, and one verification routine to review beats two.
 *
 * ## Constant time, both ways
 *
 * `hash_equals`, never `===`. A comparison that returns early on the first
 * wrong byte tells an attacker how much of a forged signature was right, one
 * byte at a time. It is cheap to get right and impossible to notice when wrong.
 */
final class WebhookSignature
{
    /** The only scheme version there is, and the prefix each signature carries. */
    public const VERSION = 'v1';

    /** §8.3: a receiver refuses anything further than this from its own clock. */
    public const TOLERANCE_SECONDS = 300;

    /** One signature, for one secret. */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return self::VERSION . '=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * The header value, with every secret that is currently valid.
     *
     * One in the ordinary case; two during a rotation, newest first, so a
     * consumer that only reads the first one still works.
     *
     * @param  list<string>  $secrets
     */
    public static function header(array $secrets, int $timestamp, string $body): string
    {
        return implode(',', array_map(
            static fn (string $secret): string => self::sign($secret, $timestamp, $body),
            $secrets,
        ));
    }

    /**
     * Does any signature in the header match, and is the timestamp fresh?
     *
     * Both halves, because either alone is not verification: a valid signature
     * with an old timestamp is a replay, and a fresh timestamp with no valid
     * signature is anybody at all.
     */
    public static function verify(
        string $secret,
        string $header,
        int $timestamp,
        string $body,
        ?int $now = null,
    ): bool {
        if (abs(($now ?? time()) - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = self::sign($secret, $timestamp, $body);

        foreach (explode(',', $header) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }

        return false;
    }
}
