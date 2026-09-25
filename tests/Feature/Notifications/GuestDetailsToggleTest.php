<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Support\GuestDetailsTracking;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Notifications\Actions\SendDueReminders;
use App\Enums\GuestDetailsStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Στοιχεία επιβατών» switched on or off on a trip that already sold
|--------------------------------------------------------------------------
|
| Audit 2, 2026-09-25. Tracking opened only at confirmation, so switching the
| list on left every booking already made untracked — no `/g/` link, no
| reminder — and switching it off left drafts `pending` with no link, chased by
| reminders that had nothing to point to.
|
*/

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Product, 2: Booking} a confirmed booking on a trip that asks for nothing yet */
function toggleScenario(bool $required = false, bool $confirm = true): array
{
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-01 09:00:00'));

    Tenancy::forTenant($fixture['tenant'], fn () => $fixture['product']->forceFill([
        'guest_details_required' => $required,
        'guest_details_deadline_hours' => 48,
    ])->save());

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    $booking = Tenancy::forTenant($fixture['tenant'], function () use ($created, $confirm): Booking {
        $booking = Booking::query()->where('uuid', $created->json('data.uuid'))->sole();
        $booking->forceFill(['is_test' => false])->save();

        return $confirm ? app(ConfirmBooking::class)($booking) : $booking;
    });

    return [$fixture['tenant'], $fixture['product'], $booking];
}

it('opens the list for bookings already confirmed when the switch goes on', function (): void {
    [$tenant, $product, $booking] = toggleScenario();

    expect($booking->guest_details_status)->toBe(GuestDetailsStatus::NotRequired);

    Tenancy::forTenant($tenant, function () use ($product, $booking): void {
        expect(GuestDetailsTracking::untrackedCount($product))->toBe(1);

        app(SaveProduct::class)($product, ['guest_details_required' => true]);

        $booking->refresh();

        expect($booking->guest_details_status)->toBe(GuestDetailsStatus::Pending)
            ->and($booking->guest_details_token)->not->toBeNull()
            ->and($booking->guest_details_deadline_at?->toIso8601String())->toBe('2026-06-29T09:00:00+00:00')
            ->and(GuestDetailsTracking::untrackedCount($product))->toBe(0);

        // The deadline follows the trip's hours when they move.
        app(SaveProduct::class)($product->refresh(), ['guest_details_deadline_hours' => 24]);

        expect($booking->refresh()->guest_details_deadline_at?->toIso8601String())->toBe('2026-06-30T09:00:00+00:00');
    });

    // And the sweep now chases it.
    Carbon::setTestNow('2026-06-28 10:00:00');

    app(SendDueReminders::class)();

    expect(Tenancy::forTenant($tenant, fn (): int => NotificationLog::query()->where('booking_id', $booking->getKey())->count()))->toBeGreaterThan(0);
})->group('fast');

it('says on save how many bookings the switch asked', function (): void {
    [$tenant, $product] = toggleScenario();

    $owner = OperatorUser::withRole(Role::Owner, $tenant);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->set('data.guest_details_required', true)
        ->assertSee(trans_choice('catalog.product.form.guest_details_required.existing', 1, ['count' => 1]))
        ->call('save')
        ->assertNotified(trans_choice('catalog.product.form.guest_details_required.opened', 1, ['count' => 1]));

    expect(GuestDetailsTracking::untrackedCount($product->refresh()))->toBe(0);
})->group('fast');

it('stops chasing when the switch goes off, and never chases without a link', function (): void {
    [$tenant, $product, $booking] = toggleScenario(required: true);

    expect($booking->guest_details_status)->toBe(GuestDetailsStatus::Pending);

    Tenancy::forTenant($tenant, function () use ($product, $booking): void {
        app(SaveProduct::class)($product, ['guest_details_required' => false]);

        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::NotRequired)
            ->and($booking->guest_details_deadline_at)->toBeNull();
    });

    // A draft made while the trip asked, confirmed after it stopped.
    [$tenant2, $product2, $draft] = toggleScenario(required: true, confirm: false);

    Tenancy::forTenant($tenant2, function () use ($product2, $draft): void {
        expect($draft->guest_details_status)->toBe(GuestDetailsStatus::Pending);

        $product2->forceFill(['guest_details_required' => false])->saveQuietly();

        $confirmed = app(ConfirmBooking::class)($draft);

        expect($confirmed->guest_details_status)->toBe(GuestDetailsStatus::NotRequired);

        // And one still marked pending with no `/g/` link is not reminded.
        $confirmed->forceFill(['guest_details_status' => GuestDetailsStatus::Pending, 'guest_details_token' => null])->save();
    });

    Carbon::setTestNow('2026-06-28 10:00:00');

    app(SendDueReminders::class)();

    foreach ([[$tenant, $booking], [$tenant2, $draft]] as [$t, $b]) {
        expect(Tenancy::forTenant($t, fn (): int => NotificationLog::query()
            ->where('booking_id', $b->getKey())->where('template', 'like', 'guest_details%')->count()))->toBe(0);
    }
})->group('fast');
