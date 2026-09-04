<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\IcalFeedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The token-authenticated iCal export URL (`docs/data-model.md` §2.7, OPS-13).
 *
 * Pulled forward from M5 with its table; the export itself is M5. What ships
 * here is the row, so `vessel_blocks` can reference a feed and so the token's
 * rules are settled before anything generates one.
 *
 * **The URL is the credential.** Google Calendar will not send a header, so
 * there is nothing else to authenticate with — which is why the token is
 * globally unique and why `include_guest_names` is off by default.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $vessel_id
 * @property string $token
 * @property bool $include_departures
 * @property bool $include_blocks
 * @property bool $include_guest_names
 * @property bool $is_active
 * @property Carbon|null $last_accessed_at
 * @property int $access_count
 */
class IcalFeed extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IcalFeedFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'include_departures' => 'boolean',
            'include_blocks' => 'boolean',
            'include_guest_names' => 'boolean',
            'is_active' => 'boolean',
            'last_accessed_at' => 'datetime',
            'access_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * A fresh token.
     *
     * 40 hex characters from `random_bytes`, not `Str::random`: this is a
     * credential, and the difference between a CSPRNG and a convenience helper
     * is the whole security of an unauthenticated URL.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** Rotating replaces the token in place, so the old URL stops working. */
    public function rotateToken(): string
    {
        $this->token = self::generateToken();
        $this->save();

        return $this->token;
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return ['token' => Str::limit($this->token, 8, '')];
    }
}
