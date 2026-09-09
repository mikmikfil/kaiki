<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\MintCheckoutSession;
use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/c/{manage_token}` — the checkout page (amends WGT-18).
 *
 * ## Why a page, when the widget used to do this in place
 *
 * The product owner asked for it directly: *"user select dates, selects adults,
 * childs etc. then goes to checkout where they need to complete all the
 * στοιχεία and then pays."* The widget keeps the two questions a guest can
 * answer while still browsing — which day, how many people — and everything
 * that follows happens here.
 *
 * WGT-18 described the old walk (date, party, extras, contact, review, gateway)
 * and is amended rather than broken: it is not FIXED, and **BKG-5 is untouched**
 * — draft with a hold, then `checkout`, then the gateway, then the webhook. Only
 * the surface the details are typed on has moved.
 *
 * ## It fixes the missing price by construction
 *
 * The widget's review step asked for a `quote` prop that `BookingMount` never
 * passed and nothing ever fetched, so it showed "Υπολογίζουμε την τιμή σας…"
 * for ever and a guest pressed pay having never been shown a total. This page
 * renders the breakdown out of `bookings.price_snapshot`, which is frozen at
 * draft creation (§3.4) and is the figure the gateway will be asked for. There
 * is no request to forget to make.
 *
 * ## The token is the credential (TOK-1)
 *
 * The same `manage_token` the booking already carries, resolved by the same
 * resolver as `/b/`. No new column and no second secret: a draft is a booking,
 * and the person holding the link is the person who made it. A separate path
 * from `/b/` because managing a confirmed booking and paying for a draft are
 * different jobs with different shapes — the same reason `/g/` and `/q/` are
 * their own routes.
 *
 * ## What it asks for
 *
 * The lead booker always. Per-passenger names and document numbers **only when
 * the trip requires them** (`products.guest_details_required`), which is the
 * flag the catalogue already carries for exactly this and which nothing read
 * until now. A three-hour sunset cruise stays four fields; the manifest is
 * asked for where the coastguard actually wants one.
 */
final class CheckoutController extends GuestPageController
{
    public function __construct(
        GetBrandPayload $brand,
        private readonly MintCheckoutSession $mintSession,
        private readonly SaveGuestDetails $saveGuests,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request, string $token): Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        // A booking that is no longer waiting to be paid for has a better page
        // than this one, and it is the page the same token opens.
        if (! self::isPayable($booking)) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        return $this->renderInTenant($tenant, 'guest.checkout', fn (): array => [
            'brand' => $this->brandFor($tenant, $locale),
            'booking' => $booking->load(['product', 'departure', 'guests']),
            'token' => $token,
            'needsGuestDetails' => (bool) $booking->product?->guest_details_required,
        ]);
    }

    public function pay(Request $request, string $token): RedirectResponse
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant || ! self::isPayable($booking)) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        // Everything from here runs inside the tenant. Reading
        // `$booking->product` outside it throws `TenantContextMissingException`
        // (TEN-4) — `show()` never hit that because `renderInTenant()` wraps
        // its whole closure, and this method had no such wrapper.
        return Tenancy::forTenant($tenant, function () use ($booking, $request, $token): RedirectResponse {
            $needsGuests = (bool) $booking->product?->guest_details_required;

            $rules = [
                'guest_name' => ['required', 'string', 'max:120'],
                'guest_email' => ['required', 'email', 'max:190'],
                'guest_phone' => ['nullable', 'string', 'max:32'],
                'special_requests' => ['nullable', 'string', 'max:1000'],
                // Not `accepted` alone: the checkbox is the evidence recorded in
                // `terms_accepted_at` beside the IP (§2.5), so it has to be present
                // rather than merely truthy.
                'terms' => ['accepted'],
            ];

            if ($needsGuests) {
                $rules['guests'] = ['required', 'array', 'min:1'];
                $rules['guests.*.full_name'] = ['required', 'string', 'max:120'];
                $rules['guests.*.document_number'] = ['nullable', 'string', 'max:40'];
                $rules['guests.*.date_of_birth'] = ['nullable', 'date', 'before:today'];
            }

            $data = $request->validate($rules);

            $booking->forceFill([
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'guest_phone' => $data['guest_phone'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'terms_accepted_at' => now(),
                'ip_address' => $request->ip(),
            ])->save();

            if ($needsGuests) {
                // The same Action `/g/{token}` uses, so the manifest is written
                // one way whether it is filled in here or afterwards.
                ($this->saveGuests)($booking, $data['guests']);
            }

            try {
                // Whatever the page said it would charge. A deposit product
                // shows «πληρώνετε X τώρα και Y πριν την αναχώρηση» beside the
                // button, and charging the full total after that is the page
                // lying about money — which is the one thing a checkout may
                // never do. `MintCheckoutSession` refuses a deposit that does
                // not exist, so the fallback is the total.
                $result = ($this->mintSession)($booking, self::kindFor($booking));
            } catch (CheckoutRefused|IllegalStateTransition) {
                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput()
                    ->withErrors(['checkout' => __('guest.checkout.refused')]);
            }

            $target = $result['target'];

            // BKG-19: a voucher covered the whole thing, so it is confirmed and
            // there is nowhere to send them but their own booking page.
            if ($target === null) {
                return redirect()->route('guest.booking', ['token' => $token]);
            }

            return redirect()->away($target->url);
        });
    }

    /**
     * Deposit if the booking has one, otherwise the whole thing.
     *
     * Read from the same snapshot the page renders its «you pay X now» line
     * from, so the sentence and the charge cannot disagree.
     */
    private static function kindFor(Booking $booking): PaymentKind
    {
        $deposit = (int) ($booking->price_snapshot['deposit']['amount_cents'] ?? 0);

        return $deposit > 0 && $deposit < $booking->total_cents
            ? PaymentKind::Deposit
            : PaymentKind::Full;
    }

    /**
     * Waiting to be paid for.
     *
     * `pending_payment` counts as well as `draft`: a guest who reached the
     * gateway, changed their mind and pressed Back has a booking in that state
     * and a hold that has not expired, and sending them to a "your booking"
     * page they cannot pay from is the worst of both.
     */
    private static function isPayable(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Draft, BookingStatus::PendingPayment], true);
    }

    /** @return array{0: Booking|null, 1: Tenant|null} */
    private function resolve(string $token): array
    {
        $booking = GuestTokenResolver::booking($token);

        return [$booking, $booking === null ? null : GuestTokenResolver::tenantOf($booking->tenant_id)];
    }
}
