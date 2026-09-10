<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\Plan;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource;
use App\Filament\Admin\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Admin\Widgets\PlatformOverview;
use App\Mail\StaffInvitationMail;
use App\Models\AuditLog;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
 * The platform merchant list — the read-only half of SAA-1, pulled forward from
 * M7 because `/admin` has been an empty panel since #9 and the platform owner
 * has had no way to see their own operators.
 *
 * Two things make this resource different from every other one in the codebase,
 * and both are asserted here rather than assumed:
 *
 *   1. **It is deliberately cross-tenant.** `tenants` is not tenant-owned — it
 *      *is* the tenant — so `BelongsToTenant` does not apply and the list must
 *      show every operator. Everywhere else in this application a query
 *      returning two tenants' rows is the bug (#8); here it is the feature, and
 *      the test below is what distinguishes the two.
 *
 *   2. **It writes exactly one thing, and only with a reason.** It was
 *      read-only because SEC-16 needs a platform write to be "confirmed and
 *      audit-logged with actor, timestamp and reason", and when this shipped
 *      the audit log was an undecided ADR. ADR-0025 was accepted on 4 September
 *      and #53 built the trail, so editing an operator's subscription is
 *      allowed — and the three conditions are asserted below, because a policy
 *      cannot enforce any of them.
 */

/**
 * The list URL on the `admin` panel.
 *
 * `AppPanelProvider` is `->default()`, so `getUrl()` with no panel resolves
 * against `/app` and throws `RouteNotFoundException`. Naming the panel is not
 * optional here, and getting it wrong is a confusing failure rather than an
 * obvious one.
 */
function adminUrl(): string
{
    return TenantResource::getUrl('index', panel: 'admin');
}

function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

/**
 * Two operators with deliberately different plans, statuses and languages.
 *
 * @return array{0: Tenant, 1: Tenant}
 */
function twoMerchants(): array
{
    return [
        Tenant::factory()->create([
            'name' => 'Aegean Blue Cruises',
            'slug' => 'aegean-blue',
            'plan' => Plan::Fleet,
            'status' => TenantStatus::Active,
            'default_locale' => 'el',
        ]),
        Tenant::factory()->create([
            'name' => 'Ionian Sunset',
            'slug' => 'ionian-sunset',
            'plan' => Plan::Solo,
            'status' => TenantStatus::Trialing,
            'default_locale' => 'en',
        ]),
    ];
}

it('lists every merchant, across tenants', function (): void {
    // The assertion that would fail the day someone "helpfully" adds
    // BelongsToTenant to the Tenant model: the list would quietly show one row
    // and look perfectly fine.
    [$aegean, $ionian] = twoMerchants();

    actingAs(superAdmin())
        ->get(adminUrl())
        ->assertSuccessful()
        ->assertSee($aegean->name)
        ->assertSee($ionian->name);
})->group('fast');

it('refuses an operator, so the resource is not a second way into /admin', function (): void {
    // `canAccessPanel` already refuses at the panel boundary (#9). This proves
    // the resource does not open a door beside it — a partial render is how
    // someone learns what exists behind a wall.
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)
        ->get(adminUrl())
        ->assertForbidden();
})->group('fast');

it('refuses an unauthenticated visitor', function (): void {
    get(adminUrl())->assertRedirect();
})->group('fast');

it('hides a soft-deleted merchant by default', function (): void {
    // A deleted operator's data still exists, and the platform owner is the one
    // person who may need to see that — but not by default, or the list stops
    // meaning "our merchants".
    [$aegean, $ionian] = twoMerchants();
    $ionian->delete();

    actingAs(superAdmin())
        ->get(adminUrl())
        ->assertSuccessful()
        ->assertSee($aegean->name)
        ->assertDontSee($ionian->name);
})->group('fast');

it('renders plan and status through the shared enum labels', function (): void {
    // Not a second copy of these strings. #12 consolidated every enum label
    // into `enums.php` precisely so the super-admin panel and the operator
    // panel cannot disagree about what "past due" is called.
    Tenant::factory()->create(['status' => TenantStatus::PastDue, 'plan' => Plan::Pro]);

    actingAs(superAdmin())
        ->get(adminUrl() . '?lang=en')
        ->assertSuccessful()
        ->assertSee(TenantStatus::PastDue->label())
        ->assertSee(Plan::Pro->label());
})->group('fast');

it('renders the list in Greek when Greek is asked for', function (): void {
    Tenant::factory()->create(['status' => TenantStatus::PastDue]);

    app()->setLocale('el');
    $greek = TenantStatus::PastDue->label();

    actingAs(superAdmin())
        ->get(adminUrl() . '?lang=el')
        ->assertSuccessful()
        ->assertSee($greek);
})->group('fast', 'i18n');

it('takes on a new operator, with an owner who sets their own password', function (): void {
    Mail::fake();

    $admin = superAdmin();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($admin)
        ->test(CreateTenant::class)
        ->fillForm([
            'name' => 'Kefalonia Sailing',
            'slug' => 'kefalonia-sailing',
            'email' => 'accounts@kefalonia-sailing.example',
            'owner_name' => 'Δημήτρης Λύκος',
            'owner_email' => 'dimitris@kefalonia-sailing.example',
            'plan' => Plan::Trial->value,
            'vertical' => TenantVertical::Boats->value,
            'default_locale' => 'el',
            'is_sandbox' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'kefalonia-sailing')->sole();

    // The trial clock starts, or the merchant list shows «no date» for ever and
    // "who lapses this week" has nothing to answer from.
    expect($tenant->status)->toBe(TenantStatus::Trialing)
        ->and($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->supported_locales)->toBe(['el', 'en']);

    Tenancy::forTenant($tenant, function (): void {
        $owner = User::query()->where('email', 'dimitris@kefalonia-sailing.example')->sole();

        // BRD-3: the brand profile comes from the observer, so a tenant is
        // never unbranded whichever of the four paths created it.
        expect(BrandProfile::query()->count())->toBe(1)
            ->and($owner->hasRole(Role::Owner))->toBeTrue();
    });

    // TEN-8a. Nobody typed a password — not the owner, and not the platform.
    Mail::assertSent(StaffInvitationMail::class);
})->group('fast');

it('refuses a slug the hosted router would never match', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // `/{operator}` is constrained to `[a-z0-9][a-z0-9-]*`. A slug outside it is
    // an operator whose pages 404 from the day they are created, and the form is
    // where that is cheap to catch.
    Livewire::actingAs(superAdmin())
        ->test(CreateTenant::class)
        ->fillForm([
            'name' => 'Bad Slug',
            'slug' => 'Bad Slug!',
            'email' => 'a@example.com',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@example.com',
            'plan' => Plan::Trial->value,
            'vertical' => TenantVertical::Boats->value,
            'default_locale' => 'el',
        ])
        ->call('create')
        ->assertHasFormErrors(['slug']);
})->group('fast');

it('offers no way to delete a merchant', function (): void {
    // Asserted rather than trusted to the absence of a page. Filament allows an
    // action when no policy forbids it, so "we did not build a form" is not the
    // same as "it is refused" — the next person to scaffold a resource would
    // get one for free.
    //
    // `create` and `update` **are** allowed since 2026-09-09 — the audit trail
    // SEC-16 needs was built by #53, and onboarding had to exist somewhere or
    // no customer could be taken on at all. Deleting stays refused: it is a
    // retention decision, not a button (GDR, ADR-0012).
    $tenant = Tenant::factory()->create();
    $admin = superAdmin();

    expect($admin->can('delete', $tenant))->toBeFalse()
        ->and($admin->can('forceDelete', $tenant))->toBeFalse()
        ->and($admin->can('restore', $tenant))->toBeFalse();

    expect(array_keys(TenantResource::getPages()))->toBe(['index', 'create', 'edit']);
})->group('fast');

it('lets a super-admin view and edit', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = superAdmin();

    expect($admin->can('viewAny', Tenant::class))->toBeTrue()
        ->and($admin->can('view', $tenant))->toBeTrue()
        ->and($admin->can('update', $tenant))->toBeTrue();
})->group('fast');

it('refuses an operator at the policy, not only at the panel', function (): void {
    // Defence in depth. If the panel boundary is ever relaxed — impersonation
    // in M7 is exactly the change that would do it — the policy still holds.
    $owner = OperatorUser::withRole(Role::Owner);
    $other = Tenant::factory()->create();

    expect($owner->can('viewAny', Tenant::class))->toBeFalse()
        ->and($owner->can('view', $other))->toBeFalse()
        ->and($owner->can('view', $owner->tenant))->toBeFalse();
})->group('fast');

it('shows the platform overview on the /admin landing page', function (): void {
    twoMerchants();

    actingAs(superAdmin())
        ->get('/admin?lang=en')
        ->assertSuccessful()
        ->assertSee(__('tenants.overview.total'))
        ->assertSee(__('tenants.overview.past_due'));
})->group('fast');

it('registers the overview widget on the admin panel, and only there', function (): void {
    // Discovered rather than listed, so this is what catches the directory
    // being renamed or the widget landing under the wrong panel — where it
    // would show every operator's counts to an operator.
    $widgetsOn = static function (string $panel): array {
        $names = [];

        foreach (Filament::getPanel($panel)->getWidgets() as $widget) {
            $names[] = is_string($widget) ? $widget : $widget::class;
        }

        return $names;
    };

    expect($widgetsOn('admin'))->toContain(PlatformOverview::class);
    expect($widgetsOn('app'))->not->toContain(PlatformOverview::class);
})->group('fast');

it('counts merchants by status, excluding deleted accounts', function (): void {
    // A cancelled account is not a merchant. Counting it would make the total
    // disagree with the list directly beneath it, which is worse than either
    // number on its own.
    Tenant::factory()->count(3)->create(['status' => TenantStatus::Active]);
    Tenant::factory()->count(2)->create(['status' => TenantStatus::Trialing]);
    Tenant::factory()->create(['status' => TenantStatus::PastDue]);
    Tenant::factory()->create(['status' => TenantStatus::Active])->delete();

    $counts = PlatformOverview::countsByStatus();

    expect($counts[TenantStatus::Active->value])->toBe(3)
        ->and($counts[TenantStatus::Trialing->value])->toBe(2)
        ->and($counts[TenantStatus::PastDue->value])->toBe(1)
        ->and(array_sum($counts))->toBe(6);
})->group('fast');

it('reports nothing rather than breaking on an empty platform', function (): void {
    // The first thing a new deployment renders. A missing key here would be a
    // 500 on the very first page the platform owner ever opens.
    expect(PlatformOverview::countsByStatus())->toBe([]);

    actingAs(superAdmin())->get('/admin')->assertSuccessful();
})->group('fast');

/**
 * A mounted edit page on the **admin** panel.
 *
 * `setCurrentPanel` by hand, because a Livewire component test does not pass
 * through a panel's middleware — so Filament's current panel stays `app`, and
 * the page renders links to `filament.app.resources.tenants.*`, which do not
 * exist. The failure is a `RouteNotFoundException` from inside a Blade view and
 * says nothing at all about panels.
 */
function editTenantPage(User $admin, Tenant $tenant): Testable
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return Livewire::actingAs($admin)->test(EditTenant::class, ['record' => $tenant->getKey()]);
}

it('records who changed an operator, when, and why', function (): void {
    $tenant = Tenant::factory()->create(['plan' => Plan::Solo, 'status' => TenantStatus::Trialing]);
    $admin = superAdmin();

    editTenantPage($admin, $tenant)
        ->fillForm([
            'plan' => Plan::Fleet->value,
            'status' => TenantStatus::Active->value,
            'vertical' => TenantVertical::Boats->value,
            'is_sandbox' => false,
        ])
        ->callAction('save', ['auditReason' => 'Upgraded on the telephone, invoice 2026-114.'])
        ->assertHasNoErrors();

    expect($tenant->refresh()->plan)->toBe(Plan::Fleet)
        ->and($tenant->status)->toBe(TenantStatus::Active);

    // SEC-16's three parts. The row lands in the **operator's** trail rather
    // than a platform one, because an operator asking "who put us on
    // read-only?" is asking about their own account.
    Tenancy::forTenant($tenant, function () use ($admin): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->user_id)->toBe($admin->id)
            ->and($entry->reason)->toContain('invoice 2026-114')
            ->and($entry->created_at)->not->toBeNull()
            // Only what moved, with both sides of it. A row listing five fields
            // on an edit that changed two is a row nobody can read in a year.
            ->and($entry->context)->toBe([
                'plan_from' => 'solo',
                'plan_to' => 'fleet',
                'status_from' => 'trialing',
                'status_to' => 'active',
            ]);
    });
})->group('fast');

it('refuses to save the change without a reason', function (): void {
    $tenant = Tenant::factory()->create(['plan' => Plan::Solo]);

    // The reason is required by the confirmation rather than by the form, so it
    // belongs to the act and cannot be left over from a previous edit. Without
    // it nothing is written at all — not the tenant, and not the trail.
    editTenantPage(superAdmin(), $tenant)
        ->fillForm(['plan' => Plan::Pro->value])
        ->callAction('save', ['auditReason' => ''])
        ->assertHasActionErrors(['auditReason' => 'required']);

    expect($tenant->refresh()->plan)->toBe(Plan::Solo);
})->group('fast');

it('counts the days an operator has left, from whichever date applies', function (): void {
    Carbon::setTestNow('2026-09-09 11:00:00');

    // The paid date wins over the trial date. An operator who converted has
    // both, and answering from the trial would show a paying customer as months
    // expired.
    $converted = Tenant::factory()->create([
        'trial_ends_at' => '2026-06-01',
        'subscription_ends_at' => '2026-09-19',
    ]);

    expect($converted->accessDaysLeft())->toBe(10);

    // Negative rather than clamped: "lapsed eleven days ago" is what the list
    // has to show, and zero would make yesterday and last spring look alike.
    $lapsed = Tenant::factory()->create(['subscription_ends_at' => '2026-08-29', 'trial_ends_at' => null]);

    expect($lapsed->accessDaysLeft())->toBe(-11);

    // Nothing to go on is null, not zero — an operator with no date is not an
    // operator whose access ends today.
    expect(Tenant::factory()->create(['trial_ends_at' => null, 'subscription_ends_at' => null])->accessDaysLeft())
        ->toBeNull();

    Carbon::setTestNow();
})->group('fast');
