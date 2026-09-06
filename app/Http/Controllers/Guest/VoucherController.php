<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Support\GuestTokenResolver;
use App\Enums\ProductStatus;
use App\Http\Middleware\ThrottleTokenLookups;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/v/{voucher}` — a balance, and nothing about anybody (spec TOK-12, TOK-2).
 *
 * ## What this page must not say
 *
 * TOK-12 ends with the requirement in capitals: *"It **MUST NOT** reveal the
 * booking or guest it was issued for."*
 *
 * A voucher code is short enough to read down a telephone and travels by
 * forwarded email; the person holding one is often not the person it was issued
 * to. `vouchers.issued_for_booking_id` therefore never reaches the view, and
 * neither does anything that could be worked back to it — not the reference,
 * not a date, not the trip it came from. `VoucherPageTest` asserts that over
 * the **whole rendered body** rather than over a field list, because the way
 * this leaks is a helpful addition three months from now.
 *
 * ## The code *is* the credential, and it is shorter than the others
 *
 * TOK-2 singles this case out: *"`/v/{voucher}` uses the voucher code itself,
 * which is therefore also generated with sufficient entropy and is rate-limited
 * harder."* The compensation for a code somebody can say out loud is the
 * failure limiter in {@see ThrottleTokenLookups} — ten
 * wrong guesses a minute per address, which makes an eight-character alphabet
 * of 36 impractical to walk rather than merely large.
 *
 * ## Expired and spent vouchers still render
 *
 * A guest holding a voucher that has run out needs to be told that, in a
 * sentence, on the page. A 404 would be indistinguishable from a mistyped code
 * and would send them to the operator's phone.
 */
final class VoucherController extends GuestPageController
{
    public function show(Request $request, string $code): Response
    {
        $voucher = GuestTokenResolver::voucher($code);
        $tenant = $voucher === null ? null : GuestTokenResolver::tenantOf($voucher->tenant_id);

        if (! $voucher instanceof Voucher || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        // No booking locale to read: this page has no booking, by design. The
        // tenant's default, overridable by `?lang=` like every other token page.
        $locale = $this->resolveLocale($request, $tenant->default_locale);

        return $this->renderInTenant($tenant, 'guest.voucher', fn (): array => [
            'brand' => $this->brandFor($tenant, $locale),
            // The model is passed and the view renders four fields off it. The
            // test asserts the *body*, which is what stops a future field from
            // quietly becoming a leak.
            'voucher' => $voucher,
            'spendable' => $voucher->isSpendable(),
            'products' => $this->eligibleProducts(),
        ]);
    }

    /**
     * TOK-12's "eligible products".
     *
     * The operator's live catalogue, which is what a voucher can be spent
     * against — a list of trips, with nothing on it about who bought what.
     * Deliberately not "products this voucher has been used on", which would be
     * exactly the history TOK-12 forbids.
     *
     * @return Collection<int, Product>
     */
    private function eligibleProducts(): Collection
    {
        return Product::query()
            ->where('status', ProductStatus::Active->value)
            ->orderBy('sort_order')
            ->limit(20)
            ->get();
    }
}
