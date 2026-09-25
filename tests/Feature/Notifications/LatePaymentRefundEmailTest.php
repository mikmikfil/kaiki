<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentStatus;
use App\Events\LatePaymentRefunded;
use App\Jobs\ExecuteGatewayRefund;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| «Η πληρωμή σας επιστρέφεται» (2026-09-25)
|--------------------------------------------------------------------------
|
| A payment that lands when the booking can no longer take it goes back on
| its own. The operator is told under «Χρειάζονται προσοχή»; the guest, who
| was charged, is told by email, with the reason, once per refund.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 10:00:00');
    Queue::fake([ExecuteGatewayRefund::class]);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<GuestMail> */
function refundMails(): array
{
    return Mail::sent(GuestMail::class, static fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::PaymentRefunded)
        ->values()
        ->all();
}

it('tells the guest a payment on a cancelled booking is going back, once', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Payment::query()->update(['status' => PaymentStatus::Cancelled->value]);
        $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()])->save();
    });

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));
    // A replay writes no second refund, and so sends no second email.
    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    $mails = refundMails();

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->extra['reason'])->toBe(LatePaymentRefunded::REASON_CANCELLED)
        ->and($mails[0]->extra['amount_cents'])->toBe(12000);

    $html = Tenancy::forTenant($tenant, fn (): string => $mails[0]->render());

    expect($html)->toContain('Η πληρωμή σας επιστρέφεται')
        ->and($html)->toContain('Η κράτηση είχε ήδη ακυρωθεί.')
        ->and($html)->toContain('120,00');
})->group('fast');

it('says the seats were gone when the booking had expired', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make(capacity: 10);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 10]);
        Payment::query()->update(['status' => PaymentStatus::Cancelled->value]);
        $booking->forceFill([
            'status' => BookingStatus::Expired,
            'cancel_reason' => CancelReason::PaymentFailed,
            'hold_expires_at' => null,
        ])->save();
    });

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    $mails = refundMails();

    expect($mails)->toHaveCount(1)
        ->and($mails[0]->extra['reason'])->toBe(LatePaymentRefunded::REASON_EXPIRED)
        ->and(Tenancy::forTenant($tenant, fn (): string => $mails[0]->render()))->toContain('Ο χρόνος πληρωμής έληξε');
})->group('fast');

it('sends nothing when no money has to go back', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make(capacity: 10);

    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    expect(Tenancy::forTenant($tenant, fn (): BookingStatus => Booking::query()->findOrFail($booking->getKey())->status))
        ->toBe(BookingStatus::Confirmed)
        ->and(refundMails())->toBe([]);
})->group('fast');
