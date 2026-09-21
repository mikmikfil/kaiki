<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelKey;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trip, as one channel knows it (spec EXT-1, ADR-0034).
 *
 * The answer to the only question an incoming OTA request asks that Kaiki
 * cannot answer from its own data: *their* `productId` means *which* trip of
 * ours. Everything else in the request — dates, party, seats — is ours already.
 *
 * ## A missing row is not an error, and the distinction matters
 *
 * An unmapped product id means *this trip is not sold here*, which is a
 * perfectly ordinary state: an operator maps the three trips they list on
 * GetYourGuide and leaves the other eighteen alone. Callers must not read it as
 * "not available", because the two send an OTA down very different paths — one
 * is a product it should stop asking about, the other is a date to try again.
 *
 * @property int $id
 * @property int $tenant_id
 * @property ChannelKey $channel
 * @property int $product_id
 * @property string $external_product_id
 */
class ChannelProductMap extends Model
{
    use BelongsToTenant;

    protected $table = 'channel_product_map';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => ChannelKey::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
