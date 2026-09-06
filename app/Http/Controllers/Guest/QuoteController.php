<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\DeclineQuote;
use App\Domain\Booking\Actions\SubmitEnquiry;
use App\Domain\Booking\Data\EnquiryData;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/q/{quote_token}` — the offer (spec TOK-11, BKG-26, §4.4).
 *
 * ## An expired or superseded quote is a page, not an error
 *
 * TOK-11: *"Expired quotes render read-only with a 'request a new quote' action
 * that creates an Enquiry."* §4.4 says the same from the other side — a
 * superseded quote goes to `expired` and is never deleted, precisely *"because
 * the guest may still have the old link open"*.
 *
 * That is why this page is a 200 in every quote status and only 404s on a token
 * that does not resolve. A guest holding last week's link is told their offer
 * was replaced and given a way forward; a guest holding a token that never
 * existed gets TOK-4's generic refusal. The two are different situations and
 * only one of them is a security question.
 *
 * ## `viewed_at` is stamped once, on the first successful view
 *
 * §2.5's own description of the column. An operator chasing a quote wants to
 * know whether it was ever opened, and re-stamping it on every refresh would
 * turn "opened on Tuesday" into "opened whenever they last looked".
 *
 * ## Acceptance can fail, and the page says why in a sentence
 *
 * §4.4 calls the re-check *"the single most important behaviour to get right in
 * quote mode"*. {@see AcceptQuote} throws `vessel_unavailable` when the boat
 * has gone; the guest is told the date is no longer available and to contact
 * the operator, and **the quote stays `sent`** so there is something to
 * re-quote from.
 */
final class QuoteController extends GuestPageController
{
    public function show(Request $request, string $token): Response
    {
        [$quote, $tenant] = $this->resolve($token);

        if (! $quote instanceof Quote || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $booking = Tenancy::forTenant($tenant, fn (): ?Booking => Booking::query()->find($quote->booking_id));

        if (! $booking instanceof Booking) {
            return $this->linkNotValid($request);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        return $this->renderInTenant($tenant, 'guest.quote', function () use ($quote, $tenant, $locale, $booking, $token): array {
            $this->stampFirstView($quote);

            return [
                'brand' => $this->brandFor($tenant, $locale),
                'quote' => $quote,
                'booking' => $booking,
                'token' => $token,
                'lines' => $quote->lineItems()->get(),
                // Not a promise about the boat — `Quote::canBeAccepted()` says
                // only that the offer is live and undecided. Whether the vessel
                // is still free is a locked read taken at the moment of
                // acceptance, and answering it here would answer it from a row
                // written days ago.
                'canAccept' => $quote->canBeAccepted(),
                'wasReplaced' => self::wasReplaced($quote),
            ];
        });
    }

    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        [$quote, $tenant] = $this->resolve($token);

        if (! $quote instanceof Quote || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        try {
            Tenancy::forTenant($tenant, fn () => app(AcceptQuote::class)($quote));
        } catch (RuntimeException $exception) {
            // `vessel_unavailable`, or an offer that is no longer live. Both go
            // back to the page, which renders the reason — a stack trace on a
            // guest-facing page is a guest who telephones.
            return redirect()
                ->route('guest.quote', ['token' => $token])
                ->with('quote_error', $exception->getMessage());
        }

        return redirect()->route('guest.quote', ['token' => $token]);
    }

    public function decline(Request $request, string $token): RedirectResponse|Response
    {
        [$quote, $tenant] = $this->resolve($token);

        if (! $quote instanceof Quote || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $reason = $request->input('reason');

        Tenancy::forTenant($tenant, fn () => app(DeclineQuote::class)(
            $quote,
            is_string($reason) ? $reason : null,
        ));

        return redirect()->route('guest.quote', ['token' => $token]);
    }

    /**
     * TOK-11's "request a new quote", which creates an {@see EnquiryData}.
     *
     * An enquiry rather than a new quote, and that is the right shape: a quote
     * is something an **operator** writes, and a guest asking for one is asking
     * a question. It also means the request lands in the inbox the operator
     * already watches rather than in a second queue nobody checks.
     */
    public function requestNew(Request $request, string $token): RedirectResponse|Response
    {
        [$quote, $tenant] = $this->resolve($token);

        if (! $quote instanceof Quote || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        $message = $request->input('message');

        Tenancy::forTenant($tenant, function () use ($quote, $message, $request): void {
            $booking = Booking::query()->find($quote->booking_id);

            if (! $booking instanceof Booking) {
                return;
            }

            $product = Product::query()->find($booking->product_id);

            app(SubmitEnquiry::class)(new EnquiryData(
                name: $booking->guest_name,
                email: $booking->guest_email,
                // The guest's own words where they wrote any, and a plain
                // statement of what happened where they did not — an empty
                // enquiry would reach the operator saying nothing.
                message: is_string($message) && trim($message) !== ''
                    ? $message
                    : __('quotes.quote.guest.request_new_message', ['reference' => $booking->reference]),
                locale: $booking->locale,
                productUuid: $product?->uuid,
                phone: $booking->guest_phone,
                preferredDate: $booking->local_date->toDateString(),
                pax: $booking->pax_total,
                ipAddress: $request->ip(),
            ));
        });

        return redirect()->route('guest.quote', ['token' => $token]);
    }

    /** Was this quote replaced by a newer version (§4.4)? */
    public static function wasReplaced(Quote $quote): bool
    {
        return $quote->status === QuoteStatus::Expired
            && Quote::query()
                ->where('booking_id', $quote->booking_id)
                ->where('version', '>', $quote->version)
                ->exists();
    }

    /** §2.5: the **first** time `/q/{token}` was opened, and only the first. */
    private function stampFirstView(Quote $quote): void
    {
        if ($quote->viewed_at !== null) {
            return;
        }

        $quote->forceFill(['viewed_at' => now()])->save();
    }

    /** @return array{0: Quote|null, 1: Tenant|null} */
    private function resolve(string $token): array
    {
        $quote = GuestTokenResolver::quote($token);

        return [$quote, $quote === null ? null : GuestTokenResolver::tenantOf($quote->tenant_id)];
    }
}
