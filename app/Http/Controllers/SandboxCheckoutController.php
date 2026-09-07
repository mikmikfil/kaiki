<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a sandbox payment actually happens on (spec SAA-9, PAY-11, TST-3).
 *
 * ## Why this exists at all
 *
 * {@see FakeGateway} has always minted a redirect
 * to a host that could not resolve, which was correct while nothing was ever
 * going to follow it. Two things now do. SAA-9 ends onboarding with *"a test
 * booking in sandbox mode"* — an operator who has not yet got a merchant account
 * should be able to walk the whole flow — and issue 111's Playwright run has to
 * reach a payment and come back from it without a third-party sandbox. Both need
 * a page, and there was none.
 *
 * ## It cannot touch a live booking, and that is the whole design
 *
 * PAY-11: *sandbox mode MUST be impossible to enable accidentally on a live
 * tenant.* So this refuses on **the booking's own `is_test` flag**, which is
 * written at creation and never changes, rather than on a config switch, a
 * header, an environment check or anything else a caller could influence. A page
 * that could be talked into confirming a live booking would be a page that
 * confirms bookings nobody paid for, and its URL is guessable by construction.
 *
 * The other three refusals matter for the same reason: the reference must match
 * a payment, the payment must still be `pending`, and the gateway must be one
 * this flow could have minted. A `succeeded` payment re-confirmed is harmless
 * today only because {@see ConfirmFromWebhook} is idempotent, and depending on
 * somebody else's idempotency for your own safety is how it stops being true.
 *
 * ## It goes through the webhook's action, not around it
 *
 * BKG-11 is explicit that the webhook is the only authority for a successful
 * payment, and the seat arithmetic on both the success and failure paths — the
 * commit, the release, the re-hold when a boat filled while a guest was failing
 * to pay — lives in {@see ConfirmFromWebhook}. A sandbox that set
 * `status = confirmed` itself would be a second implementation of the one piece
 * of this system where a mistake oversells a boat.
 */
final class SandboxCheckoutController
{
    public function show(Request $request, string $reference): View
    {
        [$payment, $booking] = $this->resolve($reference);

        return view('sandbox.checkout', [
            'payment' => $payment,
            'booking' => $booking,
            'reference' => $reference,
        ]);
    }

    /** The guest pays. */
    public function pay(Request $request, string $reference): RedirectResponse
    {
        return $this->settle($reference, succeeded: true);
    }

    /**
     * The guest's card is declined.
     *
     * A sandbox that only succeeded would test half a checkout, and the failure
     * path is the one with the interesting arithmetic in it: BKG-12 hands the
     * seats back and tries to hold them again, which can fail if the boat filled
     * while the guest was failing to pay.
     */
    public function fail(Request $request, string $reference): RedirectResponse
    {
        return $this->settle($reference, succeeded: false);
    }

    private function settle(string $reference, bool $succeeded): RedirectResponse
    {
        [$payment, $booking] = $this->resolve($reference);

        $tenant = Tenancy::withoutTenancy(
            static fn (): ?Tenant => Tenant::query()->find($payment->tenant_id),
        );

        abort_if($tenant === null, Response::HTTP_NOT_FOUND);

        Tenancy::forTenant($tenant, static function () use ($payment, $succeeded): void {
            app(ConfirmFromWebhook::class)($payment, succeeded: $succeeded);
        });

        // Whatever the widget sent with the checkout request, validated against
        // the key's allowed origins before it was stored. Falling back to the
        // guest's own booking page rather than to nowhere: an operator running
        // SAA-9's test booking from the panel has no host page to return to.
        return redirect()->away($payment->return_url ?? route('guest.booking', ['token' => $booking->manage_token]));
    }

    /**
     * The four refusals, in one place so `show` and `settle` cannot disagree.
     *
     * @return array{0: Payment, 1: Booking}
     */
    private function resolve(string $reference): array
    {
        /** @var Payment|null $payment */
        $payment = Tenancy::withoutTenancy(static fn (): ?Payment => Payment::query()
            ->withoutGlobalScopes()
            ->where('gateway_ref', $reference)
            ->first());

        abort_if($payment === null, Response::HTTP_NOT_FOUND);

        /** @var Booking|null $booking */
        $booking = Tenancy::withoutTenancy(static fn (): ?Booking => Booking::query()
            ->withoutGlobalScopes()
            ->find($payment->booking_id));

        abort_if($booking === null, Response::HTTP_NOT_FOUND);

        // The load-bearing line in this file. `is_test` is written when the
        // booking is created, from the key that created it, and never changes.
        abort_unless($booking->is_test, Response::HTTP_NOT_FOUND);

        abort_unless($payment->status === PaymentStatus::Pending, Response::HTTP_NOT_FOUND);

        // The fake gateway mints its sessions under the operator's default
        // gateway name, so this is a sanity check rather than a filter — but a
        // `cash` payment reaching a checkout page would mean something is very
        // wrong upstream.
        abort_unless($payment->gateway->isExternal(), Response::HTTP_NOT_FOUND);

        return [$payment, $booking];
    }
}
