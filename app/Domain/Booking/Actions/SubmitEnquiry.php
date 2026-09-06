<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\EnquiryData;
use App\Enums\EnquiryStatus;
use App\Events\EnquiryReceived;
use App\Models\Enquiry;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Recording a question (spec BKG-28 FIXED, BKG-29).
 *
 * ## It touches nothing in the availability engine
 *
 * BKG-28's second clause, and the reason it is worth a class docblock: the
 * natural implementation is one helpful line — *"well, we know the product and
 * the preferred date, so let us check whether it is free"* — and it would put
 * the most spam-exposed endpoint in the system on the hot path of the
 * availability engine. `EnquiryEndpointTest` counts queries against the
 * availability tables and asserts zero, because a comment saying "do not do
 * this" is not a test.
 *
 * The product is resolved by **uuid**, and only to establish that it exists and
 * belongs to this tenant. It is a label on the enquiry, not a subject.
 *
 * ## The honeypot never becomes data
 *
 * §2.5: *"a honeypot field that is never persisted."* {@see EnquiryData} has no
 * such property and `enquiries` has no such column, so there is nothing to
 * forget to strip. The rejection happens in the form request, before this runs.
 */
final class SubmitEnquiry
{
    public function __invoke(EnquiryData $data): Enquiry
    {
        $enquiry = new Enquiry;

        $enquiry->forceFill([
            'uuid' => (string) Str::uuid(),
            'product_id' => $this->productIdFor($data->productUuid),
            'name' => $data->name,
            'email' => $data->email,
            'phone' => $data->phone,
            'preferred_date' => $data->preferredDate,
            'pax' => $data->pax,
            // Verbatim. `docs/api.md` §4: free guest text *"is stored and
            // returned exactly as written and is never translated."*
            'message' => $data->message,
            'locale' => $data->locale,
            'status' => EnquiryStatus::New,
            'source' => $data->source,
            // §2.5: rate limiting and spam review. Not marketing data, and not
            // compiled with anything.
            'ip_address' => $data->ipAddress,
            'user_agent' => $data->userAgent,
        ])->save();

        // BKG-29: the operator is notified **immediately**. An enquiry is a
        // person waiting for an answer, and a digest would turn a five-minute
        // reply into a next-morning one.
        EnquiryReceived::dispatch($enquiry->getKey(), $enquiry->tenant_id);

        return $enquiry;
    }

    /**
     * The product, if one was named and it is this tenant's.
     *
     * A lookup by uuid on the tenant-scoped model, so a uuid from another
     * operator resolves to nothing rather than to a cross-tenant reference —
     * and a general enquiry, which names no product at all, is the ordinary
     * case rather than an error.
     */
    private function productIdFor(?string $uuid): ?int
    {
        if ($uuid === null || trim($uuid) === '') {
            return null;
        }

        $product = Product::query()->where('uuid', $uuid)->first();

        return $product?->getKey();
    }
}
