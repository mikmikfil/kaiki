<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The credential a hosted page hands its own widget (HOS-4, WGT-1).
 *
 * ## The problem this exists to solve
 *
 * The widget will not start without a `data-key`, and it authenticates every
 * call with it. An operator embedding the widget on their own site pastes a
 * publishable key they copied once. A **hosted** page cannot: the platform
 * stores only a key's hash, prefix and last four (SEC-5), so there is nothing
 * to render into the page — which is why the hosted pages shipped in M3 with
 * the mount point in the markup and no script tag above it. The product page
 * has been serving the "email us" fallback to every guest since.
 *
 * ## Minted per response, never stored
 *
 * The alternative was a durable publishable key per operator with its plaintext
 * kept in the database, and it was rejected: a `pk_` is public by design, but a
 * column holding live credentials in a readable form is a thing a database dump
 * hands over, and this platform already refused that trade for iCal URLs.
 *
 * So the page signs a short-lived assertion instead. Nothing is written, there
 * is no key to leak, revocation is automatic at expiry, and an attacker who
 * lifts one out of somebody's page source has a couple of hours of exactly what
 * a publishable key could do anyway — read the catalogue and start a booking
 * that is worthless until it is paid for.
 *
 * ## It resolves to a transient `ApiKey`, and that is the whole integration
 *
 * {@see resolve()} returns an `ApiKey` model that was never saved. Everything
 * downstream — scope checks, the secret-key-in-a-browser guard, capability
 * middleware — goes on reading an `ApiKey` and needs no case for this. The one
 * thing it must not do is write, which is why the middleware skips
 * `touchLastUsed()` for a key that does not exist in the database.
 */
final class HostedEmbedToken
{
    /**
     * Its own marker, deliberately neither `pk_` nor `sk_`.
     *
     * A shape the key parser cannot mistake for a stored key, and one an
     * operator cannot paste into the WordPress plugin and have work — this is
     * the hosted page's credential and belongs to no one else.
     */
    public const PREFIX = 'hpk_';

    /**
     * Two hours.
     *
     * Long enough for somebody to leave a trip open in a tab over lunch and
     * still book when they come back, short enough that a token copied out of a
     * page source is worth almost nothing. A guest who waits longer gets a
     * fresh page and a fresh token; nothing they had typed is lost, because the
     * widget holds its own state and the token is only presented on a request.
     */
    public const TTL_MINUTES = 120;

    /** The signed string to put in the page's `data-key`. */
    public static function issue(Tenant $tenant, ?Carbon $now = null): string
    {
        $payload = self::encode([
            't' => $tenant->uuid,
            'e' => ($now ?? Carbon::now())->copy()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);

        return self::PREFIX . $payload . '.' . self::sign($payload);
    }

    public static function looksLikeOne(string $raw): bool
    {
        return str_starts_with($raw, self::PREFIX);
    }

    /**
     * The tenant this token asserts, as an unsaved publishable key.
     *
     * Null for anything wrong: a bad signature, an expired token, a tenant that
     * has been deleted, or a hosted page that has since been switched off. The
     * caller turns all of those into the same `invalid_key` — distinguishing
     * them in the response would tell an attacker which half they got right.
     *
     * @param  list<string>  $origins  the origins this page is served from
     */
    public static function resolve(string $raw, array $origins = [], ?Carbon $now = null): ?ApiKey
    {
        if (! self::looksLikeOne($raw)) {
            return null;
        }

        $parts = explode('.', substr($raw, strlen(self::PREFIX)));

        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        // Constant time. A comparison that short-circuits on the first wrong
        // byte is a signature oracle, and this one is handed to browsers.
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $claims = self::decode($payload);

        if ($claims === null) {
            return null;
        }

        $expiry = $claims['e'] ?? null;
        $uuid = $claims['t'] ?? null;

        if (! is_int($expiry) || ! is_string($uuid)) {
            return null;
        }

        if (($now ?? Carbon::now())->getTimestamp() >= $expiry) {
            return null;
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();

        if ($tenant === null) {
            return null;
        }

        // The operator turned their hosted site off between the page being
        // rendered and this request. The page is 404 by now, and the token it
        // minted must not outlive it.
        // The coarse question on purpose (ADR-0029): a token minted for a
        // trip page must stay valid while any page is live, and only die when
        // the site is switched off entirely. An operator who turns the
        // marketing home page off has not revoked their own widget.
        if (! $tenant->hosted_site_mode->servesAnything()) {
            return null;
        }

        return self::transientKey($tenant, $origins);
    }

    /**
     * The tenant a token asserts, with no `ApiKey` around it.
     *
     * `ResolveTenant` needs this separately from {@see resolve()}: it runs
     * *after* authentication and re-derives the tenant from the raw header on
     * its own, so a credential the resolver cannot read ends the request with a
     * plain 404 no matter how well it authenticated a moment earlier. That is
     * exactly what happened when this token was first wired in — the widget
     * loaded, the key was accepted, and every call came back "that endpoint
     * does not exist".
     */
    public static function tenantFor(string $raw, ?Carbon $now = null): ?Tenant
    {
        $key = self::resolve($raw, [], $now);

        $tenant = $key?->getRelation('tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * An `ApiKey` that exists only for this request.
     *
     * Publishable, with exactly the scopes that type is allowed to hold — the
     * same ceiling any operator's own embed runs under, so the hosted page is
     * not a privileged client. It is never saved, which is what tells the
     * middleware not to record a last-used timestamp against a row that is not
     * there.
     *
     * ## `environment` is set, and leaving it null was a 500 on every booking
     *
     * `BookingCreateRequest::isTestKey()` reads `$key->environment->isTest()`
     * to decide PAY-11's `is_test` flag. A transient key with no environment
     * made that a call on null — so **every** booking started from a hosted
     * trip page died with a 500, and the widget showed «κάτι πήγε στραβά» with
     * no way to tell what. Nothing caught it: every test of the endpoint
     * authenticates with a real `ApiKey` row, which has the column.
     *
     * The value is the tenant's own sandbox flag, which is the right answer
     * rather than merely a non-null one: an operator in sandbox is testing, and
     * §3.9 says bookings made while testing are `is_test` and purged nightly.
     *
     * @param  list<string>  $origins
     */
    private static function transientKey(Tenant $tenant, array $origins): ApiKey
    {
        $key = new ApiKey;

        $key->forceFill([
            'tenant_id' => $tenant->getKey(),
            'name' => 'hosted-page',
            'type' => ApiKeyType::Publishable,
            'environment' => $tenant->is_sandbox ? ApiKeyEnvironment::Test : ApiKeyEnvironment::Live,
            'scopes' => array_map(
                static fn (ApiScope $scope): string => $scope->value,
                ApiKeyType::Publishable->allowedScopes(),
            ),
            'allowed_origins' => $origins,
        ]);

        $key->setRelation('tenant', $tenant);

        return $key;
    }

    /** @param  array<string, mixed>  $claims */
    private static function encode(array $claims): string
    {
        $json = json_encode($claims, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $payload): ?array
    {
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            /** @var mixed $claims */
            $claims = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::debug('hosted.embed_token.unreadable', ['exception' => $e->getMessage()]);

            return null;
        }

        return is_array($claims) ? $claims : null;
    }

    /**
     * Signed with the application key, so a token cannot be minted elsewhere.
     *
     * `APP_KEY` rather than a new secret: it is already required to boot, it is
     * already the root of every encrypted column and signed URL in the product,
     * and a second key would be a second thing to rotate and a second thing to
     * forget in a deployment (ENV-4).
     */
    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
