<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Data;

use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use Spatie\LaravelData\Data;

/**
 * One operator's credential set, on its way into the database (spec PAY-4).
 *
 * ## Why the secret half is a separate property from the public half
 *
 * Because they have different rules and merging them is how one ends up in the
 * wrong column. `credentials` is encrypted, never rendered back, never logged;
 * `publicConfig` is a plain JSON column an operator reads on screen. A single
 * "settings" array would leave the split to whoever writes the next form, and
 * a sender name landing in the encrypted column is invisible until somebody
 * cannot work out why they can't see it.
 *
 * ## It carries no tenant
 *
 * `BelongsToTenant` supplies that on write, and a `tenantId` here would be a
 * second answer to a question that already has one — the shape ADR-0001's
 * isolation gate exists to make impossible.
 */
final class IntegrationCredentialData extends Data
{
    /**
     * @param  array<string, string>  $credentials  the encrypted half
     * @param  array<string, mixed>  $publicConfig  the readable half
     */
    public function __construct(
        public readonly IntegrationProvider $provider,
        public readonly CredentialEnvironment $environment,
        public readonly array $credentials,
        public readonly array $publicConfig = [],
        public readonly ?string $webhookSecret = null,
        public readonly bool $isDefault = false,
        public readonly bool $isActive = true,
    ) {}

    /**
     * The credential fields this provider requires that are missing or blank.
     *
     * Returned rather than thrown, so the form can mark every empty field at
     * once instead of the operator discovering them one save at a time.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];

        foreach ($this->provider->credentialFields() as $field) {
            $value = $this->credentials[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $field;
            }
        }

        if ($this->provider->issuesWebhookSecret() && trim((string) $this->webhookSecret) === '') {
            $missing[] = 'webhook_secret';
        }

        return $missing;
    }

    /**
     * The provider's own account identifier, lifted out of `publicConfig`.
     *
     * It is stored twice on purpose: in `public_config` where the operator
     * edits it, and in the plain `external_account_id` column where a webhook
     * with no tenant context can be looked up by it. ENV-8 forbids filtering on
     * a JSON path, so the column is not a denormalisation for speed — it is the
     * only portable way to run that query at all.
     */
    public function externalAccountId(): ?string
    {
        $field = $this->provider->externalAccountField();

        if ($field === null) {
            return null;
        }

        $value = $this->publicConfig[$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Drop anything the provider did not ask for.
     *
     * A form that posts a stale field after the operator switches provider
     * would otherwise store a Stripe key inside a Viva row — encrypted,
     * invisible, and live until somebody's checkout fails.
     *
     * @return array<string, string>
     */
    public function knownCredentials(): array
    {
        $known = [];

        foreach ($this->provider->credentialFields() as $field) {
            $value = $this->credentials[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $known[$field] = trim($value);
            }
        }

        return $known;
    }

    /**
     * The same filter for the readable half.
     *
     * @return array<string, mixed>
     */
    public function knownPublicConfig(): array
    {
        $known = [];

        foreach ($this->provider->publicFields() as $field) {
            if (array_key_exists($field, $this->publicConfig)) {
                $value = $this->publicConfig[$field];

                $known[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        return $known;
    }
}
