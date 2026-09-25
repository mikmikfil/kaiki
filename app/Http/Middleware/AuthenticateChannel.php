<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Channels\Support\ChannelResolver;
use App\Enums\ChannelKey;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate an OTA calling *us*, and work out whose seats it means (ADR-0034).
 *
 * ## The username is the tenant resolution, and that is unusual here
 *
 * Every other way into this application knows whose it is before it
 * authenticates: `/api/v1` has a key whose prefix names the operator, the
 * hosted pages have a domain, the panels have a session. A GetYourGuide request
 * has **none of that** — no supplier id in the path, no signature, no `Origin`,
 * nothing but `Authorization: Basic`. So the username does double duty, and
 * {@see IntegrationCredential::findByInboundUsername()} runs outside tenancy
 * because it is the thing that establishes it.
 *
 * That is why the username is 32 random characters rather than anything
 * derived: it is a lookup key exposed to a third party, and one built from a
 * slug would let somebody enumerate which operators sell through GetYourGuide.
 *
 * ## What is told apart, and what deliberately is not
 *
 * An unknown username and a wrong password give the **same** 401. Telling them
 * apart tells whoever is guessing which half they got right, and the username
 * is the half that is easier to guess.
 *
 * A credential that exists, authenticates, and is switched off is different,
 * and answers **403 with a reason**. That is not a security leak — whoever sent
 * it already proved they hold the password — and collapsing it into the 401
 * would answer an operator's support ticket with a shrug instead of with "you
 * turned it off". The same distinction for the two switches above it: the
 * platform's `channel_manager` flag and the merchant's own column.
 *
 * ## It never logs the credential
 *
 * Not the password, and not the username either. The username is enough to
 * mount an offline guess against, and `NoCredentialLeakTest` scans for the
 * shapes that would put either into a log line.
 */
final class AuthenticateChannel
{
    /** Where the authenticated credential is left for the controllers. */
    public const CREDENTIAL_ATTRIBUTE = 'channel_credential';

    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) $request->getUser();
        $password = (string) $request->getPassword();

        if ($username === '' || $password === '') {
            return $this->unauthenticated();
        }

        $credential = IntegrationCredential::findByInboundUsername(
            IntegrationProvider::GetYourGuide,
            $username,
        );

        // One answer for both halves. `matchesInboundSecret()` is constant-time
        // and answers false for a credential that has never been generated, so
        // the three cases converge here rather than in three branches somebody
        // could later reorder into a disclosure.
        if ($credential === null || ! $credential->matchesInboundSecret($password)) {
            return $this->unauthenticated();
        }

        $tenant = $credential->tenant()->withoutGlobalScopes()->first();

        if ($tenant === null) {
            // A credential whose operator has been deleted. Not a password
            // problem, but there is nothing to serve and nobody to explain it
            // to, so it takes the same answer as a bad password.
            return $this->unauthenticated();
        }

        if (! $credential->is_active) {
            return $this->refused('connection_inactive', 'This connection has been switched off in Kaiki.');
        }

        // The two locks from ADR-0034, asked through the one door that asks
        // them — never re-implemented here, or this becomes the fourth call
        // site that forgets one of them.
        if (! app(ChannelResolver::class)->isPermittedFor($tenant, ChannelKey::GetYourGuide)) {
            return $this->refused('channel_not_enabled', 'This channel is not enabled for this supplier.');
        }

        tenancy()->initialize($tenant);

        $request->attributes->set(self::CREDENTIAL_ATTRIBUTE, $credential);

        return $next($request);
    }

    /**
     * 401 with the challenge, because the client is an HTTP Basic client.
     *
     * `WWW-Authenticate` is not decoration: without it a 401 is malformed for
     * Basic, and a strict client can treat the response as a transport fault
     * rather than as "your credentials are wrong" — which is the difference
     * between GetYourGuide retrying for an hour and GetYourGuide telling the
     * supplier their password is bad.
     */
    private function unauthenticated(): Response
    {
        return response()->json(
            ['errorCode' => 'UNAUTHORIZED', 'errorMessage' => 'Authentication failed.'],
            401,
            ['WWW-Authenticate' => 'Basic realm="Kaiki channel"'],
        );
    }

    private function refused(string $code, string $message): Response
    {
        return response()->json(
            ['errorCode' => $code, 'errorMessage' => $message],
            403,
        );
    }
}
