<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Data\Catalog\SearchCriteriaData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Catalog\Support\SearchFilters;
use App\Enums\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The query string of `GET /api/v1/search` (#105; `docs/api.md` §5).
 *
 * ## A disabled filter is dropped here, before anything can honour it
 *
 * This is the class the issue's own note is about: *"hiding it in the template
 * and honouring it in the controller is the version that passes a visual review
 * and fails the operator who turned it off precisely because their answer would
 * be embarrassing."*
 *
 * So every optional parameter is read through {@see SearchFilters::isEnabled()}
 * and is **null** when the operator has switched that filter off. The Action
 * never sees it, the response's `meta.applied` never lists it, and
 * `SearchFilterTest` proves the result set is identical to the unfiltered one.
 *
 * ## Validation refuses, filters do not
 *
 * A malformed `date` is `422` — there is no question to answer without one. A
 * `type` that is not a category, or a `port` uuid belonging to another operator,
 * is **not** an error: it is a filter that matches nothing, which is WGT-6's
 * rule for the catalogue endpoint and the same reasoning here. An unknown
 * category that widened the result set to everything would be the one answer
 * that is certainly wrong.
 */
final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'pax' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'port' => ['sometimes', 'string', 'max:64'],
            'type' => ['sometimes', 'string', 'max:40'],
            'duration_max' => ['sometimes', 'integer', 'min:1', 'max:10080'],
            'price_max' => ['sometimes', 'integer', 'min:0'],
            'vessel' => ['sometimes', 'string', 'max:64'],
        ];
    }

    /**
     * The criteria, with the operator's settings already applied.
     */
    public function criteria(): SearchCriteriaData
    {
        $enabled = SearchFilters::enabled();

        $port = $this->filtered(SearchFilters::PORT, 'port');
        $category = $this->category();
        $duration = $this->filtered(SearchFilters::DURATION, 'duration_max');
        $price = $this->filtered(SearchFilters::PRICE, 'price_max');
        $vessel = $this->filtered(SearchFilters::VESSEL, 'vessel');

        $applied = array_filter([
            'date' => $this->searchDate()->toDateString(),
            'pax' => $this->pax(),
            'port' => $port,
            'type' => $category,
            'duration_max' => $duration,
            'price_max' => $price,
            'vessel' => $vessel,
        ], static fn (mixed $value): bool => $value !== null);

        return new SearchCriteriaData(
            date: $this->searchDate(),
            pax: $this->pax(),
            portUuid: is_string($port) ? $port : null,
            category: $category,
            durationMaxMinutes: is_numeric($duration) ? (int) $duration : null,
            priceMaxCents: is_numeric($price) ? (int) $price : null,
            vesselUuid: is_string($vessel) ? $vessel : null,
            filtersEnabled: $enabled,
            applied: $applied,
        );
    }

    /**
     * The searched day, in the **tenant's** timezone.
     *
     * A date is a wall-clock idea: "Saturday" means Saturday in the harbour, not
     * in whatever zone the server happens to run in. Parsing it in the tenant's
     * zone is what makes the day boundaries line up with the departures.
     *
     * Not `date()`: `Illuminate\Http\Request` already has one, with a different
     * signature, and overriding it is a fatal error rather than a failing test.
     */
    public function searchDate(): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d',
            (string) $this->query('date'),
            LocalDateTimeResolver::timezone(),
        )->startOfDay();
    }

    /** The party size, defaulting to one rather than to none. */
    public function pax(): int
    {
        $pax = $this->query('pax');

        return is_numeric($pax) ? max(1, (int) $pax) : 1;
    }

    /**
     * A category that exists, or null.
     *
     * An unknown value matches nothing rather than erroring (WGT-6), and here
     * that means dropping it — with the category filter empty the search answers
     * the rest of the question rather than refusing the whole of it.
     */
    private function category(): ?string
    {
        $value = $this->filtered(SearchFilters::TYPE, 'type');

        return is_string($value) ? ProductCategory::tryFrom($value)?->value : null;
    }

    /**
     * A parameter's value, or null when the operator has that filter switched
     * off.
     *
     * The whole enforcement of the acceptance criterion is this one method.
     */
    private function filtered(string $filter, string $key): mixed
    {
        if (! SearchFilters::isEnabled($filter)) {
            return null;
        }

        $value = $this->query($key);

        return $value === '' ? null : $value;
    }
}
