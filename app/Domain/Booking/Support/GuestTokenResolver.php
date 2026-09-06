<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Turning a URL segment into a tenant and a row (spec TOK-1 … TOK-4).
 *
 * ## The token *is* the credential, so this is what resolves the tenant
 *
 * None of the four guest URLs carries a tenant — no key, no host, no session.
 * The token is the whole of the authentication and the whole of the
 * authorisation, which is why every column behind it is globally unique with no
 * tenant prefix, and why every lookup here runs `withoutTenancy()`. It is the
 * same textbook case as {@see ApiKey::findByPrefix()} and
 * {@see Payment::findByGatewayRef()}: the lookup is what *resolves*
 * the tenant, so it cannot run inside one.
 *
 * ## Every path does the same work, and that is a security property
 *
 * TOK-4 requires a failure to be a *"generic branded 'link not valid' page …
 * never a distinction between 'not found' and 'expired'."* The issue's own note
 * is the part that is easy to miss:
 *
 * > A found-but-expired token that hits the database and a not-found token that
 * > short-circuits are distinguishable by response time, and that is the same
 * > oracle in a slower form.
 *
 * So there is **no early return on shape**. A forty-character token, a
 * three-character one and an empty string all reach the same query. The
 * length check that would obviously belong at the top of {@see self::booking()}
 * is deliberately absent, and {@see self::isWellFormed()} exists only for tests
 * to assert the *generator*, never to gate a lookup.
 *
 * ## Tokens are minted here too, so there is one definition of "40 random"
 *
 * TOK-2: *"40 characters of cryptographically secure URL-safe random text …
 * never derived from any other identifier."* `Str::random()` is
 * `random_bytes()` underneath, and the "never derived" half is a property of
 * having no inputs at all — {@see self::mint()} takes none, so there is nothing
 * a booking id or a timestamp could leak through.
 */
final class GuestTokenResolver
{
    /** TOK-2's length, in one place. */
    public const LENGTH = 40;

    /**
     * A booking from `/b/{manage_token}`.
     *
     * No shape check, deliberately — see the class docblock.
     */
    public static function booking(string $token): ?Booking
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Booking => Booking::query()->where('manage_token', $token)->first(),
        );
    }

    /** A booking from `/g/{guest_details_token}`. */
    public static function guestDetails(string $token): ?Booking
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Booking => Booking::query()->where('guest_details_token', $token)->first(),
        );
    }

    /** A quote from `/q/{quote_token}`. */
    public static function quote(string $token): ?Quote
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Quote => Quote::query()->where('quote_token', $token)->first(),
        );
    }

    /**
     * A voucher from `/v/{code}`.
     *
     * TOK-2 singles this one out: *"`/v/{voucher}` uses the voucher code
     * itself, which is therefore also generated with sufficient entropy and is
     * rate-limited harder."* A voucher code is short enough to be read aloud
     * over a phone, which is exactly why the page behind it gets the tighter
     * limit rather than a longer code.
     */
    public static function voucher(string $code): ?Voucher
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Voucher => Voucher::query()->where('code', $code)->first(),
        );
    }

    /** The tenant a resolved row belongs to, with no tenant in context. */
    public static function tenantOf(int $tenantId): ?Tenant
    {
        return Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($tenantId),
        );
    }

    /**
     * A fresh token (TOK-2).
     *
     * **No parameters, and that is the requirement.** "Never derived from any
     * other identifier" is easy to satisfy today and easy to break in six
     * months by threading a booking through "for uniqueness"; a signature with
     * nothing in it cannot be.
     */
    public static function mint(): string
    {
        return Str::random(self::LENGTH);
    }

    /**
     * Does this *look* like a token TOK-2 would have produced?
     *
     * For tests to assert the generator with. **Never** call it before a
     * lookup: a shape check that short-circuits is a timing oracle, which is
     * the whole reason the resolvers above have none.
     */
    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{' . self::LENGTH . '}$/', $token) === 1;
    }
}
