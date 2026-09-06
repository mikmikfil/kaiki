<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Enquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `POST /api/v1/enquiries`'s response (`docs/api.md`, `Enquiry`).
 *
 * ## Deliberately thin, and the contract says why
 *
 * *"An enquiry is not a booking, has no guest token, and is answered by
 * email."* There is nothing here a client could poll, because there is nothing
 * to poll: the operator replies to a person, not to a record.
 *
 * The guest's own message is **not** echoed back. It is in their form and in
 * their sent mail; returning it would put a stranger's words into a response
 * that a widget on a shared page might log, for no purpose the widget has.
 *
 * @mixin Enquiry
 */
final class EnquiryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // CNV-8: the uuid, never the database id.
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'product_uuid' => $this->product?->uuid,
            'locale' => $this->locale,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
