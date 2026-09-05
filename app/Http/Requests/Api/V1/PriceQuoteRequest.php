<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\AgeBand;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `POST /api/v1/price-quote` (spec PRC-1, WGT-13;
 * `docs/api.md`, `PriceQuoteRequest`).
 *
 * ## Nothing money-shaped may appear, and it is refused rather than ignored
 *
 * The contract is blunt: *"**No price, no total and no discount may appear in
 * this body** — anything money-shaped is rejected as an unknown field."*
 *
 * Ignoring such a field would be safe — nothing below reads one — but refusing
 * is better, and the difference is the client that *thinks* it is setting a
 * price. Silently dropping the field means an integrator ships a checkout that
 * appears to apply their own discount, and finds out when a guest is charged
 * the difference. A 422 tells them on their first request.
 *
 * Only money-shaped keys are refused, not every unknown one: a client sending
 * `client_version` or a tracking id is harmless, and rejecting it would make the
 * endpoint brittle for no safety gain.
 *
 * PRC-1 is the rule underneath: price is computed server-side and frozen into
 * the snapshot. The widget never sends and never computes a price.
 *
 * ## The wire speaks uuids; the engine speaks codes and ids
 *
 * `pax` carries `age_band_uuid` and `extras` carries `extra_uuid`, because
 * CNV-8 keeps database ids off the wire. {@see ComputePrice} takes band
 * **codes** — the stable key a snapshot groups by, which survives an operator
 * renaming a band — and extra **ids**. The translation happens here, against
 * this product's own bands and offered extras, so a uuid belonging to another
 * product simply is not found rather than silently pricing something else.
 */
final class PriceQuoteRequest extends FormRequest
{
    /**
     * Keys that may never appear in this body, in the spellings a client
     * reaches for. Substring-matched, so `total`, `total_cents` and
     * `grand_total` are all caught by one entry.
     *
     * @var list<string>
     */
    private const MONEY_SHAPED = [
        'price', 'total', 'amount', 'cents', 'discount', 'deposit', 'subtotal', 'vat', 'fee',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_uuid' => ['required', 'string', 'max:120'],
            'departure_uuid' => ['nullable', 'string', 'max:64'],

            'window' => ['nullable', 'array'],
            'window.local_date' => ['required_with:window', 'date_format:Y-m-d'],
            'window.local_time' => ['nullable', 'date_format:H:i'],
            'window.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],

            'pax' => ['required', 'array', 'min:1'],
            'pax.*.age_band_uuid' => ['required', 'string', 'max:64'],
            'pax.*.qty' => ['required', 'integer', 'min:0', 'max:500'],

            'extras' => ['sometimes', 'array'],
            'extras.*.extra_uuid' => ['required', 'string', 'max:64'],
            'extras.*.qty' => ['required', 'integer', 'min:1', 'max:500'],

            // PRC-18 is M2. The contract says an invalid code is "an error, not
            // a silent no-op", and a code nothing can redeem is invalid — so it
            // is refused rather than dropped. A guest who typed one would
            // otherwise be charged full price with no explanation.
            'voucher_code' => ['prohibited'],

            // The contract's `additionalProperties: false`, for the half that
            // matters. See the class docblock for why these are refused rather
            // than ignored, and `prohibited` rather than an `after()` error.
            ...$this->moneyShapedRules(),
        ];
    }

    /**
     * A `prohibited` rule for every money-shaped key actually submitted.
     *
     * **A real rule, not an `after()` callback**, and the difference is
     * §4.1's promise of both languages in every error. A message added in
     * `after()` is a finished string in whichever locale was active, and
     * `ApiExceptionRenderer` re-localises by *re-running the rules* — so a
     * hand-added error has nothing to re-run and comes back with the same
     * sentence in both slots. Caught by `AvailabilityLocaleTest`, which asserts
     * the two are actually different.
     *
     * Laravel's own `validation.prohibited` line exists in both locales, so the
     * wording is generic and correct rather than specific and monolingual. The
     * per-field `code` is `prohibited`, which is what an integrator branches on;
     * the explanation of *why* belongs in the contract, where they are reading
     * before they send anything.
     *
     * @return array<string, list<string>>
     */
    private function moneyShapedRules(): array
    {
        $rules = [];

        foreach ($this->moneyShapedKeys() as $key) {
            $rules[$key] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Every money-shaped key anywhere in the submitted body, dotted.
     *
     * Walks the whole tree rather than the top level: `window.price_cents` is
     * the same assumption wearing a hat.
     *
     * @return list<string>
     */
    public function moneyShapedKeys(): array
    {
        return $this->scan($this->all(), '');
    }

    /**
     * @param  array<mixed>  $body
     * @return list<string>
     */
    private function scan(array $body, string $prefix): array
    {
        $found = [];

        foreach ($body as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (! is_int($key) && self::isMoneyShaped((string) $key)) {
                $found[] = $path;
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->scan($value, $path)];
            }
        }

        return $found;
    }

    private static function isMoneyShaped(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::MONEY_SHAPED as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function productIdentifier(): string
    {
        return (string) $this->input('product_uuid');
    }

    public function departureUuid(): ?string
    {
        $uuid = $this->input('departure_uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * The party, keyed by age-band **code**.
     *
     * A uuid this product does not own is dropped rather than erroring: the
     * effect is a party missing those passengers, which the counted-pax and
     * capacity rules then judge on its merits. Erroring would leak whether the
     * uuid exists on some other product.
     *
     * @return array<string, int>
     */
    public function paxByCode(Product $product): array
    {
        $byUuid = $product->ageBands->keyBy('uuid');

        $pax = [];

        foreach ((array) $this->input('pax', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $band = $byUuid->get((string) ($entry['age_band_uuid'] ?? ''));

            if ($band instanceof AgeBand) {
                // Summed, not overwritten: a client that lists the same band
                // twice means four adults, not two.
                $pax[$band->code] = ($pax[$band->code] ?? 0) + max(0, (int) ($entry['qty'] ?? 0));
            }
        }

        return $pax;
    }

    /**
     * The extras, keyed by database id, as {@see ComputePrice} expects.
     *
     * @param  iterable<Extra>  $offered
     * @return array<int, int>
     */
    public function extraQuantities(iterable $offered): array
    {
        $byUuid = [];

        foreach ($offered as $extra) {
            $byUuid[$extra->uuid] = $extra->getKey();
        }

        $quantities = [];

        foreach ((array) $this->input('extras', []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $byUuid[(string) ($entry['extra_uuid'] ?? '')] ?? null;

            if ($id !== null) {
                $quantities[$id] = ($quantities[$id] ?? 0) + max(0, (int) ($entry['qty'] ?? 0));
            }
        }

        return $quantities;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'voucher_code.prohibited' => (string) __('pricing.quote.validation.voucher_not_supported'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function window(): ?array
    {
        $window = $this->input('window');

        return is_array($window) ? $window : null;
    }
}
