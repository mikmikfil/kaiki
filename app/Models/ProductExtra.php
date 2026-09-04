<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProductExtraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One product's terms for one extra (`docs/data-model.md` §2.3).
 *
 * A `Pivot` with an `id` and a tenant, so `BelongsToTenant` applies to it like
 * any other row — a pivot outside the tenant scope would be the one table where
 * a cross-tenant read is invisible, because nothing about a join table looks
 * like a leak.
 *
 * **Every override is nullable and null means inherit**, including
 * `is_required_override`, which is therefore a tri-state boolean: null
 * inherits, false forces optional, true forces required. A two-state column
 * cannot express "this product says nothing about it", which is what almost
 * every row means.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $extra_id
 * @property int|null $price_cents_override
 * @property int|null $max_qty_override
 * @property bool|null $is_required_override tri-state: null inherits
 * @property int $sort_order
 */
class ProductExtra extends Pivot
{
    use BelongsToTenant;

    /** @use HasFactory<ProductExtraFactory> */
    use HasFactory;

    protected $table = 'product_extra';

    public $incrementing = true;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_cents_override' => 'integer',
            'max_qty_override' => 'integer',
            'is_required_override' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
