<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\Cursor;

/**
 * The query string of `GET /api/v1/products` (`docs/api.md` §3.5).
 *
 * ## The filters do not validate, and that is the requirement
 *
 * WGT-6: an unknown `category` **returns an empty list rather than an error**.
 * An operator writes `data-category="sunset"` into their page once and the embed
 * outlives the products it was written for; a category they stopped selling must
 * render an empty widget, not a red error where the trips used to be. The same
 * reasoning covers `mode` and `vessel` — all three are filters, and a filter
 * that matches nothing is an empty result.
 *
 * That is also why an unrecognised value is passed through to the query as a
 * plain string rather than mapped onto the enum and dropped: dropping it would
 * silently *widen* the result to the whole catalogue, which is the one answer
 * that is definitely wrong.
 *
 * ## One thing fails, and it is not `per_page`
 *
 * `per_page` is **clamped, never rejected** (§3.5) — including a value that is
 * not a number at all, which reads as "no preference" and takes the default.
 *
 * A `cursor` that cannot have come from `pagination.next_cursor` is
 * `400 invalid_cursor`. It is the one refusal because it is the one value with
 * no sensible reading: silently serving page one to a client that asked for
 * page nine is how a sync job loses half a catalogue and reports a clean run.
 */
final class ProductIndexRequest extends FormRequest
{
    /** `docs/api.md`, `CursorQuery`: `schema: { type: string, maxLength: 512 }`. */
    private const MAX_CURSOR_LENGTH = 512;

    /**
     * Authorisation is the API key's, and the `api.scope:products.read`
     * middleware has already settled it before this class is constructed.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * **Empty, and that is the design.**
     *
     * A rule here produces `422 validation_failed`, and the contract does not
     * list a 422 among this operation's responses at all — the only request
     * failure it documents is `400 invalid_cursor`. A first draft validated
     * `per_page` as an integer and `cursor` as `max:512`, and both turned into
     * a 422 that no client is told to expect.
     *
     * They are also the wrong answers. `?per_page=abc` has an obvious reading
     * — the default — and §3.5 already says values out of range are clamped
     * rather than rejected; refusing garbage while clamping a number is an
     * inconsistency a caller has to learn. And a 600-character cursor is not a
     * length problem, it is a **malformed cursor**, which §3.5 names and gives
     * its own code.
     *
     * So every query parameter is read through an accessor below, each of which
     * states what it does with a value it cannot use.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The categories to filter by, as raw strings.
     *
     * `?category=sunset` and `?category[]=sunset&category[]=custom` are both
     * accepted — the contract declares the parameter `style: form, explode:
     * true`, and the widget's `data-category` passes one value.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $value = $this->query('category');

        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $values),
            static fn (string $v): bool => $v !== '',
        ));
    }

    public function mode(): ?string
    {
        $mode = $this->query('mode');

        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    public function vesselUuid(): ?string
    {
        $vessel = $this->query('vessel');

        return is_string($vessel) && $vessel !== '' ? $vessel : null;
    }

    /**
     * Items per page, clamped into the contract's range.
     *
     * Clamped at both ends. §3.5 only says values above the maximum are
     * clamped, but `?per_page=0` and `?per_page=-1` have no useful reading
     * either, and a paginator handed a zero throws.
     */
    public function perPage(): int
    {
        $requested = $this->query('per_page');

        if (! is_numeric($requested)) {
            return (int) config('kaiki.catalog.api.per_page', 24);
        }

        return max(1, min((int) $requested, (int) config('kaiki.catalog.api.max_per_page', 100)));
    }

    /**
     * The pagination cursor, refused when it is not one.
     *
     * `Cursor::fromEncoded()` returns null for anything that is not valid
     * base64url JSON, which is exactly the "malformed or stale" §3.5 names.
     * Laravel would otherwise treat a broken cursor as no cursor at all and
     * serve page one — the failure that looks like success.
     */
    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');

        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

        // The contract caps a cursor at 512 characters. Over that is not a
        // length error to report on its own — it is a value that cannot have
        // come from `pagination.next_cursor`, which is what `invalid_cursor`
        // means.
        if (strlen($cursor) > self::MAX_CURSOR_LENGTH || Cursor::fromEncoded($cursor) === null) {
            throw new HttpResponseException(ApiErrorResponse::fromKey(
                key: 'api.errors.invalid_cursor',
                code: 'invalid_cursor',
                status: 400,
            ));
        }

        return $cursor;
    }
}
