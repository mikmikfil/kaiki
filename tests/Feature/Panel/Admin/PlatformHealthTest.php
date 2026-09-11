<?php

declare(strict_types=1);

use App\Domain\Platform\Support\PlatformHealthReport;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Enums\WebhookEventStatus;
use App\Filament\Admin\Pages\PlatformHealth;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\GatewayWebhookEvent;
use App\Models\IcalSource;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| SAA-17 — platform health, across every operator
|--------------------------------------------------------------------------
|
| The one screen besides the merchant list that is cross-tenant on purpose.
| Everywhere else two operators' rows in one answer is the defect #8 catches;
| here it is the answer, so the test builds two operators and asserts each is
| counted as itself — not merged, not missing.
|
*/

function healthAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

function healthUrl(): string
{
    return PlatformHealth::getUrl(panel: 'admin');
}

function healthGatewayEvent(?Tenant $tenant, WebhookEventStatus $status): void
{
    (new GatewayWebhookEvent)->forceFill([
        'tenant_id' => $tenant?->getKey(),
        'provider' => PaymentGatewayName::Viva->value,
        'event_id' => (string) Str::uuid(),
        'signature_valid' => true,
        'payload' => '{}',
        'status' => $status->value,
        'received_at' => Carbon::now(),
    ])->save();
}

it('refuses an operator, at the panel and at the page itself', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get(healthUrl())->assertForbidden();

    // Defence in depth: if the panel boundary is ever relaxed — impersonation
    // is exactly that change — the page still answers for itself.
    actingAs($owner);
    expect(PlatformHealth::canAccess())->toBeFalse();
})->group('fast');

it('shows the super-admin the three blocks', function (): void {
    actingAs(healthAdmin())
        ->get(healthUrl() . '?lang=el')
        ->assertSuccessful()
        ->assertSee(__('platform.health.queue.heading', [], 'el'))
        ->assertSee(__('platform.health.failed.none', [], 'el'))
        ->assertSee(__('platform.health.tenants.none', [], 'el'));
})->group('fast');

it('counts open failures per operator, each as itself', function (): void {
    $aegean = Tenant::factory()->create(['name' => 'Aegean Health Test']);
    $ionian = Tenant::factory()->create(['name' => 'Ionian Health Test']);

    Tenancy::forTenant($aegean, static function (): void {
        Invoice::factory()->failed()->count(2)->create();
        // Registered with AADE — not a failure, must not be counted.
        Invoice::factory()->sent()->create();
        IcalSource::factory()->failing()->create();
    });

    Tenancy::forTenant($ionian, static function (): void {
        // Below the threshold the operator's own feed uses: not yet a failure.
        IcalSource::factory()->failing(1)->create();
        IcalSource::factory()->failing(5)->create();
    });

    healthGatewayEvent($ionian, WebhookEventStatus::Failed);
    healthGatewayEvent($ionian, WebhookEventStatus::Processed);
    healthGatewayEvent(null, WebhookEventStatus::Orphaned);

    $report = app(PlatformHealthReport::class);
    $rows = collect($report->perTenant())->keyBy('tenant_id');

    expect($rows->keys()->all())->toBe([$aegean->getKey(), $ionian->getKey()])
        ->and($rows[$aegean->getKey()])->toMatchArray(['mydata' => 2, 'gateway_webhooks' => 0, 'ical' => 1, 'total' => 3])
        ->and($rows[$ionian->getKey()])->toMatchArray(['mydata' => 0, 'gateway_webhooks' => 1, 'ical' => 1, 'total' => 2])
        // PAY-7's orphan belongs to nobody, so it is its own line.
        ->and($report->orphanGatewayWebhooks())->toBe(1);

    actingAs(healthAdmin())
        ->get(healthUrl() . '?lang=el')
        ->assertSuccessful()
        ->assertSee('Aegean Health Test')
        ->assertSee('Ionian Health Test')
        // Each name leads to the operator's own record in the merchant list.
        ->assertSee(TenantResource::getUrl('edit', ['record' => $aegean->getRouteKey()], panel: 'admin'), escape: false);
})->group('fast');

it('reports the queue depth, the oldest wait and the failed jobs by class', function (): void {
    Carbon::setTestNow('2026-09-11 12:00:00');

    foreach ([10, 1] as $minutesAgo) {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => Carbon::now()->getTimestamp(),
            'created_at' => Carbon::now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    foreach (['App\\Jobs\\DeliverWebhook', 'App\\Jobs\\DeliverWebhook', 'App\\Mail\\BookingConfirmedMail'] as $class) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode(['displayName' => $class]),
            'exception' => "RuntimeException: the receiver answered 500\n#0 /app/Jobs/DeliverWebhook.php(88)",
            'failed_at' => Carbon::now(),
        ]);
    }

    $report = app(PlatformHealthReport::class);

    expect($report->queue())->toMatchArray(['depth' => 2, 'reserved' => 0, 'oldest_age_seconds' => 600])
        ->and($report->failedJobsTotal())->toBe(3)
        ->and($report->failedJobsByClass())->toBe(['App\\Jobs\\DeliverWebhook' => 2, 'App\\Mail\\BookingConfirmedMail' => 1])
        // The first line of the exception, not the trace.
        ->and($report->recentFailedJobs()[0]['error'])->toBe('RuntimeException: the receiver answered 500');

    actingAs(healthAdmin())
        ->get(healthUrl() . '?lang=el')
        ->assertSuccessful()
        ->assertSee('DeliverWebhook')
        ->assertSee(__('platform.health.failed.retry_hint', [], 'el'));

    Carbon::setTestNow();
})->group('fast');
