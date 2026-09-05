<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\Availability\AvailabilityRequestData;
use App\Domain\Catalog\Support\AgeBandResolver;
use App\Http\Responses\ApiErrorResponse;
use App\Models\AgeBand;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * The query string of `GET /api/v1/availability` (spec AVL-29;
 * `docs/api.md` §5).
 *
 * ## The 62-day cap is enforced once, in the domain, and translated here
 *
 * {@see AvailabilityRequestData::forRange()} already refuses an inverted or
 * over-long range, and it is right that it does: it is the last line of defence
 * for every caller, including the panel and M3's hosted calendar. Repeating the
 * arithmetic here would be a second implementation of the same rule, and the
 * two would eventually disagree about whether the range is inclusive.
 *
 * So this class calls the domain and **translates its refusal into the
 * contract's vocabulary** — `422 invalid_date_range` with `from`, `to` and
 * `max_days`, which is what the error table names for exactly this. AVL-29 is
 * explicit that the answer is a refusal rather than a truncated result: a
 * silently trimmed range renders as empty days, and the guest concludes the
 * boat does not sail in September.
 *
 * ## `pax` is one integer, and the engine wants a party
 *
 * The contract's parameter is a single number — a calendar asks "can you seat
 * four", not "can you seat two adults, a child and an infant". The engine works
 * in age bands because that is what capacity and price actually depend on, so
 * the number is assigned to the product's **base** band: the one every other
 * band's price is a multiple of, and by definition one that occupies a seat.
 *
 * That makes the answer a *seat* question rather than a legal-capacity one,
 * which is the right reading of a calendar filter. A party with infants is
 * priced and capacity-checked properly at `POST /price-quote` and again, under
 * lock, at checkout.
 */
final class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the three that have no sensible default.
     *
     * A missing `product`, `from` or `to` is not a filter that matches nothing
     * — there is no question to answer at all — so unlike
     * {@see ProductIndexRequest} this endpoint does validate, and a failure is
     * the `422 validation_failed` the contract lists for this operation.
     *
     * The date range itself is deliberately **not** here: `after_or_equal` and
     * a `before` would produce `validation_failed` where the contract names
     * `invalid_date_range`, and would put the 62-day rule in a second place.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product' => ['required', 'string', 'max:120'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'pax' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function productIdentifier(): string
    {
        return (string) $this->query('product');
    }

    /**
     * How many capacity-counting passengers the guest intends to book, or null.
     *
     * Null is a calendar asking what exists; a number is a family asking
     * whether they fit. The engine treats the two differently — with no party
     * it reports what sails, with one it reports what sails **and can seat
     * them** — so the distinction is preserved rather than defaulted to 1.
     */
    public function pax(): ?int
    {
        $pax = $this->query('pax');

        return is_numeric($pax) ? max(1, (int) $pax) : null;
    }

    /**
     * The party, in the engine's vocabulary.
     *
     * @return array<string, int>
     */
    public function paxByCode(Product $product): array
    {
        $pax = $this->pax();

        if ($pax === null) {
            return [];
        }

        $base = AgeBandResolver::base($product->ageBands);

        // A product with no base band cannot price anything and CAT-8 refuses
        // to save one, so this is a data defect rather than a request problem.
        // Answering as though no party was given is the honest degradation: the
        // calendar shows what sails and the price quote will explain why it
        // cannot be bought.
        return $base instanceof AgeBand ? [$base->code => $pax] : [];
    }

    /**
     * The validated range, or `422 invalid_date_range`.
     *
     * @throws HttpResponseException
     */
    public function range(Product $product): AvailabilityRequestData
    {
        try {
            return AvailabilityRequestData::forRange(
                (string) $this->query('from'),
                (string) $this->query('to'),
                $this->paxByCode($product),
            );
        } catch (ValidationException) {
            throw new HttpResponseException(ApiErrorResponse::fromKey(
                key: 'api.errors.invalid_date_range',
                code: 'invalid_date_range',
                status: 422,
                details: [
                    'from' => (string) $this->query('from'),
                    'to' => (string) $this->query('to'),
                    'max_days' => AvailabilityRequestData::MAX_DAYS,
                ],
            ));
        }
    }
}
