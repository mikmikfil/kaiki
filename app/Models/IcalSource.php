<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IcalSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An external calendar polled for blocks (`docs/data-model.md` §2.7, OPS-15).
 *
 * Pulled forward from M5 with its table; the polling is M5.
 *
 * ## The URL is encrypted, so uniqueness needs a hash beside it
 *
 * §1.7's canonical case. An Airbnb or Google private feed URL *is* a
 * credential — a leaked one exposes an operator's whole calendar — so the
 * column is encrypted, and an encrypted column cannot be indexed or compared.
 * `url_hash` is what stops the same feed being added twice, which would
 * otherwise double every imported event and make the boat look busy for
 * occupations that do not exist.
 *
 * The hash is maintained here rather than by the caller, because a caller that
 * forgets it writes a row the unique index cannot protect.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $vessel_id
 * @property string $name
 * @property string $url encrypted
 * @property string $url_hash
 * @property bool $is_active
 * @property int $sync_interval_minutes
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property string|null $etag
 * @property int $events_imported
 */
class IcalSource extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IcalSourceFactory> */
    use HasFactory;

    /** Ten failures and the feed switches itself off, with a notification. */
    public const FAILURE_LIMIT = 10;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'is_active' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'events_imported' => 'integer',
        ];
    }

    /**
     * Keep `url_hash` in step with `url`, always.
     *
     * A mutator rather than a caller's responsibility: the unique index is the
     * only thing standing between an operator and the same feed twice, and it
     * protects nothing if a writer can set the URL without the hash.
     *
     * @return Attribute<string, array{url: string, url_hash: string}>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            set: function (string $value): array {
                return [
                    'url' => $value,
                    'url_hash' => self::hashUrl($value),
                ];
            },
        )->withoutObjectCaching();
    }

    /** SHA-256 of the plaintext URL (§1.7). */
    public static function hashUrl(string $url): string
    {
        return hash('sha256', trim($url));
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * Feeds the cross-tenant poller should visit.
     *
     * @param  Builder<IcalSource>  $query
     * @return Builder<IcalSource>
     */
    public function scopeDueForSync(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $inner) use ($now): void {
                $inner->whereNull('last_synced_at')
                    ->orWhereRaw('last_synced_at <= ?', [$now->copy()->subMinutes(15)->toDateTimeString()]);
            });
    }

    /** Has this feed failed often enough to switch itself off? */
    public function hasExhaustedRetries(): bool
    {
        return $this->consecutive_failures >= self::FAILURE_LIMIT;
    }
}
