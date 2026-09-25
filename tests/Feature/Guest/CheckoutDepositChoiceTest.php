<?php

declare(strict_types=1);

use App\Enums\BalanceCollection;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| «Προκαταβολή ή όλο το ποσό» on `/c/{token}` (Mike, 2026-09-25; plan Β4)
|--------------------------------------------------------------------------
|
| A booking with a deposit offers two choices: the deposit now with the
| balance's date said beside it (or «στο σκάφος»), and the whole amount. The
| deposit is preselected. What matters most is that the choice is what the
| gateway is asked for — a page that says one amount and charges another is the
| one thing a checkout may never do.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A €120 draft with a €36 deposit, sailing on 1 August at 10:00 in Athens.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function checkoutChoiceDraft(BalanceCollection $collection = BalanceCollection::Online, int $depositCents = 3600): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    $tenant->forceFill([
        'deposits_enabled' => true,
        'balance_collection' => $collection,
        'balance_due_days_before_departure' => 14,
    ])->save();

    Tenancy::forTenant($tenant, static function () use ($booking, $depositCents): void {
        // The scenario's settled charge belongs to a confirmed booking; this
        // one has paid nothing yet.
        Payment::query()->where('booking_id', $booking->getKey())->delete();

        $booking->forceFill([
            'status' => BookingStatus::Draft,
            'deposit_cents' => $depositCents,
            'paid_cents' => 0,
            'balance_cents' => 12000,
            'starts_at_utc' => Carbon::parse('2026-08-01 07:00:00'),
            'ends_at_utc' => Carbon::parse('2026-08-01 11:00:00'),
            'hold_expires_at' => now()->addMinutes(15),
            // No rate plan override: the operator's fourteen days decide.
            'price_snapshot' => [...(array) $booking->price_snapshot, 'rate_plan_id' => null],
        ])->save();
    });

    return [$tenant, $booking->refresh()];
}

/** @return array<string, string> */
function depositCheckoutForm(?string $kind): array
{
    return array_filter([
        'guest_name' => 'Δοκιμή Δοκιμή',
        'guest_email' => 'guest@example.com',
        'terms' => '1',
        'kind' => $kind,
    ], static fn (?string $value): bool => $value !== null);
}

function checkoutChoiceCharge(Tenant $tenant, Booking $booking): Payment
{
    return Tenancy::forTenant($tenant, fn (): Payment => Payment::query()
        ->where('booking_id', $booking->getKey())
        ->where('status', PaymentStatus::Pending->value)
        ->latest('id')
        ->firstOrFail());
}

it('offers the deposit, preselected, with the balance date, and the whole amount', function (): void {
    [, $booking] = checkoutChoiceDraft();

    // Fourteen local days before 1 August, at 09:00 in Athens.
    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('Προκαταβολή 36,00', escape: false)
        // The formatter puts a no-break space before the euro sign.
        ->assertSee("84,00\u{a0}€ έως 18/7", escape: false)
        ->assertSee('Όλο το ποσό 120,00', escape: false)
        ->assertSee('value="deposit" form="checkout-form" id="kind-deposit" checked', escape: false)
        ->assertDontSee('id="kind-full" checked', escape: false);
})->group('fast');

it('says «στο σκάφος» when the operator collects the balance on the boat', function (): void {
    [, $booking] = checkoutChoiceDraft(BalanceCollection::OnBoard);

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee("84,00\u{a0}€ στο σκάφος", escape: false)
        ->assertDontSee('έως 18/7', escape: false);
})->group('fast');

it('offers no choice when the booking has no deposit', function (): void {
    [, $booking] = checkoutChoiceDraft(depositCents: 0);

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertDontSee('name="kind"', escape: false)
        ->assertSee('Πληρωμή 120,00', escape: false);
})->group('fast');

it('charges the deposit when the guest keeps it', function (): void {
    [$tenant, $booking] = checkoutChoiceDraft();

    post('/c/' . $booking->manage_token, depositCheckoutForm('deposit'))->assertRedirect();

    $payment = checkoutChoiceCharge($tenant, $booking);

    expect($payment->kind)->toBe(PaymentKind::Deposit)
        ->and($payment->amount_cents)->toBe(3600);
})->group('fast');

it('charges the whole amount when the guest chooses it', function (): void {
    [$tenant, $booking] = checkoutChoiceDraft();

    post('/c/' . $booking->manage_token, depositCheckoutForm('full'))->assertRedirect();

    $payment = checkoutChoiceCharge($tenant, $booking);

    expect($payment->kind)->toBe(PaymentKind::Full)
        ->and($payment->amount_cents)->toBe(12000);
})->group('fast');

it('charges the deposit when the page sent no choice', function (): void {
    // A page rendered before the choice existed, still open in a tab.
    [$tenant, $booking] = checkoutChoiceDraft();

    post('/c/' . $booking->manage_token, depositCheckoutForm(null))->assertRedirect();

    expect(checkoutChoiceCharge($tenant, $booking)->amount_cents)->toBe(3600);
})->group('fast');

it('refuses a kind that is not one of the two', function (): void {
    [, $booking] = checkoutChoiceDraft();

    post('/c/' . $booking->manage_token, depositCheckoutForm('balance'))
        ->assertSessionHasErrors('kind');
})->group('fast');
