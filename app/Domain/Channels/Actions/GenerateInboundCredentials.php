<?php

declare(strict_types=1);

namespace App\Domain\Channels\Actions;

use App\Domain\Channels\Data\InboundCredentialsData;
use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mint the Basic-auth pair an OTA uses to call us (ADR-0034).
 *
 * ## Generating and rotating are the same act
 *
 * There is no separate rotate. Pressing the button when a pair exists replaces
 * it, which is what rotation *is* here — unlike an API key, where ADR-0013
 * rotates by create-then-revoke so the old one keeps working while it is swapped
 * out. That does not apply: GetYourGuide's dashboard holds exactly one
 * credential per supplier, so there is no window in which two are valid and no
 * value in pretending otherwise.
 *
 * The cost is stated plainly on the screen: **from the moment this is pressed
 * until the new pair is pasted into GetYourGuide, their calls fail.** That is a
 * real gap and the operator has to choose it knowingly, which is why the action
 * does not happen as a side effect of saving the form.
 *
 * ## The username is random, not derived
 *
 * A username built from the tenant's slug or id would let anybody holding one
 * guess the shape of every other, and — worse — let somebody enumerate which
 * operators sell through GetYourGuide by trying names. It carries a readable
 * prefix so that a line in somebody's log is identifiable as ours, and 32
 * random characters after it.
 */
final class GenerateInboundCredentials
{
    /** Readable in a log without saying whose it is. */
    public const USERNAME_PREFIX = 'gyg_';

    /** Collisions are checked against a globally unique index; this bounds the retry. */
    private const MAX_ATTEMPTS = 5;

    public function __invoke(IntegrationCredential $credential): InboundCredentialsData
    {
        $username = $this->unusedUsername();

        // 48 characters from Laravel's CSPRNG. Long because it is typed by
        // nobody — it is copied into a field on somebody else's dashboard — so
        // there is no usability argument for making it shorter.
        $password = Str::random(48);

        $pair = new InboundCredentialsData($username, $password);

        DB::transaction(function () use ($credential, $pair): void {
            $credential->forceFill([
                'inbound_username' => $pair->username,
                'inbound_secret_hash' => hash('sha256', $pair->password),
                'inbound_last_four' => $pair->lastFour(),
                'inbound_rotated_at' => now(),
            ])->save();
        });

        return $pair;
    }

    /**
     * A username nothing else holds.
     *
     * Checked against the whole table rather than the tenant's own rows,
     * because the index is global — it has to be, since the lookup runs before
     * any tenant is known.
     */
    private function unusedUsername(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::USERNAME_PREFIX . Str::random(32);

            $taken = IntegrationCredential::query()
                ->withoutGlobalScopes()
                ->where('inbound_username', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        // Five collisions on 32 random characters is not bad luck; it is a
        // broken random source, and minting a credential from one would be
        // worse than refusing to mint one at all.
        throw new RuntimeException('Could not generate an unused inbound username after ' . self::MAX_ATTEMPTS . ' attempts.');
    }
}
