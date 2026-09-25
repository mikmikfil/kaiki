<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Support\GuestDetailsTracking;
use App\Domain\Operations\Actions\GenerateManifest;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\Manifest;
use App\Enums\BookingSource;
use App\Enums\GuestDetailsStatus;
use App\Enums\ManifestColumn;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Events\BookingConfirmed;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\CheckIn;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
use App\Listeners\Booking\SendBookingConfirmation;
use App\Mail\Support\BookingMailDetails;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| A quay or phone sale on a trip that asks for the passenger list
|--------------------------------------------------------------------------
|
| Mike, 25/9: the sale goes through, and the details are filled in later,
| before departure. So the booking is chased like any other (pending, a
| deadline, a `/g/` link), the gap shows on the booking, at boarding and in
| the attention list, and when the guest left no email the crew open the
| guest's own form on their phone. The harbour's list marks who is missing.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Mail::fake();
    Carbon::setTestNow('2026-07-01 07:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: array<string, mixed>, 1: User} a trip that asks for the list, leaving in two hours */
function quayListScenario(Role $role = Role::Crew): array
{
    $fixture = BookingApiScenario::bookable(unitPriceCents: 5000, startsAt: Carbon::now()->addHours(2)->startOfMinute());

    $fixture['tenant']->forceFill(['timezone' => 'Europe/Athens'])->save();

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $fixture['product']->forceFill(['guest_details_required' => true, 'guest_details_deadline_hours' => 24])->save();
    });

    $user = OperatorUser::withRole($role, $fixture['tenant']);
    $user->forceFill(['locale' => 'el'])->save();

    return [$fixture, $user];
}

function quaySale(User $user, string $departureUuid, ?string $email = null, ?string $phone = null): Booking
{
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    Livewire::actingAs($user)->test(Calendar::class)
        ->callAction('sell', [
            'pax_adult' => 2,
            'guest_name' => 'Μαρία Κ.',
            'guest_email' => $email,
            'guest_phone' => $phone,
        ], ['departure' => $departureUuid, 'paid_by' => PaymentGatewayName::Cash->value])
        ->assertHasNoActionErrors()
        ->assertNotified(__('bookings.guest_details.missing'));

    return Booking::query()->where('source', BookingSource::Quay->value)->latest('id')->firstOrFail();
}

it('sells on the quay with only a name, and opens the passenger list with a deadline and a link', function (): void {
    [$fixture, $crew] = quayListScenario();

    $booking = quaySale($crew, (string) $fixture['departure']->uuid);

    expect($booking->guest_details_status)->toBe(GuestDetailsStatus::Pending)
        ->and($booking->guest_details_token)->not->toBeNull()
        ->and($booking->guest_details_deadline_at)->not->toBeNull()
        ->and(GuestDetailsTracking::urlWhilePending($booking))->toBe(route('guest.details', ['token' => $booking->guest_details_token]));

    // Nobody to send it to: nothing sent, and the form still opens for the crew.
    expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())->toBe(0);

    get('/g/' . $booking->guest_details_token)->assertOk();
})->group('fast');

it('shows «Λείπουν στοιχεία επιβατών» on the booking, at boarding and in the attention list, with the form a tap away', function (): void {
    [$fixture, $crew] = quayListScenario(Role::Manager);

    $booking = quaySale($crew, (string) $fixture['departure']->uuid);
    $url = route('guest.details', ['token' => $booking->guest_details_token]);

    Livewire::actingAs($crew)->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertSee(__('bookings.guest_details.missing'))
        ->assertActionVisible('fill_guest_details')
        ->assertActionHasUrl('fill_guest_details', $url);

    Livewire::actingAs($crew)->test(CheckIn::class)
        ->assertSee(__('bookings.guest_details.missing'))
        ->assertSee($url, escape: false);

    actingAs($crew);
    // The page carries its list as JSON; the link is there, slashes escaped.
    get(route('filament.app.boarding'))->assertOk()
        ->assertSee('"details_url"', escape: false)
        ->assertSee((string) $booking->guest_details_token, escape: false);

    $titles = array_map(static fn ($item): string => $item->title, (new AttentionItems('Europe/Athens'))->all());

    expect($titles)->toContain(__('attention.guest_details.title', ['reference' => $booking->reference]));
})->group('fast');

it('gives the harbour a list that marks which passengers are missing', function (): void {
    [$fixture, $crew] = quayListScenario(Role::Manager);

    $booking = quaySale($crew, (string) $fixture['departure']->uuid);

    BookingGuest::query()->where('booking_id', $booking->getKey())->where('position', 1)
        ->update(['full_name' => 'Μαρία Κωνσταντίνου']);

    $manifest = Manifest::forDeparture($fixture['departure']->fresh(), [ManifestColumn::FullName, ManifestColumn::Reference]);

    // Two aboard, neither finished: a name alone is not the list this trip asks for.
    expect($manifest->onBoard)->toBe(2)
        ->and($manifest->missingDetails)->toBe(2)
        ->and($manifest->isMissing(0))->toBeTrue()
        ->and($manifest->isMissing(1))->toBeTrue();

    $csv = app(GenerateManifest::class)->csv($manifest);

    expect($csv)->toContain(__('manifest.missing_column'));

    $html = view('manifests.harbour', ['manifest' => $manifest])->render();

    expect($html)->toContain(trans_choice('manifest.missing', 2, ['count' => 2]));
})->group('fast');

it('texts the form to a guest who left a phone and no email', function (): void {
    config(['kaiki.notifications.sms_enabled' => true]);

    [$fixture, $crew] = quayListScenario();
    $fixture['tenant']->forceFill(['sms_enabled' => true])->save();

    $booking = quaySale($crew, (string) $fixture['departure']->uuid, phone: '+306912345678');

    $sms = NotificationLog::query()
        ->where('booking_id', $booking->getKey())
        ->where('channel', NotificationChannel::Sms->value)
        ->get();

    // The quay's own rule stands: no confirmation text. The list's does go.
    expect($sms)->toHaveCount(1)
        ->and($sms->first()?->template)->toBe(NotificationTemplate::GuestDetailsRequested);

    // With an email, the confirmation carries the link and no text is sent.
    $second = quaySale($crew, (string) $fixture['departure']->uuid, email: 'maria@example.gr', phone: '+306912345679');

    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($second->getKey(), (int) $second->tenant_id));

    expect(NotificationLog::query()->where('booking_id', $second->getKey())->where('channel', NotificationChannel::Sms->value)->count())->toBe(0);
})->group('fast');

it('sends a phone booking the form in its confirmation, on the same terms', function (): void {
    [$fixture] = quayListScenario(Role::Manager);

    $booking = Tenancy::forTenant($fixture['tenant'], fn (): Booking => app(CreateManualBooking::class)(
        new BookingDraftData(
            product: $fixture['product'],
            date: $fixture['departure']->local_date->copy(),
            guestName: 'Γιώργος Νικολάου',
            guestEmail: 'giorgos@example.gr',
            paxByCode: ['adult' => 2],
            departure: $fixture['departure'],
        ),
        payOnTheDay: true,
    ));

    expect($booking->guest_details_status)->toBe(GuestDetailsStatus::Pending);

    expect(Tenancy::forTenant($fixture['tenant'], fn (): ?string => BookingMailDetails::for($booking, NotificationTemplate::BookingConfirmed, 'el')->detailsUrl))
        ->toBe(route('guest.details', ['token' => $booking->guest_details_token]));
})->group('fast');
