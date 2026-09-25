<?php

declare(strict_types=1);

use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| Guest pages and emails name days on the operator's clock (CNV-2)
|--------------------------------------------------------------------------
|
| Every `*_at` is stored in UTC. Between 00:00 and 03:00 in Athens the UTC
| date is the day before, and a page that printed it raw named the wrong day.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('dates a voucher expiry in the tenant timezone', function (): void {
    [$tenant] = GuestPageScenario::booking();

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->create([
        'code' => 'GIFT-NIGHT',
        // 00:30 on 1 February in Athens.
        'expires_at' => Carbon::parse('2027-01-31 22:30:00', 'UTC'),
    ]));

    get('/v/' . $voucher->code)
        ->assertOk()
        ->assertSee('01/02/2027')
        ->assertDontSee('31/01/2027');
})->group('fast');

it('names the passenger-list deadline in the confirmation, on the tenant clock', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true);

    $html = Tenancy::forTenant($tenant, function () use ($booking): string {
        $booking->product->forceFill(['guest_details_deadline_hours' => 48])->save();

        // 01:30 on 19 July in Athens; the list is due 48 hours earlier, at
        // 01:30 on the 17th, which is still the 16th in UTC.
        $booking->forceFill([
            'starts_at_utc' => Carbon::parse('2026-07-18 22:30:00', 'UTC'),
            'guest_details_deadline_at' => null,
        ])->save();

        return (new GuestMail(Booking::query()->findOrFail($booking->getKey()), NotificationTemplate::BookingConfirmed))->render();
    });

    expect($html)->toContain(__('mail.common.details_by', ['date' => '17/7'], 'el'));
})->group('fast');
