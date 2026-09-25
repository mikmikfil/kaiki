<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\AuditAction;
use App\Enums\Plan;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\AuditLog;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The logo and the colours, set from /admin (Mike, 2026-09-25)
|--------------------------------------------------------------------------
|
| «Την επιλογή χρωμάτων και logo για τον κάθε merchant την θέλω στο admin.
| Αφαίρεσέ την από το first time conf. Και άσ' την και στα settings του
| operator. Αλλά την αρχικοποίηση θέλω να την κάνω από το admin.»
|
| Create and Edit Merchant both carry an «Εμφάνιση» section. What they write
| must land on **that** merchant's brand profile, through the same Actions the
| operator's own screen uses — so a logo set here is stored where, and how, an
| operator's would be.
|
*/

beforeEach(function (): void {
    Storage::fake((string) config('kaiki.branding.uploads.disk'));
    Mail::fake();
});

function adminBrandingSuperAdmin(): User
{
    return User::factory()->superAdmin()->create();
}

function adminBrandingEditPage(Tenant $tenant): Testable
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return Livewire::actingAs(adminBrandingSuperAdmin())->test(EditTenant::class, ['record' => $tenant->getRouteKey()]);
}

function adminBrandOf(Tenant $tenant): BrandProfile
{
    return BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->sole();
}

it('sets the colours and the logo when taking a merchant on', function (): void {
    $other = Tenant::factory()->create();
    $otherBefore = adminBrandOf($other)->only(['color_primary', 'color_accent', 'logo_light_path']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs(adminBrandingSuperAdmin())
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
            'branding.color_primary' => '#0B4F4A',
            'branding.color_accent' => '#D9822B',
            'branding.logo_light_path' => [UploadedFile::fake()->image('logo.png', 600, 200)],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'kefalonia-sailing')->sole();
    $profile = adminBrandOf($tenant);

    expect($profile->color_primary)->toBe('#0B4F4A')
        ->and($profile->color_accent)->toBe('#D9822B')
        // Stored by `UploadBrandAsset`, in this merchant's own directory.
        ->and($profile->logo_light_path)->toStartWith("brand/{$tenant->getKey()}/logo_light/")
        ->and($profile->logo_dark_path)->toBeNull();

    Storage::disk((string) config('kaiki.branding.uploads.disk'))->assertExists((string) $profile->logo_light_path);

    // And nobody else's.
    expect(adminBrandOf($other)->only(['color_primary', 'color_accent', 'logo_light_path']))->toBe($otherBefore);
})->group('fast');

it('leaves the platform colours alone when the section is not touched', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs(adminBrandingSuperAdmin())
        ->test(CreateTenant::class)
        ->fillForm([
            'name' => 'Paxos Boats',
            'slug' => 'paxos-boats',
            'email' => 'hello@paxos-boats.example',
            'owner_name' => 'Ελένη Μαρή',
            'owner_email' => 'eleni@paxos-boats.example',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = adminBrandOf(Tenant::query()->where('slug', 'paxos-boats')->sole());

    expect($profile->color_primary)->toBe(BrandProfile::platformDefaults()['color_primary'])
        ->and($profile->logo_light_path)->toBeNull();
})->group('fast');

it('changes a merchant’s colours and logos on the edit page, with the change in their trail', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $otherBefore = adminBrandOf($other)->only(['color_primary', 'color_secondary', 'logo_dark_path']);

    adminBrandingEditPage($tenant)
        ->assertFormSet(['branding.color_primary' => adminBrandOf($tenant)->color_primary])
        ->fillForm([
            'branding.color_primary' => '#123456',
            'branding.color_secondary' => '#654321',
            'branding.logo_dark_path' => [UploadedFile::fake()->image('dark.png', 600, 200)],
        ])
        ->callAction('save', ['auditReason' => 'Μας έστειλε το λογότυπο με email.'])
        ->assertHasNoErrors();

    $profile = adminBrandOf($tenant);

    expect($profile->color_primary)->toBe('#123456')
        ->and($profile->color_secondary)->toBe('#654321')
        ->and($profile->logo_dark_path)->toStartWith("brand/{$tenant->getKey()}/logo_dark/")
        // Recomputed by `UpdateBrandProfile`, as on the operator's screen.
        ->and($profile->contrast_warnings)->not->toBeEmpty()
        ->and(adminBrandOf($other)->only(['color_primary', 'color_secondary', 'logo_dark_path']))->toBe($otherBefore);

    Tenancy::forTenant($tenant, function (): void {
        $entry = AuditLog::query()->where('action', AuditAction::TenantUpdated->value)->sole();

        expect($entry->reason)->toBe('Μας έστειλε το λογότυπο με email.')
            ->and($entry->context)->toMatchArray([
                'brand_color_primary_to' => '#123456',
                'brand_color_secondary_to' => '#654321',
            ])
            ->and($entry->context)->toHaveKey('brand_logo_dark_path_to');
    });
})->group('fast');

it('removes a logo cleared on the edit page, with its file', function (): void {
    $tenant = Tenant::factory()->create();

    adminBrandingEditPage($tenant)
        ->fillForm(['branding.logo_light_path' => [UploadedFile::fake()->image('logo.png', 600, 200)]])
        ->callAction('save', ['auditReason' => 'Πρώτο λογότυπο.']);

    $path = (string) adminBrandOf($tenant)->logo_light_path;
    expect($path)->not->toBe('');

    adminBrandingEditPage($tenant)
        ->fillForm(['branding.logo_light_path' => null])
        ->callAction('save', ['auditReason' => 'Το λογότυπο ήταν λάθος.'])
        ->assertHasNoErrors();

    expect(adminBrandOf($tenant)->logo_light_path)->toBeNull();
    Storage::disk((string) config('kaiki.branding.uploads.disk'))->assertMissing($path);
})->group('fast');

it('refuses a colour that is not a hex colour', function (): void {
    $tenant = Tenant::factory()->create();

    adminBrandingEditPage($tenant)
        ->fillForm(['branding.color_primary' => 'blue'])
        ->callAction('save', ['auditReason' => 'Δοκιμή.'])
        ->assertHasFormErrors(['branding.color_primary']);

    expect(adminBrandOf($tenant)->color_primary)->not->toBe('blue');
})->group('fast');

it('no longer asks for the logo and colours in the operator’s setup guide', function (): void {
    expect(SetupChecklist::steps())->not->toContain('branding')
        ->and(SetupChecklist::reported())->not->toContain('branding')
        ->and(defined(SetupChecklist::class . '::BRANDING'))->toBeFalse();
})->group('fast');

it('drops a branding step an older guide recorded as skipped', function (): void {
    $tenant = Tenant::factory()->create(['onboarding_skipped_steps' => ['branding', SetupChecklist::VAT]]);

    expect(SetupChecklist::skipped($tenant))->toBe([SetupChecklist::VAT]);
})->group('fast');
