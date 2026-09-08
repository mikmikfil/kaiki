<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event, aimed at one endpoint (spec OPS-20, `docs/api.md` §8.4).
 *
 * A row is created **before** the first attempt and updated by each one, so a
 * delivery that never gets sent — because the queue is behind, or the worker
 * died — is still visible in the panel as `pending` rather than being invisible
 * until it succeeds.
 *
 * ## The backoff is a table, not a formula
 *
 * §8.4 fixes it: `10 s, 30 s, 2 min, 10 min, 30 min, 2 h, 6 h, 12 h`. Eight
 * attempts over roughly a day. Written out rather than computed because the
 * published contract is the list — an integrator reads those numbers and sizes
 * their own retention against them, and a formula that produced *almost* those
 * numbers would be a contract nobody could rely on.
 *
 * Jitter is added on top so a thousand deliveries created by one nightly job do
 * not stampede the same server at the same second.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $webhook_endpoint_id
 * @property WebhookEvent $event
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int|null $duration_ms
 * @property Carbon|null $delivered_at
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use Prunable;

    /**
     * Seconds before each attempt, from `docs/api.md` §8.4.
     *
     * Eight entries, so eight attempts. The count is derived from this array
     * rather than declared beside it, because two numbers that must agree are
     * two numbers that eventually will not.
     *
     * @var list<int>
     */
    public const BACKOFF_SECONDS = [10, 30, 120, 600, 1800, 7200, 21600, 43200];

    /** How long the receiver has to answer. §8.4: ten seconds, redirects not followed. */
    public const TIMEOUT_SECONDS = 10;

    /** §8.4's truncation, so somebody's 500-page HTML does not become a database row. */
    public const BODY_EXCERPT = 2000;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => WebhookEvent::class,
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public static function maxAttempts(): int
    {
        return count(self::BACKOFF_SECONDS);
    }

    /** Is there another attempt after the one just made? */
    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::maxAttempts();
    }

    /**
     * When the next attempt is due, with jitter.
     *
     * Up to a tenth of the interval, added rather than subtracted — a delivery
     * is never retried *sooner* than the published schedule, which is the half
     * of it a receiver's own rate limiter cares about.
     */
    public function nextAttemptAt(?Carbon $now = null): Carbon
    {
        $index = min($this->attempts, self::maxAttempts() - 1);
        $seconds = self::BACKOFF_SECONDS[$index];

        return ($now ?? Carbon::now())->copy()->addSeconds($seconds + random_int(0, intdiv($seconds, 10)));
    }

    /**
     * Ninety days (`docs/data-model.md` §3.13).
     *
     * A delivery log is a copy of booking data — a guest's name and email in a
     * second table — kept so an operator can answer "what did we send that
     * morning" while somebody still cares. Kept for ever it would be personal
     * data retained for no stated purpose, growing at the rate of the business.
     *
     * Global scopes are deliberately not removed: `model:prune` runs outside
     * tenancy, and `BelongsToTenant` throws rather than scoping to nobody, so
     * `withoutGlobalScopes()` is what makes this a platform-wide sweep rather
     * than an exception at four in the morning.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', Carbon::now()->subDays(90));
    }

    /**
     * Deliveries due to be attempted, across every tenant.
     *
     * Read by the sweeper, which is why `wh_deliveries_retry_idx` has no
     * `tenant_id` in front of it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', DeliveryStatus::Pending)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $now ?? Carbon::now());
    }
}
