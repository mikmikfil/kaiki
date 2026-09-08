<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Webhooks\EventRegistry;
use App\Enums\WebhookEvent;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One URL an operator wants told about things (spec OPS-19, OPS-20).
 *
 * ## Twenty consecutive failures switches it off
 *
 * Not twenty failures — twenty **in a row**, counted here and reset by the
 * first success. An endpoint that fails nineteen times across a bad Tuesday and
 * then works has a clean slate, because the rule is about a URL whose owner has
 * moved on, not about a rough afternoon. `docs/api.md` §8.4: *"nobody's queue
 * should burn for a week on a dead URL."*
 *
 * Being switched off is recorded (`disabled_at`) rather than inferred from the
 * counter, so the panel can say *when* it happened and an operator can tell "I
 * turned this off" from "Kaiki turned this off".
 *
 * ## `signing_secret` uses the cast, unlike `IcalSource::url`
 *
 * A plain `'encrypted'` cast is correct here and was wrong there. `ical_sources`
 * needs a deterministic hash beside the ciphertext for its unique index, which
 * forced a custom accessor — and a custom accessor *plus* a cast is exactly the
 * bug that left iCal URLs in plaintext for two milestones. Nothing needs to look
 * an endpoint up by its secret, so there is no hash, no accessor, and no way for
 * the two mechanisms to cancel each other out.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string $url
 * @property string $signing_secret encrypted
 * @property array<int, string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property Carbon|null $last_delivery_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Twenty in a row, then off.
     *
     * `docs/api.md` §8.4. High enough that a provider's bad morning does not
     * silence an operator's integration — eight attempts spread over a day means
     * twenty consecutive failures is the better part of a week of real outage —
     * and low enough that a decommissioned URL stops costing anybody anything.
     */
    public const FAILURE_LIMIT = 20;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivery_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $endpoint): void {
            $endpoint->uuid ??= (string) Str::uuid();
            $endpoint->signing_secret ??= self::freshSecret();
        });
    }

    /**
     * A new signing secret.
     *
     * 32 random bytes as hex. Long enough that guessing is not a strategy, and
     * hex rather than base64 so an operator can paste it into a config file, an
     * environment variable or a Zapier field without meeting `+` or `/`.
     */
    public static function freshSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * The subscription list, cleaned on the way out as well as in.
     *
     * See {@see EventRegistry}: this column is written by a form, and a stored
     * name for an event that has since been withdrawn should stop matching
     * rather than throw on a queue worker.
     *
     * @return Attribute<array<int, string>, array<int, string>>
     */
    protected function events(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): array => EventRegistry::normalise(
                is_string($value) ? (array) json_decode($value, true) : [],
            ),
            set: static fn (mixed $value): string => (string) json_encode(
                EventRegistry::normalise(is_array($value) ? $value : []),
            ),
        );
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** Is this endpoint one we would send to right now? */
    public function isDeliverable(): bool
    {
        return $this->is_active && $this->disabled_at === null;
    }

    public function wants(WebhookEvent $event): bool
    {
        return EventRegistry::wants($this->events, $event);
    }

    /**
     * Endpoints that should hear about an event, for one tenant.
     *
     * The event filter is applied in PHP rather than in SQL. `events` is a JSON
     * column and the two engines disagree about how to search one — MySQL has
     * `JSON_CONTAINS`, SQLite needs `json_each` — and an operator has a handful
     * of endpoints, not thousands. Correct on both engines beats clever on one.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('disabled_at');
    }

    /** A success: the counter goes back to zero. */
    public function recordSuccess(): void
    {
        $this->forceFill([
            'consecutive_failures' => 0,
            'last_delivery_at' => Carbon::now(),
        ])->save();
    }

    /**
     * A failure, and possibly the last straw.
     *
     * Returns true when this failure switched the endpoint off, so the caller
     * can tell the operator — a webhook that stopped working silently is a
     * webhook nobody notices until the accounts do not balance.
     */
    public function recordFailure(): bool
    {
        $this->forceFill([
            'consecutive_failures' => $this->consecutive_failures + 1,
            'last_delivery_at' => Carbon::now(),
        ]);

        $disabled = $this->consecutive_failures >= self::FAILURE_LIMIT && $this->disabled_at === null;

        if ($disabled) {
            $this->forceFill(['is_active' => false, 'disabled_at' => Carbon::now()]);
        }

        $this->save();

        return $disabled;
    }
}
