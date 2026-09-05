<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Filament\App\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec TEN-3, TEN-8, SEC-5, SEC-16, CNV-13. ADR-0013 Option A.
 *
 * The operator's only way to create the key their widget needs, and — the part
 * that actually matters — their only way to revoke one that has leaked.
 */

function tenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted list page with the tenant resolved.
 *
 * A Livewire component test does not pass through the panel's middleware, so
 * `ResolveTenant` never runs and every query on a tenant-owned model throws.
 * The HTTP tests above prove the middleware does this in production; here it
 * has to be done by hand.
 */
function panelAs(User $user): Testable
{
    tenancy()->initialize(tenantOf($user));

    return Livewire::actingAs($user)->test(ListApiKeys::class);
}

/** @param  array<string, mixed>  $data */
function createKeyThrough(User $owner, array $data = []): Testable
{
    return panelAs($owner)
        ->callAction('create', array_merge([
            'name' => 'Website widget',
            'type' => ApiKeyType::Publishable->value,
            'environment' => 'live',
            'scopes' => ['products.read', 'availability.read'],
            'allowed_origins' => ['https://example.gr'],
            'expires_at' => null,
        ], $data));
}

it('lets an owner reach the API keys page', function (): void {
    actingAs(OperatorUser::withRole(Role::Owner))->get('/app/api-keys')->assertSuccessful();
})->group('fast');

it('lists the tenant keys without ever exposing the stored hash', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $key = Tenancy::forTenant(
        tenantOf($owner),
        fn (): ApiKey => ApiKey::factory()->create(['name' => 'Website widget']),
    );

    $response = actingAs($owner)->get('/app/api-keys');

    // The prefix is the only part of a key that is ever shown again (TEN-3).
    $response->assertSuccessful()
        ->assertSee('Website widget')
        ->assertSee($key->prefix)
        ->assertDontSee($key->secret_hash);
})->group('fast');

it('reveals the plaintext exactly once, and never again', function (): void {
    // CNV-13. The key exists in one response and nowhere else — not in the
    // session, not in a flash bag that survives a redirect, not in a log.
    $owner = OperatorUser::withRole(Role::Owner);

    $component = createKeyThrough($owner);

    $stored = ApiKey::query()->sole();
    $raw = $component->get('revealedKey');

    expect($raw)->toBeString()
        ->and($stored->matches($raw))->toBeTrue()
        ->and($raw)->toStartWith('pk_live_');

    // A fresh page has nothing to show, because nothing was ever stored.
    actingAs($owner)->get('/app/api-keys')->assertDontSee($raw);

    // And the hash in the database cannot reproduce it.
    expect($stored->getAttribute('secret_hash'))->not->toBe($raw);
})->group('fast');

it('forgets the plaintext once the reveal modal is dismissed', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $component = createKeyThrough($owner);

    expect($component->get('revealedKey'))->toBeString();

    $component->call('unmountAction');

    expect($component->get('revealedKey'))->toBeNull();
})->group('fast');

it('records who created the key', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    createKeyThrough($owner);

    expect(ApiKey::query()->sole()->created_by_user_id)->toBe($owner->getKey());
})->group('fast');

it('refuses a manager and a crew member', function (Role $role): void {
    // TEN-8: API keys sit with billing and gateway credentials, because a
    // secret key reaches every booking and every guest's personal data.
    $response = actingAs(OperatorUser::withRole($role))->get('/app/api-keys');

    expect($response->status())->not->toBe(200)
        ->and($response->status())->toBeIn([403, 404]);
})->with([
    'manager' => Role::Manager,
    'crew' => Role::Crew,
])->group('fast');

it('rejects a publishable key given a scope its type may not hold', function (): void {
    // SEC-5: type is the ceiling, scopes only narrow it. The domain Action
    // throws on this; the form must surface it as a validation error rather
    // than let an InvalidArgumentException become a 500.
    $owner = OperatorUser::withRole(Role::Owner);

    panelAs($owner)
        ->callAction('create', [
            'name' => 'Sneaky',
            'type' => ApiKeyType::Publishable->value,
            'environment' => 'live',
            'scopes' => ['webhooks.receive'],
            'allowed_origins' => [],
            'expires_at' => null,
        ])
        ->assertHasActionErrors(['scopes']);

    expect(ApiKey::query()->count())->toBe(0);
})->group('fast');

it('revokes a key, keeps the row, and records the actor in the audit trail', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $key = Tenancy::forTenant(
        tenantOf($owner),
        fn (): ApiKey => ApiKey::factory()->create(['name' => 'Leaked key']),
    );

    panelAs($owner)
        ->callTableAction('revoke', $key)
        ->assertHasNoTableActionErrors();

    // Revocation is a timestamp, never a delete: the row is the evidence of
    // what the key could reach (data-model §2.1).
    expect($key->refresh()->revoked_at)->not->toBeNull()
        ->and(ApiKey::query()->count())->toBe(1);

    // SEC-16, and the assertion this test used to make is the reason ADR-0025
    // exists. #10 satisfied the requirement with a `Log::info` and said in its
    // own review that the defence was temporary: a log line is not queryable,
    // not visible to the operator, has no retention policy and captures no
    // reason. It is now a row the operator can read for themselves — which
    // matters most on exactly this action, because a leaked key is the incident
    // they need to investigate without opening a support ticket.
    $row = Tenancy::forTenant(tenantOf($owner), fn (): AuditLog => AuditLog::query()->firstOrFail());

    expect($row->action)->toBe(AuditAction::ApiKeyRevoked)
        ->and($row->user_id)->toBe($owner->getKey())
        // SEC-3: the prefix identifies the key without being one.
        ->and($row->subject_label)->toBe($key->prefix)
        ->and($row->created_at)->not->toBeNull();
})->group('fast');

it('offers no revoke action on an already revoked key', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $key = Tenancy::forTenant(
        tenantOf($owner),
        fn (): ApiKey => ApiKey::factory()->create(['revoked_at' => now()]),
    );

    panelAs($owner)
        ->assertTableActionHidden('revoke', $key);
})->group('fast');
