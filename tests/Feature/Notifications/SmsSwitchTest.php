<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\AuditAction;
use App\Enums\IntegrationProvider;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| SMS per operator, switched on by the platform (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| A text costs the operator money and needs their own gateway account, so it
| is not something every operator gets. The platform switches it on for the
| one who asked, on /admin → Edit Merchant, audited; the config value stays a
| kill switch above everyone; and an operator without it never sees a text
| gateway on the connections screen.
|
*/

it('sends a text only when both the platform and the operator have SMS on', function (bool $platform, ?bool $operator, bool $sends): void {
    config(['kaiki.notifications.sms_enabled' => $platform]);

    [$tenant, $booking] = GuestPageScenario::booking();
    $tenant->forceFill(['sms_enabled' => $operator])->save();

    Tenancy::forTenant($tenant, function () use ($booking, $sends): void {
        $booking->forceFill(['guest_phone' => '+306912345678'])->save();

        app(SendNotification::class)->sms($booking->refresh(), NotificationTemplate::PreDeparture24h, 'Αύριο στις 09:30.');

        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('channel', NotificationChannel::Sms->value)
            ->exists())->toBe($sends);
    });
})->with([
    'both on' => [true, true, true],
    'operator never switched' => [true, null, false],
    'operator off' => [true, false, false],
    'platform kill switch' => [false, true, false],
])->group('fast');

it('offers the text gateways only to an operator with SMS on', function (): void {
    $off = Tenant::factory()->create();
    $on = Tenant::factory()->create(['sms_enabled' => true]);

    expect(IntegrationProvider::operatorOptions($off))->not->toHaveKey(IntegrationProvider::Twilio->value)
        ->and(IntegrationProvider::operatorOptions($on))->toHaveKeys([
            IntegrationProvider::Apifon->value,
            IntegrationProvider::Yuboto->value,
            IntegrationProvider::Twilio->value,
        ]);
})->group('fast');

it('lets the platform switch SMS on, with a reason, in the operator\'s own trail', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->superAdmin()->create();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet(['sms_enabled' => false])
        ->fillForm(['sms_enabled' => true])
        ->callAction('save', ['auditReason' => 'Το ζήτησε ο διοργανωτής, με δικό του λογαριασμό Apifon.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->usesSms())->toBeTrue();

    Tenancy::forTenant($tenant, function (): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->context)->toHaveKey('sms_enabled_to', true);
    });
})->group('fast');
