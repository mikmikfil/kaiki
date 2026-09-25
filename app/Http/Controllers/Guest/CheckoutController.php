<?php

declare(strict_types=1);

namespace App\Http\Controllers\Guest;

use App\Domain\Booking\Actions\ComputeBalanceDueAt;
use App\Domain\Booking\Actions\MintCheckoutSession;
use App\Domain\Booking\Actions\ResumeAbandonedBooking;
use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Domain\Booking\Support\GuestTokenResolver;
use App\Domain\Booking\Support\PassengerForm;
use App\Domain\Booking\Support\PolicyExplanation;
use App\Domain\Booking\Support\TripQuestionForm;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Hosted\Support\HostedUrl;
use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\TripQuestionScope;
use App\Exceptions\CheckoutRefused;
use App\Exceptions\DiscountCodeRefused;
use App\Exceptions\HoldRefused;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
 * The lead booker always. The passengers **only when the trip requires them**
 * (`products.guest_details_required`), which is the flag the catalogue already
 * carries for exactly this. A three-hour sunset cruise stays four fields; the
 * manifest is asked for where the coastguard actually wants one.
 *
 * What each passenger is asked for — name, nationality, date of birth, and a
 * passport or identity card unless their band is «Χωρίς έγγραφο» — is
 * {@see PassengerForm}'s (2026-09-17).
 */
final class CheckoutController extends GuestPageController
{
    public function __construct(
        GetBrandPayload $brand,
        private readonly MintCheckoutSession $mintSession,
        private readonly SaveGuestDetails $saveGuests,
        private readonly ApplyDiscountCode $applyCode,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request, string $token): Response
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant) {
            return $this->linkNotValid($request);
        }

        // The abandoned-payment email's button (2026-09-24): a fresh booking
        // with the same party, checked again, and its checkout.
        if (ResumeAbandonedBooking::applies($booking)) {
            return $this->resume($request, $booking, $tenant, $token);
        }

        // A booking that is no longer waiting to be paid for has a better page
        // than this one, and it is the page the same token opens.
        if (! self::isPayable($booking)) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        $locale = $this->resolveLocale($request, $booking->locale);

        return $this->renderInTenant($tenant, 'guest.checkout', fn (): array => [
            'brand' => $this->brandFor($tenant, $locale),
            'booking' => $booking->load(['product', 'departure']),
            'token' => $token,
            'needsGuestDetails' => (bool) $booking->product?->guest_details_required,
            // One row per person, created if the booking has none yet, each
            // knowing its band and whether that band carries a document. Also
            // when the trip asks a per-person question and no documents: the
            // panel then holds a name and the questions (2026-09-17).
            'passengers' => self::asksPassengers($booking)
                ? PassengerForm::rows($booking, $locale)
                : [],
            'bookingQuestions' => TripQuestionForm::scoped(TripQuestionForm::questionsFor($booking), TripQuestionScope::PerBooking),
            'personQuestions' => TripQuestionForm::scoped(TripQuestionForm::questionsFor($booking), TripQuestionScope::PerPerson),
            'givenAnswers' => TripQuestionForm::answersOf($booking),
            // «Κουπόνι» (2026-09-17): what the snapshot says was applied.
            'discountCode' => data_get($booking->price_snapshot, 'discount_code'),

            // **The operator's timezone, not the application's.** Everything is
            // stored UTC (CLAUDE.md), and `config('app.timezone')` is therefore
            // UTC — so the hold sentence was printing 07:32 to a guest standing
            // on a quay at 10:52. Every other guest-facing time on the site
            // goes through the tenant's zone; this one was the exception.
            'timezone' => $tenant->timezone ?: (string) config('kaiki.defaults.timezone'),
            'backUrl' => $this->backToSiteUrl($booking, $tenant),

            // The page the consent line points at. A guest ticking a box for
            // terms they have no way to read is not consenting to anything,
            // and the page has existed for every operator since ADR-0029
            // retired the `off` tier for exactly this reason.
            'legalUrl' => HostedUrl::legal($tenant, $locale),

            // «Προκαταβολή ή όλο το ποσό» (Mike, 2026-09-25). Null when the
            // booking has no deposit, and the page then charges the total.
            'payChoice' => self::payChoice($booking, $tenant),

            /*
             * The cancellation policy, in one sentence, at the moment it is
             * being decided on.
             *
             * From `policy_snapshot`, which `CreateBookingDraft` already froze
             * onto this draft — the same copy refunds are computed from
             * (brief §5.9), so what the guest is shown and what they would get
             * back cannot drift apart. Null when the product carries no policy
             * at all, and then the block is simply absent rather than reassuring
             * somebody with a blank.
             */
            'policySummary' => $this->policySummary($booking, $locale),
            // The whole ladder, for the «Πολιτική ακύρωσης» window beside the
            // summary (2026-09-17). From the same frozen snapshot.
            'policyLines' => PolicyExplanation::lines($booking->policy_snapshot, $locale),
        ]);
    }

    /**
     * One locale's sentence out of the frozen policy snapshot.
     *
     * `summary` is stored per locale (§3.3). A snapshot written before the
     * guest's language existed on the tenant falls back to the other one rather
     * than showing nothing — a policy in the wrong language is still a policy,
     * and silence here is the failure this was added to fix.
     */
    private function policySummary(Booking $booking, string $locale): ?string
    {
        $summary = data_get($booking->policy_snapshot, 'summary');

        if (! is_array($summary)) {
            return null;
        }

        $text = $summary[$locale] ?? collect($summary)->first(
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }

    /**
     * Where a guest lands after a payment that did not go through.
     *
     * Back on this page with a sentence saying so, while the booking can still
     * be paid for — the details they typed are already on the booking, so the
     * form refills and «try again» is one press (product owner, 2026-09-11).
     * Their booking page otherwise: BKG-12 expires a booking whose seats went
     * while the guest was failing to pay, and a checkout for it would only
     * bounce them there anyway.
     *
     * One method because two places send a guest back from a gateway — the
     * sandbox page and Viva's Failure URL — and a guest must not be able to
     * tell which one they were on by where they end up.
     */
    public static function returnAfterFailedPayment(Booking $booking): RedirectResponse
    {
        if (! self::isPayable($booking)) {
            return redirect()->route('guest.booking', ['token' => $booking->manage_token]);
        }

        return redirect()
            ->route('guest.checkout', ['token' => $booking->manage_token])
            // In the booking's own locale (TOK-5): this request came from a
            // gateway, which says nothing useful about the guest's language.
            ->withErrors(['checkout' => __('guest.checkout.payment_failed', [], $booking->locale)]);
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
        // **The guest's language, set before anything can fail.**
        //
        // `show()` resolves the locale and renders in it; this method never did,
        // so every sentence it produces came out in the fallback language. A
        // guest who filled a Greek page in Greek, pressed «Πληρωμή» and hit a
        // refusal was answered in English on the same Greek page — which reads
        // as a different site's error rather than as this one's.
        app()->setLocale($this->resolveLocale($request, $booking->locale));

        return Tenancy::forTenant($tenant, function () use ($booking, $request, $token): RedirectResponse {
            $needsGuests = (bool) $booking->product?->guest_details_required;
            $asksPassengers = self::asksPassengers($booking);
            $questions = TripQuestionForm::questionsFor($booking);

            $rules = [
                'guest_name' => ['required', 'string', 'max:120'],
                'guest_email' => ['required', 'email', 'max:190'],
                'guest_phone' => ['nullable', 'string', 'max:32'],
                'special_requests' => ['nullable', 'string', 'max:1000'],
                // Not `accepted` alone: the checkbox is the evidence recorded in
                // `terms_accepted_at` beside the IP (§2.5), so it has to be present
                // rather than merely truthy.
                'terms' => ['accepted'],
                // The guest's choice beside the price (2026-09-25). Optional:
                // a page rendered before the choice existed posts none.
                'kind' => ['nullable', 'string', 'in:deposit,full'],
            ];

            if ($needsGuests) {
                // Required, not optional. `guest_details_required` is set on a
                // trip precisely because the λιμεναρχείο asks for a passenger
                // list; what depends on the passenger's band and document is
                // checked after the shape, in `PassengerForm::check()`.
                $rules = [...$rules, ...PassengerForm::rules()];
            } elseif ($asksPassengers) {
                // Per-person questions on a trip that asks for no documents:
                // a name for each panel, and the answers.
                $rules['guests'] = ['required', 'array', 'min:1'];
                $rules['guests.*.position'] = ['required', 'integer', 'min:1'];
                $rules['guests.*.full_name'] = ['required', 'string', 'max:120'];
            }

            [$questionRules, $questionNames] = TripQuestionForm::rules(
                $questions,
                array_keys((array) $request->input('guests', [])),
            );

            $validator = ValidatorFactory::make(
                $request->all(),
                [...$rules, ...$questionRules],
                [],
                [...($needsGuests ? PassengerForm::attributes($request->all()) : []), ...$questionNames],
            );

            if ($needsGuests) {
                $validator->after(static fn (Validator $v) => PassengerForm::check($v, $booking));
            }

            $data = $validator->validate();

            $booking->forceFill([
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'],
                'guest_phone' => $data['guest_phone'] ?? null,
                'special_requests' => $data['special_requests'] ?? null,
                'terms_accepted_at' => now(),
                'ip_address' => $request->ip(),
            ])->save();

            if ($asksPassengers) {
                // The same Action `/g/{token}` uses, so the manifest is written
                // one way whether it is filled in here or afterwards.
                ($this->saveGuests)($booking, $data['guests']);
            }

            if ($questions->isNotEmpty()) {
                TripQuestionForm::save($booking, (array) $request->input('answers', []), (array) $request->input('guests', []));
            }

            // «Κουπόνι» (2026-09-17): a code that ran out or expired while the
            // guest was filling this in comes off, and they are shown the new
            // total before the gateway rather than charged it.
            if (! $this->applyCode->recheck($booking)) {
                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput()
                    ->withErrors(['discount_code' => __('discount_codes.refused.no_longer')]);
            }

            try {
                // Whatever the page said it would charge. A deposit product
                // shows «πληρώνετε X τώρα και Y πριν την αναχώρηση» beside the
                // button, and charging the full total after that is the page
                // lying about money — which is the one thing a checkout may
                // never do. `MintCheckoutSession` refuses a deposit that does
                // not exist, so the fallback is the total.
                $result = ($this->mintSession)($booking, self::kindFor($booking, $data['kind'] ?? null));
            } catch (DiscountCodeRefused $refused) {
                // Somebody else spent the last use between this page and the
                // lock inside `StartCheckout`. The code comes off and the guest
                // sees the new total before paying it — the same answer as the
                // check above, arrived at a second later.
                ($this->applyCode)($booking, null);

                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput()
                    ->withErrors(['discount_code' => $refused->getMessage()]);
            } catch (CheckoutRefused|IllegalStateTransition $refused) {
                // Too late is said as such (2026-09-25); everything else is the
                // general sentence.
                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput()
                    ->withErrors(['checkout' => $refused instanceof CheckoutRefused && $refused->errorCode === 'lead_time_too_short'
                        ? $refused->getMessage()
                        : __('guest.checkout.refused')]);
            } catch (HoldRefused $refused) {
                // A charter whose hold lapsed on this page while somebody else
                // took the boat (2026-09-25). Its own sentence, and no charge.
                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput()
                    ->withErrors(['checkout' => $refused->getMessage()]);
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
     * `POST /c/{token}/code` — put a discount code on the draft, or take it off.
     *
     * Its own small form beside the price rather than part of the payment
     * form: a guest applies a code to see the new total, and pressing «Εφαρμογή»
     * must not be taken as pressing «Πληρωμή».
     */
    public function code(Request $request, string $token): RedirectResponse
    {
        [$booking, $tenant] = $this->resolve($token);

        if ($booking === null || ! $tenant instanceof Tenant || ! self::isPayable($booking)) {
            return redirect()->route('guest.booking', ['token' => $token]);
        }

        app()->setLocale($this->resolveLocale($request, $booking->locale));

        $typed = $request->boolean('remove') ? null : (string) $request->input('discount_code', '');

        return Tenancy::forTenant($tenant, function () use ($booking, $typed, $token): RedirectResponse {
            try {
                $applied = ($this->applyCode)($booking, $typed);
            } catch (DiscountCodeRefused $refused) {
                return redirect()
                    ->route('guest.checkout', ['token' => $token])
                    ->withInput(['discount_code' => $typed])
                    ->withErrors(['discount_code' => $refused->getMessage()]);
            }

            return redirect()
                ->route('guest.checkout', ['token' => $token])
                ->with('discount_status', __($applied === null ? 'discount_codes.checkout.removed' : 'discount_codes.checkout.applied'));
        });
    }

    /** Does checkout show a panel per passenger? */
    private static function asksPassengers(Booking $booking): bool
    {
        return (bool) $booking->product?->guest_details_required
            || TripQuestionForm::scoped(TripQuestionForm::questionsFor($booking), TripQuestionScope::PerPerson)->isNotEmpty();
    }

    /**
     * What the guest chose, when there was a choice; otherwise the deposit if
     * the booking has one, and the whole thing if not.
     *
     * `full` is always honoured — paying everything is never wrong. `deposit`
     * only when there is one: a stale page posting it for a booking whose
     * deposit went away is charged the total the page will now show.
     */
    private static function kindFor(Booking $booking, ?string $chosen = null): PaymentKind
    {
        if ($chosen === 'full') {
            return PaymentKind::Full;
        }

        return self::depositCents($booking) !== null
            ? PaymentKind::Deposit
            : PaymentKind::Full;
    }

    /**
     * The deposit this booking would be charged, or null when it has none.
     *
     * `deposit_cents` on the booking rather than the snapshot's figure: it is
     * the column `StartCheckout` and `MintCheckoutSession` charge, so the choice
     * on the page and the amount at the gateway are one number.
     */
    private static function depositCents(Booking $booking): ?int
    {
        return $booking->deposit_cents > 0 && $booking->deposit_cents < $booking->total_cents
            ? $booking->deposit_cents
            : null;
    }

    /**
     * «Προκαταβολή €X τώρα, €Y έως 12/10» or «Όλο το ποσό €Z» (2026-09-25).
     *
     * The due date is {@see ComputeBalanceDueAt}'s, asked about this booking as
     * if its deposit were paid now — the date it will actually get at
     * confirmation. With the balance paid on the boat there is no date, and
     * the page says «στο σκάφος».
     *
     * @return array{deposit: int, balance: int, total: int, due: Carbon|null, on_board: bool}|null
     */
    public static function payChoice(Booking $booking, Tenant $tenant): ?array
    {
        $deposit = self::depositCents($booking);

        if ($deposit === null) {
            return null;
        }

        $balance = $booking->total_cents - $deposit;
        $onBoard = $tenant->collectsBalanceOnBoard();

        $probe = clone $booking;
        $probe->balance_cents = $balance;

        return [
            'deposit' => $deposit,
            'balance' => $balance,
            'total' => $booking->total_cents,
            'due' => $onBoard ? null : app(ComputeBalanceDueAt::class)($probe, Carbon::now()),
            'on_board' => $onBoard,
        ];
    }

    /**
     * Waiting to be paid for.
     *
     * `pending_payment` counts as well as `draft`: a guest who reached the
     * gateway, changed their mind and pressed Back has a booking in that state
     * and a hold that has not expired, and sending them to a "your booking"
     * page they cannot pay from is the worst of both.
     *
     * Public since the gateway return pages ask the same question — see
     * {@see self::returnAfterFailedPayment()}.
     */
    public static function isPayable(Booking $booking): bool
    {
        return in_array($booking->status, [BookingStatus::Draft, BookingStatus::PendingPayment], true);
    }

    /**
     * Start again from an abandoned checkout, or say plainly why not.
     *
     * A draft goes to its own checkout; a booking already paid for goes to its
     * booking page; a boat that filled or a code that ran out goes back to the
     * old booking's page with the reason.
     */
    private function resume(Request $request, Booking $booking, Tenant $tenant, string $token): Response
    {
        app()->setLocale($this->resolveLocale($request, $booking->locale));

        try {
            $fresh = Tenancy::forTenant($tenant, fn (): Booking => app(ResumeAbandonedBooking::class)($booking));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('guest.booking', ['token' => $token])
                ->with('resume_refused', __('guest.booking.resume_refused'));
        }

        return self::isPayable($fresh)
            ? redirect()->route('guest.checkout', ['token' => $fresh->manage_token])
            : redirect()->route('guest.booking', ['token' => $fresh->manage_token]);
    }

    /** @return array{0: Booking|null, 1: Tenant|null} */
    private function resolve(string $token): array
    {
        $booking = GuestTokenResolver::booking($token);

        return [$booking, $booking === null ? null : GuestTokenResolver::tenantOf($booking->tenant_id)];
    }
}
