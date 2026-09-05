<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Catalog\Data\OfferedExtra;
use App\Domain\Media\Support\ImagePayload;
use App\Models\Extra;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An add-on, as one product actually offers it (`docs/api.md`, `Extra`; spec
 * CAT-12, PRC-10).
 *
 * **Wraps an {@see OfferedExtra}, never an {@see Extra}.** The difference is
 * the whole point: a product may override an extra's price, its maximum
 * quantity, whether it is required and where it sorts, and the resolution of
 * those four is a `??` chain that `OfferedExtra` exists to own. A resource
 * reading the model directly would publish the tenant-wide price on a product
 * that charges something else — and would get `is_required` wrong in the one
 * direction that matters, because the override is tri-state and a `false` must
 * beat the extra's `true`.
 *
 * The `Extra` model is still loaded, for the two fields the value object does
 * not carry: `description` and the image. Both are properties of the item
 * itself and are not overridable per product.
 */
final class ExtraResource extends JsonResource
{
    public function __construct(
        private readonly OfferedExtra $offered,
        private readonly ?Extra $extra = null,
    ) {
        parent::__construct($offered);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->offered->uuid,
            'name' => $this->offered->name,
            'description' => $this->extra?->description,
            'pricing_type' => $this->offered->pricingType->value,
            // Null for `on_request`, and the value object already guarantees
            // that — an override cannot promote an unpriced extra into a total.
            'price_cents' => $this->offered->priceCents,
            'currency' => MoneyFormatter::currency(),
            'max_qty' => $this->offered->maxQty,
            'is_required' => $this->offered->isRequired,
            'image_url' => ImagePayload::url($this->extra?->image_path),
            'sort_order' => $this->offered->sortOrder,
        ];
    }
}
