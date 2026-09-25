<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CollectBalanceOnBoard;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingSource;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Exceptions\HoldRefused;
use App\Filament\App\Pages\Calendar;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Booking\CheckInScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A read-only account sells nothing and records no money (TEN-9, audit 2)
|--------------------------------------------------------------------------
|
| The panel's policies refused a read-only tenant's writes, but «Πώληση τώρα»
| and «Πληρώθηκε» went straight to their Actions: a lapsed account could still
| write a confirmed, paid booking and a payment row. The refusal now sits in
| the doors themselves — the draft every new booking passes, and the manual
| payment every hand-recorded payment passes — and the buttons are hidden.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refuses a quay or phone booking on a read-only account, and hides «Πώληση τώρα»', function (): void {
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::now()->addHours(2)->startOfMinute());
    $crew = OperatorUser::withRole(Role::Crew, $fixture['tenant']);

    $fixture['tenant']->forceFill(['status' => TenantStatus::ReadOnly])->save();

    Tenancy::forTenant($fixture['tenant']->refresh(), function () use ($fixture): void {
        $data = new BookingDraftData(
            product: $fixture['product'],
            date: $fixture['departure']->local_date->copy(),
            guestName: 'Μαρία Κ.',
            guestEmail: null,
            paxByCode: ['adult' => 2],
            departure: $fixture['departure'],
        );

        expect(fn () => app(CreateManualBooking::class)($data, paidBy: PaymentGatewayName::Cash, source: BookingSource::Quay))
            ->toThrow(HoldRefused::class, (string) __('errors.tenant_read_only'));

        expect(Booking::query()->count())->toBe(0)
            ->and(Payment::query()->count())->toBe(0);
    });

    tenancy()->initialize($fixture['tenant']);

    Livewire::actingAs($crew)->test(Calendar::class)->assertActionHidden('sell');
})->group('fast');

it('records no payment by hand or on the boat on a read-only account', function (): void {
    Carbon::setTestNow('2026-07-03 08:45:00');

    [$tenant, $booking] = CheckInScenario::sailing(Carbon::parse('2026-07-03 09:00:00'));

    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['total_cents' => 12000, 'paid_cents' => 0, 'balance_cents' => 12000])->save());

    $crew = OperatorUser::withRole(Role::Crew, $tenant);
    $tenant->forceFill(['status' => TenantStatus::ReadOnly])->save();

    actingAs($crew);

    Tenancy::forTenant($tenant->refresh(), function () use ($booking, $crew): void {
        expect(CollectBalanceOnBoard::offeredTo($crew, $booking))->toBeFalse()
            ->and(RecordManualPayment::refusalFor($booking))->toBe('errors.tenant_read_only');

        expect(fn () => app(CollectBalanceOnBoard::class)($booking, PaymentGatewayName::Cash, $crew))
            ->toThrow(ValidationException::class);

        expect(fn () => app(RecordManualPayment::class)($booking, 5000, PaymentGatewayName::Cash))
            ->toThrow(ValidationException::class);

        expect(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
})->group('fast');
