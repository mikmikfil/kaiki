<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Media\Support\ImagePayload;
use App\Models\Vessel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The boat as a guest sees it (`docs/api.md`, `VesselSummary`).
 *
 * ## What is deliberately absent
 *
 * `registration_number`, `captain_name`, the home port and `status` are all on
 * the model and none of them are here. The contract's own line is the rule:
 * *"Never exposes the internal id or the home-port address."* A registration
 * number is the boat's identity document and a captain's name is a person —
 * neither belongs in a payload a third-party page can read with a publishable
 * key (SEC-2, CNV-8).
 *
 * ## `length_m` is derived from `length_cm`
 *
 * The column is centimetres — an integer, because a length stored as a float
 * is a length that renders as `18.499999`. The contract shows metres, so the
 * division happens at the boundary. This is presentation, not money: CNV-1's
 * no-floats rule is about cents, and there is no cent here.
 *
 * @mixin Vessel
 */
final class VesselSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'capacity_max' => $this->capacity_max,
            'length_m' => $this->length_cm !== null ? round($this->length_cm / 100, 2) : null,
            'crew_count' => $this->crew_count,
            'description' => $this->description,
            'images' => ImagePayload::collection($this->images, app()->getLocale()),
            'specs' => (object) $this->specs,
        ];
    }
}
