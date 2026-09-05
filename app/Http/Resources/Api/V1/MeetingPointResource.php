<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Media\Support\ImagePayload;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A `ports` row as a product's meeting point (`docs/api.md`, `MeetingPoint`).
 *
 * ## `lat` and `lng` become numbers here, and only here
 *
 * The column is `decimal:7` and the model casts it to a **string**, because
 * these are the only decimal columns in the schema and a float drifts (§2.3).
 * The contract types them as JSON numbers, so the cast happens at the boundary
 * — one place, on the way out, where nothing arithmetic happens afterwards.
 *
 * ## `maps_url` is resolved, never the raw column
 *
 * {@see Port::mapsUrl()} is the documented resolution of "operator override,
 * else coordinates, else address, else nothing". Reading `$port->maps_url`
 * here would silently return null for every port whose operator never typed
 * one, which is most of them.
 *
 * @mixin Port
 */
final class MeetingPointResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'address' => $this->address,
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'instructions' => $this->instructions,
            'photo_url' => ImagePayload::url($this->photo_path),
            'maps_url' => $this->mapsUrl(),
        ];
    }
}
