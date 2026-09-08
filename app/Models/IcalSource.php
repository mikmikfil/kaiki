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
use Illuminate\Support\Facades\Crypt;

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
            // `url` is deliberately **not** cast to `encrypted` here. It is
            // encrypted and decrypted by its own accessor below, and having
            // both would be the bug that shipped in #29 — see that method.
            'is_active' => 'boolean',
            'sync_interval_minutes' => 'integer',
            'last_synced_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'events_imported' => 'integer',
        ];
    }

    /**
     * The URL, encrypted at rest, with `url_hash` kept in step (§1.7).
     *
     * ## Why the encryption is spelled out here instead of being a cast
     *
     * It was a cast — `'url' => 'encrypted'` — **and** this mutator, and the
     * two silently cancelled out. A custom attribute mutator replaces the
     * cast's setter, so the value written to the row was the plaintext URL; and
     * the cast's *getter* survived, so reading it back tried to decrypt
     * plaintext and threw `DecryptException`.
     *
     * Both halves were invisible until #124, because nothing had ever read this
     * column: the table was pulled forward into M1 with `vessel_blocks`, and
     * the sync that finally fetches the URL is M5. So an operator's private
     * Airbnb feed URL — a credential that exposes their whole calendar — would
     * have been stored in the clear, in a column whose docblock said it was
     * encrypted, with a test suite that never noticed.
     *
     * Doing both directions explicitly is the fix *and* the guard: there is now
     * one place that decides how this value is stored, and no second mechanism
     * that can quietly disagree with it.
     *
     * ## The hash is still the model's job, not the caller's
     *
     * The original reasoning holds and is why this is a mutator at all: the
     * unique index is the only thing standing between an operator and the same
     * feed added twice — which would double every imported event and make the
     * boat look busy for occupations that do not exist — and an index protects
     * nothing if a writer can set the URL without the hash.
     *
     * @return Attribute<string, array{url: string, url_hash: string}>
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: static function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return $value;
                }

                return Crypt::decryptString($value);
            },
            set: function (string $value): array {
                return [
                    'url' => Crypt::encryptString($value),
                    // Hashed from the **plaintext**, so two rows holding the
                    // same URL collide even though their ciphertexts differ —
                    // encryption is randomised, so comparing ciphertext would
                    // never match anything.
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

    /**
     * The threshold at which OPS-15 requires the operator to be told.
     *
     * *"Failures retry with backoff and are surfaced to the operator after
     * three consecutive failures."* Three and ten are two different decisions
     * and both are here so they cannot drift apart: **three** is when a person
     * needs to know, **ten** is when the platform stops asking.
     *
     * The gap between them is the point. Telling somebody on the first failure
     * trains them to ignore the warning — a property-management system has a
     * bad afternoon roughly every month. Waiting until ten means a fortnight of
     * a calendar that silently is not syncing, which is a boat sold twice.
     */
    public const ATTENTION_THRESHOLD = 3;

    /**
     * Should this source be shown to the operator as needing a look (OPS-15)?
     *
     * A source the platform switched off after ten failures always qualifies,
     * even though its `consecutive_failures` stopped climbing — an inactive
     * feed with no warning beside it reads as one the operator disabled
     * themselves.
     */
    public function needsAttention(): bool
    {
        return $this->consecutive_failures >= self::ATTENTION_THRESHOLD
            || ($this->hasExhaustedRetries() && ! $this->is_active);
    }
}
