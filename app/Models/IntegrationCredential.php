<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Integrations\Support\CredentialRepository;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\IntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One operator's credentials for one provider in one environment
 * (`docs/data-model.md` §2.7, spec PAY-3, PAY-4, ADR-0004 Option A).
 *
 * ## The two columns that must never be readable anywhere but here
 *
 * `credentials` and `webhook_secret` are `encrypted` casts, which is PAY-3's
 * mandate — but a cast only protects the database. Three more things protect
 * everywhere else, because the cast is invisible once the model is in memory:
 *
 * 1. `$hidden` keeps both out of `toArray()` and `toJson()`, which is what a
 *    log context, a queue payload and a Sentry breadcrumb are built from.
 * 2. {@see self::__debugInfo()} redacts them for `dd()` and `var_dump()`,
 *    which ignore `$hidden` entirely and are what somebody reaches for at
 *    exactly the wrong moment.
 * 3. `NoCredentialLeakTest` scans the source for the shapes that would put one
 *    into a log line or a form, because 1 and 2 are conventions and a scanner
 *    is not.
 *
 * SEC-9 and MYD-15 both say "never logged", and a requirement written three
 * times in the spec is one nobody has actually made impossible yet.
 *
 * ## Reading these is a repository's job, not a caller's
 *
 * Decryption is not free and the checkout path resolves credentials more than
 * once per request. Go through {@see CredentialRepository}
 * — §2.7 requires it, and it is also the only reader that busts its own cache
 * on save.
 *
 * @property int $id
 * @property int $tenant_id
 * @property IntegrationProvider $provider
 * @property CredentialEnvironment $environment
 * @property array<string, string> $credentials encrypted
 * @property array<string, mixed> $public_config
 * @property string|null $external_account_id
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property string|null $webhook_secret encrypted
 */
class IntegrationCredential extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IntegrationCredentialFactory> */
    use HasFactory;

    /** `last_error` is a column, so a gateway cannot decide how wide it is. */
    public const MAX_ERROR_LENGTH = 500;

    /**
     * What a redacted secret reads as.
     *
     * A constant rather than a literal at each site, so that
     * `NoCredentialLeakTest` can tell "this was deliberately redacted" from
     * "this happens to be a string".
     */
    public const REDACTED = '[redacted]';

    protected $guarded = [];

    /**
     * Never let a secret reach a payload, a log line or a Sentry event.
     *
     * `public_config` is deliberately *not* here: it is the half an operator
     * must be able to read back, and hiding it would make a support ticket
     * about a wrong sender name unanswerable.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials', 'webhook_secret'];

    /**
     * No database default for `public_config`: MySQL 8 refuses a literal
     * default on a JSON column, so the default lives here and the column is
     * plain NOT NULL.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'public_config' => '{}',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'environment' => CredentialEnvironment::class,
            'credentials' => 'encrypted:array',
            'public_config' => 'array',
            'webhook_secret' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * What `dd()` and `var_dump()` show.
     *
     * `$hidden` does nothing for either — they read the raw attribute bag — and
     * both are what somebody uses while debugging a failing checkout, which is
     * the moment credentials are most likely to end up pasted into an issue.
     * The values are replaced rather than the keys removed, so it stays obvious
     * that the row has credentials and merely will not show them.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $attributes = $this->attributesToArray();

        foreach ($this->hidden as $secret) {
            if (array_key_exists($secret, $this->attributes)) {
                $attributes[$secret] = self::REDACTED;
            }
        }

        return $attributes;
    }

    /** Is every field this provider needs present and non-empty? */
    public function isComplete(): bool
    {
        foreach ($this->provider->credentialFields() as $field) {
            $value = $this->credentials[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return ! ($this->provider->issuesWebhookSecret() && ($this->webhook_secret ?? '') === '');
    }

    /**
     * Usable for a real call: active, complete, and verified at least once.
     *
     * Verification is part of it because PAY-11 refuses to let sandbox mode be
     * enabled accidentally, and "credentials that were typed but never worked"
     * is the state that turns a live checkout into a 500 in front of a guest.
     */
    public function isUsable(): bool
    {
        return $this->is_active && $this->verified_at !== null && $this->isComplete();
    }

    /**
     * The last four characters of a named credential field, for the panel.
     *
     * The only place a secret is allowed to inform what an operator sees, and
     * it is four characters so they can tell *which* key they pasted without
     * the screen carrying anything usable. Same reasoning as
     * `api_keys.last_four`, which is a stored column for the same purpose.
     */
    public function hint(string $field): string
    {
        $value = $this->credentials[$field] ?? null;

        if (! is_string($value) || $value === '') {
            return '';
        }

        return str_repeat('•', 4) . Str::substr($value, -4);
    }

    /**
     * Resolve a tenant's credentials for a provider without a tenant in context.
     *
     * The `gateway_webhook_events` case (§2.7): a webhook arrives, carries the
     * provider's own account identifier and nothing else we own, and the tenant
     * has to come from somewhere before any money logic runs.
     *
     * Runs outside the tenant scope on purpose — this lookup is what *resolves*
     * the tenant, exactly as {@see ApiKey::findByPrefix()} does — and it is
     * safe because it matches on a plain indexed column that is not a secret.
     * Inactive rows are still matched: a webhook for a deactivated integration
     * is a thing that must be recorded and investigated, not silently dropped.
     */
    public static function findByExternalAccount(IntegrationProvider $provider, string $externalAccountId): ?self
    {
        if ($externalAccountId === '') {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()
                ->where('provider', $provider->value)
                ->where('external_account_id', $externalAccountId)
                ->first(),
        );
    }
}
