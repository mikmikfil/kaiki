<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ComputeBalanceDueAt;
use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\ExpireAbandonedCheckouts;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Notifications\Actions\SendDueReminders;
use App\Domain\Operations\Support\AttentionItems;
use App\Enums\BalanceCollection;
use App\Enums\BookingStatus;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Filament\App\Pages\PaymentSettings;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Mail\GuestMail;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Where the balance is collected (Mike, 2026-09-25)
|--------------------------------------------------------------------------
|
| A per-operator setting: «Online με κάρτα», as it always worked, or «Στο
| σκάφος, την ημέρα» — no due date, no reminders, nothing overdue until the
| boat has sailed. And a phone booking can be confirmed on a deposit or on
| nothing at all, which is what most Greek operators actually do.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A confirmed booking with a balance, for an operator who collects it on board.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function onBoardBooking(int $paidCents = 4000, int $balanceCents = 8000): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: $paidCents, balanceCents: $balanceCents);

    $tenant->forceFill(['deposits_enabled' => true, 'balance_collection' => BalanceCollection::OnBoard])->save();

    return [$tenant->refresh(), $booking];
}

/**
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $payment
 */
function phoneBooking(array $fixture, array $payment): Booking
{
    return BookingResource::createFromForm([
        'product_id' => $fixture['product']->getKey(),
        'departure_id' => $fixture['departure']->getKey(),
        'guest_name' => 'Γιώργος Νικολάου',
        'guest_email' => 'giorgos@example.gr',
        'pax' => [['code' => 'adult', 'qty' => 2]],
        ...$payment,
    ]);
}

/*
| The setting itself
*/

it('collects online unless the operator says otherwise', function (): void {
    $tenant = Tenant::factory()->create()->refresh();

    expect($tenant->balance_collection)->toBe(BalanceCollection::Online)
        ->and($tenant->collectsBalanceOnBoard())->toBeFalse()
        ->and(Tenant::factory()->collectingBalanceOnBoard()->create()->refresh()->collectsBalanceOnBoard())->toBeTrue()
        ->and(BalanceCollection::OnBoard->labelIn('el'))->toBe('Στο σκάφος, την ημέρα')
        ->and(BalanceCollection::Online->labelIn('el'))->toBe('Online με κάρτα')
        ->and(BalanceCollection::OnBoard->labelIn('en'))->toBe('On board, on the day');
})->group('fast');

it('saves «Στο σκάφος» from the payment settings and hides the days before', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);

    $page = Livewire::actingAs($owner)->test(PaymentSettings::class);

    // Deposits off: there is no balance, so nothing to say about it.
    $page->assertFormFieldIsHidden('balance_collection');

    $page->fillForm(['deposits_enabled' => true, 'balance_collection' => BalanceCollection::Online->value])
        ->assertFormFieldIsVisible('balance_collection')
        ->assertFormFieldIsVisible('balance_due_days_before_departure')
        ->fillForm(['balance_collection' => BalanceCollection::OnBoard->value])
        ->assertFormFieldIsHidden('balance_due_days_before_departure')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->balance_collection)->toBe(BalanceCollection::OnBoard)
        ->and($tenant->deposits_enabled)->toBeTrue();
})->group('fast');

/*
| On board: no due date, no reminders, not overdue before departure
*/

it('gives a balance collected on board no due date, and keeps the balance', function (): void {
    [$tenant, $booking] = onBoardBooking();

    $due = Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-06-01 12:00', 'UTC')));

    expect($due)->toBeNull()
        ->and($booking->balance_cents)->toBe(8000);

    // The same booking, online: a date.
    $tenant->forceFill(['balance_collection' => BalanceCollection::Online])->save();

    expect(Tenancy::forTenant($tenant, fn (): ?Carbon => app(ComputeBalanceDueAt::class)($booking, Carbon::parse('2026-06-01 12:00', 'UTC'))))
        ->not->toBeNull();
})->group('fast');

it('sends no balance reminder and no overdue notice when the balance is paid on board', function (): void {
    Mail::fake();

    [$tenant, $booking] = onBoardBooking();

    // A due date written before the operator switched, already past, with the
    // departure still ahead: online, this is a reminder and an overdue notice.
    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'balance_due_at' => $booking->starts_at_utc->copy()->subDays(3),
    ])->save());

    Carbon::setTestNow($booking->starts_at_utc->copy()->subDays(2)->setTime(11, 0));

    app(SendDueReminders::class)();

    $balanceLogs = static fn (): int => Tenancy::forTenant($tenant, static fn (): int => NotificationLog::query()
        ->where('booking_id', $booking->getKey())
        ->whereIn('template', [NotificationTemplate::BalanceDueReminder->value, NotificationTemplate::BalanceOverdue->value])
        ->count());

    expect($balanceLogs())->toBe(0);

    // The control: the same booking, online, gets them.
    $tenant->forceFill(['balance_collection' => BalanceCollection::Online])->save();

    app(SendDueReminders::class)();

    expect($balanceLogs())->toBeGreaterThan(0);
})->group('fast');

it('is not overdue before departure, and «Απλήρωτο υπόλοιπο» after it', function (): void {
    app()->setLocale('el');

    [$tenant, $booking] = onBoardBooking();

    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'balance_due_at' => $booking->starts_at_utc->copy()->subDays(3),
    ])->save());

    $items = static fn (): array => Tenancy::forTenant($tenant, static fn (): array => (new AttentionItems('Europe/Athens'))->everything());
    $count = static fn (): int => Tenancy::forTenant($tenant, static fn (): int => (new AttentionItems('Europe/Athens'))->count());

    // The day before: nothing, though the old due date has passed.
    Carbon::setTestNow($booking->starts_at_utc->copy()->subDay());

    expect($items())->toBe([])
        ->and($count())->toBe(0);

    // The boat has sailed and the balance is still open.
    Carbon::setTestNow($booking->starts_at_utc->copy()->addDay());

    $after = $items();

    expect($after)->toHaveCount(1)
        ->and($after[0]->key)->toBe('balance:' . $booking->getKey())
        ->and($after[0]->title)->toContain('Απλήρωτο υπόλοιπο')
        ->and($after[0]->detail)->toContain('80,00')
        ->and($count())->toBe(1);

    // Still there once the booking has completed, until it is paid.
    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['status' => BookingStatus::Completed])->save());

    expect($items())->toHaveCount(1);

    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['balance_cents' => 0, 'paid_cents' => 12000])->save());

    expect($items())->toBe([]);
})->group('fast');

/*
| What the guest reads
*/

it('says «πληρώνεται στο σκάφος» in the confirmation, in both languages', function (): void {
    [$tenant, $booking] = onBoardBooking();

    foreach (['el' => 'Υπόλοιπο 80,00 €, πληρώνεται στο σκάφος', 'en' => '80,00 € balance, paid on board'] as $locale => $sentence) {
        $html = Tenancy::forTenant($tenant, static function () use ($booking, $locale): string {
            $booking->forceFill(['locale' => $locale])->save();

            return (new GuestMail($booking->refresh(), NotificationTemplate::BookingConfirmed))->render();
        });

        $html = html_entity_decode($html, ENT_QUOTES);

        expect($html)->toContain($sentence)
            ->and($html)->toContain(__('mail.common.deposit_paid', [], $locale))
            ->and($html)->not->toContain(__('mail.common.until', [], $locale) . '</td>');
    }
})->group('fast');

it('labels an online balance «Προκαταβολή», «Υπόλοιπο» and «Έως» in the email', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    $html = Tenancy::forTenant($tenant, static function () use ($booking): string {
        $booking->forceFill(['locale' => 'el', 'balance_due_at' => $booking->starts_at_utc->copy()->subDays(14)])->save();

        return (new GuestMail($booking->refresh(), NotificationTemplate::BookingChanged))->render();
    });

    $html = html_entity_decode($html, ENT_QUOTES);

    expect($html)->toContain('Προκαταβολή')
        ->and($html)->toContain('Υπόλοιπο')
        ->and($html)->toContain('Έως</td>')
        ->and($html)->not->toContain('πληρώνεται στο σκάφος');
})->group('fast');

it('shows «πληρώνεται στο σκάφος» on the booking page, with no pay button', function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');

    [, $booking] = onBoardBooking();

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('Υπόλοιπο 80,00 €, πληρώνεται στο σκάφος.')
        ->assertSee('Προκαταβολή')
        ->assertDontSee('/pay-balance', false)
        ->assertDontSee(__('guest.booking.pay_balance'));
})->group('fast');

it('keeps the pay button and names the deposit on the booking page when collected online', function (): void {
    Carbon::setTestNow('2026-06-20 09:00:00');

    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['balance_due_at' => $booking->starts_at_utc->copy()->subDays(14)])->save());

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('/pay-balance', false)
        ->assertSee('Προκαταβολή')
        ->assertSee('Έως')
        ->assertDontSee('πληρώνεται στο σκάφος');
})->group('fast');

/*
| The two settings must not disagree without saying so
*/

it('warns on the payment settings when deposits are on and no price list asks for one', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);

    $warning = __('payment_settings.warnings.no_deposit_plan');

    Livewire::actingAs($owner)->test(PaymentSettings::class)
        ->fillForm(['deposits_enabled' => true])
        ->assertSee($warning);

    Livewire::actingAs($owner)->test(PaymentSettings::class)
        ->fillForm(['deposits_enabled' => false])
        ->assertDontSee($warning);

    RatePlan::factory()->depositPercent(30)->create();

    Livewire::actingAs($owner)->test(PaymentSettings::class)
        ->fillForm(['deposits_enabled' => true])
        ->assertDontSee($warning);
})->group('fast');

it('warns on the trip prices when a deposit is set and deposits are off', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);

    $product = Product::factory()->create();
    AgeBand::factory()->create(['product_id' => $product->getKey()]);

    $warning = __('pricing.periods.terms.deposits_off');

    $page = Livewire::actingAs($owner)->test(EditProduct::class, ['record' => $product->getRouteKey()]);

    $page->set('priceTerms.deposit_type', 'none')->assertDontSee($warning);
    $page->set('priceTerms.deposit_type', 'percent')->assertSee($warning);

    $tenant->forceFill(['deposits_enabled' => true])->save();
    tenancy()->initialize($tenant->refresh());

    Livewire::actingAs($owner)->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->set('priceTerms.deposit_type', 'percent')
        ->assertDontSee($warning);
})->group('fast');

/*
| A phone booking: paid, a deposit, or on the day
*/

it('confirms a phone booking paid in full, as before', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = phoneBooking($fixture, ['payment' => BookingResource::PAYMENT_PAID, 'paid_by' => 'cash']);

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(13000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($booking->balance_due_at)->toBeNull()
            ->and(Payment::query()->where('booking_id', $booking->getKey())->sole()->kind)->toBe(PaymentKind::Full);
    });
})->group('fast');

it('confirms a phone booking on a deposit, with the rest open and a due date online', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = phoneBooking($fixture, [
            'payment' => BookingResource::PAYMENT_DEPOSIT,
            'deposit_amount' => 4000,
            'deposit_by' => PaymentGatewayName::BankTransfer->value,
        ]);

        $payment = Payment::query()->where('booking_id', $booking->getKey())->sole();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(4000)
            ->and($booking->balance_cents)->toBe(9000)
            ->and($booking->deposit_cents)->toBe(4000)
            ->and($booking->hold_expires_at)->toBeNull()
            // Online: the balance has a date, as it would after a checkout.
            ->and($booking->balance_due_at)->not->toBeNull()
            ->and($payment->kind)->toBe(PaymentKind::Deposit)
            ->and($payment->gateway)->toBe(PaymentGatewayName::BankTransfer)
            ->and($payment->amount_cents)->toBe(4000);
    });
})->group('fast');

it('gives a phone deposit no due date when the operator collects on board', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    $fixture['tenant']->forceFill(['deposits_enabled' => true, 'balance_collection' => BalanceCollection::OnBoard])->save();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = phoneBooking($fixture, [
            'payment' => BookingResource::PAYMENT_DEPOSIT,
            'deposit_amount' => 4000,
            'deposit_by' => PaymentGatewayName::Pos->value,
        ]);

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->balance_cents)->toBe(9000)
            ->and($booking->balance_due_at)->toBeNull();
    });
})->group('fast');

it('confirms a phone booking paid on the day, with nothing paid', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = phoneBooking($fixture, ['payment' => BookingResource::PAYMENT_ON_THE_DAY]);

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(0)
            ->and($booking->balance_cents)->toBe(13000)
            ->and($booking->hold_expires_at)->toBeNull()
            ->and(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
})->group('fast');

it('treats a deposit of the whole price as paid in full, and refuses a deposit of nothing', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = phoneBooking($fixture, [
            'payment' => BookingResource::PAYMENT_DEPOSIT,
            'deposit_amount' => 13000,
            'deposit_by' => PaymentGatewayName::Cash->value,
        ]);

        expect($booking->balance_cents)->toBe(0)
            ->and(Payment::query()->where('booking_id', $booking->getKey())->sole()->kind)->toBe(PaymentKind::Full);

        $before = Booking::query()->count();

        expect(fn () => app(CreateManualBooking::class)(
            new BookingDraftData(
                product: $fixture['product'],
                date: $fixture['departure']->local_date->copy(),
                guestName: 'Νίκος',
                guestEmail: 'nikos@example.gr',
                paxByCode: ['adult' => 1],
            ),
            depositCents: 0,
        ))->toThrow(ValidationException::class);

        // Refused before the draft: nothing holds the seats.
        expect(Booking::query()->count())->toBe($before);
    });
})->group('fast');

it('asks the phone form for a payment choice', function (): void {
    $fixture = BookingApiScenario::bookable(unitPriceCents: 6500);
    $owner = OperatorUser::withRole(Role::Owner, $fixture['tenant']);

    tenancy()->initialize($fixture['tenant']);

    Livewire::actingAs($owner)->test(BookingResource\Pages\CreateBooking::class)
        ->fillForm([
            'product_id' => $fixture['product']->getKey(),
            'departure_id' => $fixture['departure']->getKey(),
            'pax' => [['code' => 'adult', 'qty' => 2]],
            'guest_name' => 'Γιώργος',
            'guest_email' => 'giorgos@example.gr',
        ])
        ->call('create')
        ->assertHasFormErrors(['payment' => 'required']);

    Livewire::actingAs($owner)->test(BookingResource\Pages\CreateBooking::class)
        ->fillForm([
            'product_id' => $fixture['product']->getKey(),
            'departure_id' => $fixture['departure']->getKey(),
            'pax' => [['code' => 'adult', 'qty' => 2]],
            'guest_name' => 'Γιώργος',
            'guest_email' => 'giorgos@example.gr',
            'payment' => BookingResource::PAYMENT_DEPOSIT,
        ])
        ->call('create')
        ->assertHasFormErrors(['deposit_amount' => 'required', 'deposit_by' => 'required']);
})->group('fast');

/*
| Expiry never touches money
*/

it('never expires a pending booking that has money on it', function (): void {
    [$paidTenant, $paid] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);
    [$emptyTenant, $empty] = GuestPageScenario::booking(paidCents: 0, balanceCents: 12000);

    foreach ([[$paidTenant, $paid], [$emptyTenant, $empty]] as [$tenant, $booking]) {
        Tenancy::forTenant($tenant, static function () use ($booking): void {
            Booking::query()->whereKey($booking->getKey())->update([
                'status' => BookingStatus::PendingPayment->value,
                'updated_at' => now()->subHours(3),
            ]);
        });
    }

    app(ExpireAbandonedCheckouts::class)();

    expect(Tenancy::forTenant($paidTenant, fn () => $paid->refresh()->status))->toBe(BookingStatus::PendingPayment)
        ->and(Tenancy::forTenant($emptyTenant, fn () => $empty->refresh()->status))->toBe(BookingStatus::Expired);
})->group('fast');
