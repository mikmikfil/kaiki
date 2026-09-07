<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The query string of `GET /api/v1/sync/products` (`docs/api.md` §5).
 *
 * Same shape of decision as {@see ProductIndexRequest} — `per_page` is clamped
 * and a broken `cursor` is `400 invalid_cursor` — with one parameter that is
 * genuinely new and genuinely refusable.
 *
 * ## An unparseable `updated_since` is a refusal, not a default
 *
 * Every other parameter in this API reads a value it cannot use as "no
 * preference". This one must not. `updated_since` is the client's claim about
 * what it already holds, and the two possible defaults are both wrong in a way
 * the client cannot detect: treating it as absent re-sends the entire catalogue
 * on every run, and treating it as "now" silently skips everything changed
 * since the last successful sync. The second is a mirror that quietly stops
 * updating and reports success for ever.
 *
 * So it is `400 invalid_updated_since`, which is a request the caller can fix.
 */
final class SyncProductsRequest extends FormRequest
{
    /** `docs/api.md`, `CursorQuery`: `schema: { type: string, maxLength: 512 }`. */
    private const MAX_CURSOR_LENGTH = 512;

    /**
     * Settled before this class is constructed: `api.secret` refuses a
     * publishable key and `api.scope:products.read` refuses a narrowed one.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty for the reason {@see ProductIndexRequest::rules()} gives at length:
     * a rule here is a `422 validation_failed`, and the contract lists no 422
     * among this operation's responses.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The instant to resume from, or null for a full sync.
     *
     * Parsed as UTC unless the value carries its own offset. A client that
     * stores `meta.sync_cursor` verbatim sends back an ISO-8601 instant with a
     * `Z`, which is the case this is built for; a bare date from somebody
     * exploring the endpoint by hand is read as midnight UTC rather than
     * refused.
     */
    public function updatedSince(): ?Carbon
    {
        $value = $this->query('updated_since');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            throw new HttpResponseException(ApiErrorResponse::fromKey(
                key: 'api.errors.invalid_updated_since',
                code: 'invalid_updated_since',
                status: 400,
                details: ['updated_since' => $value],
            ));
        }
    }

    /**
     * Whether non-`active` products travel. **Defaults to true** for this
     * endpoint alone (§5): a mirror that only ever sees `active` products has
     * no way to unpublish the page of one an operator switched off, which is
     * the same orphan-page failure tombstones exist to prevent.
     *
     * Only an explicit, unambiguous false turns it off — `0`, `false`, `no` and
     * `off`. Anything else reads as the default, because a client that meant to
     * narrow the feed and mistyped the value should get the safe answer.
     */
    public function includeInactive(): bool
    {
        $value = $this->query('include_inactive');

        if (! is_string($value)) {
            return true;
        }

        return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Items per page. The contract's default and maximum for this endpoint are
     * both 100 (§3.5) — a sync wants the largest page it can have, and the cap
     * exists so one call cannot ask for the whole catalogue at once.
     */
    public function perPage(): int
    {
        $requested = $this->query('per_page');

        $max = (int) config('kaiki.catalog.api.sync_per_page', 100);

        if (! is_numeric($requested)) {
            return $max;
        }

        return max(1, min((int) $requested, $max));
    }

    /**
     * The pagination cursor, refused when it is not one.
     *
     * Serving page one to a client that asked for page nine is how a sync job
     * loses half a catalogue and reports a clean run — which is why this is a
     * refusal here and a clamp everywhere else.
     */
    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');

        if (! is_string($cursor) || $cursor === '') {
            return null;
        }

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
