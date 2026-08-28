<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A public API credential (data-model §2.1, spec TEN-3).
 *
 * The raw key exists exactly once, in the response to the create action. After
 * that only `prefix`, `secret_hash` and `last_four` survive, so a database dump
 * cannot be replayed against the API.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ApiKeyType $type
 * @property ApiKeyEnvironment $environment
 * @property string $prefix
 * @property string $secret_hash
 * @property string $last_four
 * @property array<int, string> $scopes
 * @property array<int, string> $allowed_origins
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class ApiKey extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $guarded = [];

    /** Never let the stored hash reach a payload, a log line or a Sentry event. */
    protected $hidden = ['secret_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ApiKeyType::class,
            'environment' => ApiKeyEnvironment::class,
            'scopes' => 'array',
            'allowed_origins' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Find a key by the plaintext handle at the front of a presented key.
     *
     * Runs outside the tenant scope on purpose: this lookup is what *resolves*
     * the tenant, so it necessarily happens before one exists. It is the
     * textbook case the `withoutTenancy()` escape hatch was built for, and it
     * is safe because `prefix` is globally unique.
     */
    public static function findByPrefix(string $prefix): ?self
    {
        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()->where('prefix', $prefix)->first(),
        );
    }

    /** Constant-time comparison — a timing side channel would leak the hash byte by byte. */
    public function matches(string $rawKey): bool
    {
        return hash_equals($this->secret_hash, hash('sha256', $rawKey));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** @return list<ApiScope> */
    public function scopes(): array
    {
        return array_values(array_filter(
            array_map(static fn (string $scope): ?ApiScope => ApiScope::tryFrom($scope), $this->scopes),
        ));
    }

    /**
     * Does this key carry the scope — and is its type allowed to?
     *
     * Both checks matter. The type is the ceiling (SEC-5): a publishable key
     * that somehow acquired `quotes.write` in the database still cannot use it,
     * so a bad write or a bad migration cannot quietly escalate a key.
     */
    public function can(ApiScope $scope): bool
    {
        return $this->type->permits($scope) && in_array($scope, $this->scopes(), strict: true);
    }

    /** Empty allow-list means any origin, which the panel warns about (#10). */
    public function allowsOrigin(?string $origin): bool
    {
        if ($this->allowed_origins === []) {
            return true;
        }

        if ($origin === null) {
            return false;
        }

        return in_array(rtrim(strtolower($origin), '/'), array_map(
            static fn (string $allowed): string => rtrim(strtolower($allowed), '/'),
            $this->allowed_origins,
        ), strict: true);
    }

    /**
     * Record use, at most once per key per throttle window.
     *
     * Authentication runs on every public API request. Writing `last_used_at`
     * each time would turn the read-heavy availability endpoint into a
     * write-heavy one and put its <150 ms p95 budget at risk. `Cache::add` is
     * atomic and driver-agnostic — database driver locally, Redis in
     * production — which is why this is not a direct `Redis::` call (ENV-7).
     */
    public function touchLastUsed(): void
    {
        $seconds = (int) config('kaiki.api_keys.last_used_throttle_seconds');

        if (! Cache::add("apikey:{$this->getKey()}:touched", true, $seconds)) {
            return;
        }

        Tenancy::withoutTenancy(function (): void {
            static::query()->whereKey($this->getKey())->update(['last_used_at' => now()]);
        });
    }
}
