<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Filament\App\Pages\Branding;
use App\Filament\App\Resources\PortResource\Pages\CreatePort;
use App\Filament\App\Resources\PortResource\Pages\ListPorts;
use App\Http\Middleware\EnsureTenantIsWritable;
use App\Models\BrandProfile;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantOwnedPolicy;
use App\Support\Tenancy;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Symfony\Component\Finder\Finder;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Read-only mode actually blocks a panel write — spec TEN-9, SAA-7 (#43)
|--------------------------------------------------------------------------
|
| The bug this file exists for: `EnsureTenantIsWritable` gates on the HTTP
| method, and **Livewire hands it a `GET`**. It re-runs persistent middleware
| against a synthesized request that restores the original page-load method, so
| the safe-method short-circuit fired on every Filament action and the tenant's
| state was never consulted. No error, no log line, no partial enforcement —
| every write in `/app` was ungated from #7 until now.
|
| Two assertions carry this file, and they pull in opposite directions:
|
| 1. A write is refused. Obvious, and the easy half.
| 2. **Reads over the same endpoint still work.** Sorting, searching and
|    paginating a Filament table are all `POST /livewire/update`. A blunt method
|    check would pass (1) and give a read-only operator a 403 for looking at
|    their own data, which TEN-9 explicitly does not ask for. Without (2) the
|    wrong fix looks right.
|
*/

/** A tenant whose subscription has lapsed, and an owner inside it. */
function readOnlyOwner(): User
{
    $tenant = Tenant::factory()->create(['status' => TenantStatus::ReadOnly]);

    return OperatorUser::withRole(Role::Owner, $tenant);
}

function writableOwner(): User
{
    return OperatorUser::withRole(Role::Owner);
}

function tenantOfUser(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @return list<class-string<TenantOwnedPolicy>> */
function tenantOwnedPolicies(): array
{
    $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Policies';

    $policies = [];

    foreach (Finder::create()->files()->in($dir)->name('*Policy.php') as $file) {
        $class = 'App\\Policies\\' . str_replace('.php', '', $file->getFilename());

        if (class_exists($class) && is_subclass_of($class, TenantOwnedPolicy::class)) {
            $policies[] = $class;
        }
    }

    sort($policies);

    return $policies;
}

it('refuses a panel write for a read-only tenant', function (): void {
    // Refused at the door: Filament authorizes `create` when the page mounts,
    // so the operator never fills in a form they were never going to be allowed
    // to submit.
    $owner = readOnlyOwner();

    tenancy()->initialize(tenantOfUser($owner));

    Livewire::actingAs($owner)
        ->test(CreatePort::class)
        ->assertForbidden();

    expect(Port::query()->count())->toBe(0);
})->group('fast');

it('refuses a write on a row that already exists, and changes nothing', function (): void {
    // The branding page is the write furthest from a resource — a Filament page
    // with no model of its own, saving through `UpdateBrandProfile`. It is
    // gated because it asks `Gate::allows('update', ...)` like everything else
    // in the panel does, which is the whole reason the guard sits in the policy.
    $owner = readOnlyOwner();
    $tenant = tenantOfUser($owner);

    $before = Tenancy::forTenant($tenant, fn (): ?string => BrandProfile::query()->value('color_primary'));

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(Branding::class)
        ->set('data.color_primary', '#ff0000')
        ->call('save')
        ->assertForbidden();

    $after = Tenancy::forTenant($tenant, fn (): ?string => BrandProfile::query()->value('color_primary'));

    expect($after)->toBe($before);
})->group('fast');

it('lets a read-only operator read over the very same endpoint', function (): void {
    // The assertion the blunt fix fails. Sorting, searching and paginating are
    // POSTs to `/livewire/update`, and TEN-9 asks for none of them to break —
    // an operator sorting out a payment still needs to see their own data.
    $owner = readOnlyOwner();

    $port = Tenancy::forTenant(
        tenantOfUser($owner),
        fn (): Port => Port::factory()->named(['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'])->create(),
    );

    tenancy()->initialize(tenantOfUser($owner));

    Livewire::actingAs($owner)
        ->test(ListPorts::class)
        ->assertSuccessful()
        ->sortTable('name')
        ->assertCanSeeTableRecords([$port])
        ->searchTable('Ζέας')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$port]);
})->group('fast');

it('still opens the panel page itself', function (): void {
    // A GET, and it must keep working: TEN-9 blocks changing things, not
    // looking at them.
    actingAs(readOnlyOwner())->get('/app/ports')->assertSuccessful();
})->group('fast');

it('refuses every write ability on every tenant-owned policy', function (): void {
    // Reflection-driven, like #8. The guarantee is not "the policies written so
    // far remember to check" — it is that a policy cannot be written without the
    // check, because it inherits it. A hand-kept list would be correct until the
    // first person forgot, which is the exact failure being guarded against.
    $owner = readOnlyOwner();
    $port = Tenancy::forTenant(tenantOfUser($owner), fn (): Port => Port::factory()->create());

    tenancy()->initialize(tenantOfUser($owner));

    $allowed = [];

    foreach (tenantOwnedPolicies() as $policyClass) {
        $policy = new $policyClass;

        foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $result = $ability === 'create'
                ? $policy->create($owner)
                : $policy->{$ability}($owner, $port);

            // A policy may refuse for its own reasons — `BrandProfilePolicy`
            // returns a flat `false` for `create` because a brand row is made
            // with the tenant and never by a person. Refused is refused; what
            // this test forbids is a write that is *allowed*.
            $refused = $result instanceof AccessResponse ? $result->denied() : $result === false;

            if (! $refused) {
                $allowed[] = "{$policyClass}::{$ability}";
            }
        }
    }

    expect($allowed)->toBe([], 'writes not refused in read-only mode: ' . implode(', ', $allowed));
})->group('fast');

it('explains the refusal in the operator language rather than saying they are not allowed', function (string $locale, string $fragment): void {
    // CNV-8: an owner denied on their own catalogue with a bare "forbidden"
    // reads it as a permissions bug and opens a support ticket. The denial has
    // to say what actually happened, and that their guests are unaffected.
    app()->setLocale($locale);

    $owner = readOnlyOwner();

    tenancy()->initialize(tenantOfUser($owner));

    $response = Gate::forUser($owner)->inspect('create', Port::class);

    expect($response->allowed())->toBeFalse()
        ->and($response->message())->toContain($fragment);
})->with([
    ['el', 'μόνο για ανάγνωση'],
    ['en', 'read only'],
])->group('fast', 'i18n');

it('leaves a writable tenant exactly as it was', function (): void {
    $owner = writableOwner();

    tenancy()->initialize(tenantOfUser($owner));

    Livewire::actingAs($owner)
        ->test(CreatePort::class)
        ->fillForm([
            'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
            'address' => 'Ακτή Θεμιστοκλέους',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Port::query()->count())->toBe(1);
})->group('fast');

it('keeps refusing an unsafe HTTP request on the API path', function (): void {
    // The middleware is honest wherever the method is, and the API is one of
    // those places. It stays, and this proves it still works.
    $tenant = Tenant::factory()->create(['status' => TenantStatus::ReadOnly]);

    Tenancy::forTenant($tenant, function (): void {
        $request = Request::create('/api/v1/anything', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = (new EnsureTenantIsWritable)->handle(
            $request,
            static fn (): Symfony\Component\HttpFoundation\Response => new Response('ok'),
        );

        expect($response->getStatusCode())->toBe(403)
            ->and((string) $response->getContent())->toContain('tenant_read_only');
    });
})->group('fast');

it('records why the middleware alone was never enough', function (): void {
    // Not decoration. The middleware looks like it enforces TEN-9, which is how
    // the gap survived three issues. If this file ever loses its docblock, the
    // next reader deletes the policy guard as a duplicate.
    $request = Request::create('/livewire/update', 'GET');
    $tenant = Tenant::factory()->create(['status' => TenantStatus::ReadOnly]);

    Tenancy::forTenant($tenant, function () use ($request): void {
        $response = (new EnsureTenantIsWritable)->handle(
            $request,
            static fn (): Symfony\Component\HttpFoundation\Response => new Response('passed through'),
        );

        // A `GET` is exactly what Livewire hands it, on every panel action.
        expect((string) $response->getContent())->toBe('passed through');
    });
})->group('fast');
