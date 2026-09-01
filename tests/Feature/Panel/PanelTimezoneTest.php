<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec CNV-2: stored in UTC, displayed in the tenant timezone.
 *
 * The panel registers this once, in AppPanelProvider, rather than leaving every
 * resource to remember a ->timezone() call. This test exists because the
 * failure is invisible: a datetime rendered in UTC looks perfectly reasonable,
 * it is simply two or three hours off, and nobody notices until an operator
 * argues about a departure time with a passenger standing on the quay.
 */

it('renders a stored UTC datetime in the tenant timezone', function (): void {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);
    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    // Midsummer, so Athens is UTC+3 and the offset is unmistakable.
    Tenancy::forTenant($tenant, fn (): ApiKey => ApiKey::factory()->create([
        'name' => 'Website widget',
        'last_used_at' => '2026-06-15 09:00:00',
    ]));

    $response = actingAs($owner)->get('/app/api-keys');

    $response->assertSuccessful()
        ->assertSee('12:00')
        ->assertDontSee('09:00');
})->group('fast');

it('follows the tenant, not a hardcoded Greek default', function (): void {
    // An operator in another timezone must not be shown Athens time. The
    // default is Europe/Athens; the behaviour is "whatever this tenant is".
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    $owner = OperatorUser::withRole(Role::Owner, $tenant);

    Tenancy::forTenant($tenant, fn (): ApiKey => ApiKey::factory()->create([
        'name' => 'Website widget',
        'last_used_at' => '2026-06-15 09:00:00',
    ]));

    actingAs($owner)->get('/app/api-keys')
        ->assertSuccessful()
        ->assertSee('09:00')
        ->assertDontSee('12:00');
})->group('fast');
