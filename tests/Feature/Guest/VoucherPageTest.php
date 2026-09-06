<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| TOK-12: a balance, and nothing about anybody
|--------------------------------------------------------------------------
|
| > *It MUST NOT reveal the booking or guest it was issued for.*
|
| A voucher code is short enough to read down a telephone and travels by
| forwarded email; the person holding one is often not the person it was issued
| to. So the assertion here is over the **whole rendered body** rather than over
| a field list — the way this leaks is not a field somebody puts on the page
| today, it is a helpful addition three months from now.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the code, the amounts and the expiry', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->create([
        'code' => 'GIFT-TESTCODE',
        'amount_cents' => 15000,
        'remaining_cents' => 9000,
        'expires_at' => Carbon::parse('2027-01-31'),
    ]));

    get('/v/' . $voucher->code)
        ->assertOk()
        ->assertSee('GIFT-TESTCODE')
        ->assertSee('150,00')
        ->assertSee('90,00')
        ->assertSee('31/01/2027');
});

it('reveals nothing about the booking or the guest it was issued for', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->create([
        'issued_for_booking_id' => $booking->getKey(),
        'notes' => 'Weather cancellation, Γιώργος Νικολάου',
    ]));

    $body = get('/v/' . $voucher->code)->assertOk()->getContent();

    // TOK-12, asserted over the whole page. The reference, the guest's name and
    // their email are all one careless `{{ $voucher->issuedForBooking->… }}`
    // away, and a field-by-field assertion would not notice.
    $leaks = [
        $booking->reference,
        $booking->guest_name,
        $booking->guest_email,
        (string) $booking->uuid,
        // Not even the operator's own note, which carries a guest's name in
        // exactly the way an operator writes one.
        'Γιώργος Νικολάου',
        $booking->manage_token,
    ];

    foreach ($leaks as $leak) {
        expect(str_contains($body, (string) $leak))->toBeFalse("the voucher page leaked: {$leak}");
    }
});

it('lists the trips it can be used on, and nothing about who used what', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, function (): Voucher {
        Product::factory()->create([
            // `title`, not `name`: §2.3's column, and the one the page renders.
            'title' => ['el' => 'Ημερήσια Δήλος', 'en' => 'Delos day trip'],
            'status' => ProductStatus::Active,
        ]);

        return Voucher::factory()->create();
    });

    // "Eligible products" is the operator's live catalogue — deliberately not
    // "products this voucher has been used on", which would be exactly the
    // history TOK-12 forbids.
    get('/v/' . $voucher->code)->assertOk()->assertSee('Ημερήσια Δήλος');
});

it('renders a spent voucher with a sentence rather than a 404', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->fullySpent()->create());

    $body = get('/v/' . $voucher->code)->assertOk()->getContent();

    // A guest holding a voucher that has run out needs to be told that. A 404
    // is indistinguishable from a mistyped code and sends them to the phone.
    expect($body)->toContain(__('guest.voucher.spent'));
});

it('renders an expired voucher with a sentence rather than a 404', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->lapsed()->create());

    $body = get('/v/' . $voucher->code)->assertOk()->getContent();

    expect($body)->toContain(__('guest.voucher.expired'));
});

it('answers a code that does not exist with the same generic page as every other failure', function (): void {
    get('/v/GIFT-NOTHINGHERE')
        ->assertStatus(404)
        ->assertSee(__('guest.link.title'));
});
