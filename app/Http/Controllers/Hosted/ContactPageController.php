<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Booking\Actions\SubmitEnquiry;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Enums\BookingSource;
use App\Http\Requests\Api\V1\EnquiryCreateRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/{operator}/contact` — the page with a form on it (HOS-1, BKG-28, BKG-29).
 *
 * ## It writes an enquiry, not an email
 *
 * The obvious build is a form that sends the operator a message. That message
 * then lives in an inbox, gets answered or does not, and nobody can say which
 * — while `enquiries` already exists, with a status, an owner, a panel screen
 * and an immediate notification (BKG-29). A contact form that produced an email
 * instead would be a second, worse copy of a thing this product already does
 * properly, and it would be the copy the operator loses questions in.
 *
 * So this is the same Action the widget's enquiry mount and the API call:
 * {@see SubmitEnquiry}. The only difference is `source`, which is `hosted`
 * because that is where this one came from.
 *
 * ## The same request object as the API, on purpose
 *
 * {@see EnquiryCreateRequest} carries BKG-29's two filters — the honeypot and
 * the timing check — and their limits are written down in its docblock. A
 * second set of rules for the same question is how the web form ends up with a
 * validation the API does not have, or without one the API does. A `FormRequest`
 * redirects back with the errors on a web route and answers JSON on an API one,
 * which is exactly the difference that should exist between the two.
 *
 * ## No JavaScript, like everything else here
 *
 * HOS-4. A plain `POST`, a redirect, and a sentence on the way back. The timing
 * check's `form_rendered_at` is a hidden field stamped by the server when the
 * page is rendered rather than by a script when it loads — which is both more
 * honest and the only version that works with scripts blocked.
 */
class ContactPageController extends HostedController
{
    public function __construct(
        GetBrandPayload $brand,
        private readonly SubmitEnquiry $submit,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.contact', fn (): array => [
            // Stamped here, so the "too fast" filter measures the time between
            // the page being built and the form coming back — with no script
            // involved, and with a value the browser cannot have made up
            // earlier than the request that produced it.
            'renderedAt' => now()->toIso8601String(),
            'about' => $this->productFor($request),
            'metaDescription' => (string) __('hosted.contact.meta', ['operator' => $tenant->name]),
        ], $locale);
    }

    /**
     * The trip the visitor arrived from, if they arrived from one.
     *
     * `?product={uuid}` on the link in the product page's «any questions» card.
     * It becomes `enquiries.product_id`, so the operator reads the question
     * beside the trip it is about rather than beside "is this available?" with
     * no idea what "this" is.
     *
     * Resolved through the tenant-scoped model, so a uuid from another
     * operator's catalogue — or a made-up one — is simply not found, and the
     * page renders as the general contact page it also is.
     */
    private function productFor(Request $request): ?Product
    {
        $uuid = $request->query('product');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Product::query()->where('uuid', $uuid)->first();
    }

    public function send(EnquiryCreateRequest $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        $back = redirect()->route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale]);

        if ($request->looksAutomated()) {
            // BKG-29's second filter, and the refusal says the message could not
            // be sent rather than naming the check it failed. The one person
            // this ever refuses wrongly is a human on a fast connection, and
            // they need a way forward rather than an accusation — so their words
            // come back with them (`withInput`) instead of an empty form.
            return $back->withInput()->withErrors(['message' => __('hosted.contact.rejected')]);
        }

        ($this->submit)($request->toData(BookingSource::Hosted));

        // `with` rather than a query parameter: the confirmation belongs to this
        // one submission and should not survive a refresh, a bookmark or a link
        // somebody pastes to a friend.
        return $back->with('sent', true);
    }
}
