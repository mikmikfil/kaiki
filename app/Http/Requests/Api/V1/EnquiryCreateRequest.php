<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Booking\Data\EnquiryData;
use App\Enums\BookingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The body of `POST /api/v1/enquiries` (spec BKG-28, BKG-29;
 * `docs/api.md`, `EnquiryCreateRequest`).
 *
 * ## Two cheap filters, and what they are honestly worth
 *
 * BKG-29 asks for a honeypot **and** a timing check, with *"no third-party
 * CAPTCHA in MVP"*. Neither stops anybody who is trying: a honeypot field is
 * visible in the DOM, and a client-supplied `form_rendered_at` is a number the
 * client chose. What they stop is the overwhelming majority of the traffic this
 * endpoint actually gets — scripts that POST to every form they find and fill
 * every field they see. The real defence is the rate limit, which is why this
 * endpoint's per-IP number is **5 a minute**, the tightest in `docs/api.md`
 * §3.6.
 *
 * Saying that plainly is the point. A filter whose limits are written down is a
 * filter nobody mistakes for security.
 *
 * ## The timing check is bounded at one end only
 *
 * A guest who opens the form, is interrupted by a phone call and submits forty
 * minutes later is **not** a bot, and an "implausibly slow" rejection would
 * refuse exactly the enquiries an operator most wants — the careful ones. Too
 * fast is a signal; too slow is a life. So a submission faster than
 * {@see self::MINIMUM_SECONDS} is refused and everything slower is accepted,
 * including a missing or unparseable timestamp: a client that does not send one
 * is a WordPress shortcode from before the field existed, not an attacker.
 *
 * ## `company_website` is refused, not stripped
 *
 * `docs/api.md`: *"A filled value is answered `422 enquiry_rejected` with no row
 * created and no notification sent."* Accepting-and-discarding would leave the
 * bot believing it succeeded; refusing costs nothing and tells the truth. It is
 * validated here and never travels further — {@see EnquiryData} has no property
 * for it and `enquiries` has no column.
 */
final class EnquiryCreateRequest extends FormRequest
{
    /**
     * Faster than this and a human did not read the form.
     *
     * Three seconds, which is the shortest anybody plausibly takes to type a
     * name, an email and a sentence — and long enough that a script POSTing
     * immediately after a GET is caught. Not configurable: a per-tenant knob
     * here would be a per-tenant way to switch off the check.
     */
    public const MINIMUM_SECONDS = 3;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_uuid' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            // A local date (§1.5). `date_format` rather than `date`, because
            // `date` accepts "next tuesday" and stores whatever PHP made of it.
            'preferred_date' => ['nullable', 'date_format:Y-m-d'],
            'pax' => ['nullable', 'integer', 'min:1', 'max:500'],
            'message' => ['required', 'string', 'min:5', 'max:4000'],
            'locale' => ['nullable', 'string', 'in:el,en'],

            // Must be empty, and is refused rather than ignored — see the class
            // docblock. Validated as a string so a bot filling it with an array
            // gets a 422 rather than an exception.
            'company_website' => ['nullable', 'string', 'max:0'],

            // Optional, and only ever used to refuse. See the class docblock:
            // a client that does not send one is not an attacker.
            'form_rendered_at' => ['nullable', 'date'],

            'consent' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Was this submitted implausibly fast?
     *
     * Returns false for a missing, unparseable or future timestamp. A future
     * one is a clock-skewed browser rather than a bot, and refusing it would
     * turn a wrong system time into an unfixable "your message could not be
     * sent".
     */
    public function looksAutomated(): bool
    {
        $rendered = $this->input('form_rendered_at');

        if (! is_string($rendered) || trim($rendered) === '') {
            return false;
        }

        try {
            $at = Carbon::parse($rendered);
        } catch (Throwable) {
            return false;
        }

        if ($at->isFuture()) {
            return false;
        }

        return $at->diffInSeconds(now()) < self::MINIMUM_SECONDS;
    }

    /**
     * The enquiry, with everything the request itself knows attached.
     *
     * `$source` is a **parameter** and not a guess. The API cannot tell a widget
     * mount from a WordPress shortcode from a hosted page, and reading the
     * referrer would be a guess recorded as a fact — so the public endpoint
     * passes nothing and gets `widget`, the column's own default, while the
     * hosted contact page passes `hosted` because it knows.
     */
    public function toData(BookingSource $source = BookingSource::Widget): EnquiryData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return new EnquiryData(
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            message: (string) $validated['message'],
            // The request's resolved locale, not the browser's raw header: the
            // I18N-5 chain has already run in middleware, and the body may
            // override it.
            locale: is_string($validated['locale'] ?? null) ? $validated['locale'] : app()->getLocale(),
            productUuid: is_string($validated['product_uuid'] ?? null) ? $validated['product_uuid'] : null,
            phone: is_string($validated['phone'] ?? null) ? $validated['phone'] : null,
            preferredDate: is_string($validated['preferred_date'] ?? null) ? $validated['preferred_date'] : null,
            pax: isset($validated['pax']) ? (int) $validated['pax'] : null,
            source: $source,
            ipAddress: $this->ip(),
            // Truncated to the column rather than rejected: a long user agent
            // is a browser, not an attack.
            userAgent: mb_substr((string) $this->userAgent(), 0, 500) ?: null,
        );
    }
}
