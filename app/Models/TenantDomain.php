<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An operator's custom hostname (ADR-0010 Option A).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $hostname
 * @property DomainStatus $status
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 */
class TenantDomain extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Normalise on the way in, so the uniqueness constraint means what it
        // says. Comparing at read time instead would let `Example.COM` and
        // `example.com` both exist and resolve to different tenants.
        static::saving(static function (self $domain): void {
            $domain->hostname = self::normalise($domain->hostname);
        });
    }

    /**
     * Lowercase, trim, drop any port, and punycode-encode a Unicode hostname.
     *
     * Greek operators register Greek domains, so `κρουαζιέρες.gr` is a real
     * input here, and it must match the `xn--` form a browser actually sends.
     */
    public static function normalise(string $hostname): string
    {
        // mb_strtolower, not strtolower: the latter is byte-based and leaves
        // `ΑΙΓΑΙΟ.GR` untouched. IDN processing would fold the case anyway, but
        // relying on that would mean the lowercase step silently does nothing
        // for exactly the hostnames this product's customers register.
        $hostname = mb_strtolower(trim($hostname));
        $hostname = preg_replace('/:\d+$/', '', $hostname) ?? $hostname;
        $hostname = rtrim($hostname, '.');

        if ($hostname === '' || mb_check_encoding($hostname, 'ASCII')) {
            return $hostname;
        }

        $encoded = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $encoded === false ? $hostname : $encoded;
    }
}
