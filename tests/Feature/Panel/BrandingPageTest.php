<?php

declare(strict_types=1);

use App\Domain\Branding\Support\BrandPayload;
use App\Domain\Branding\Support\ContrastChecker;
use App\Enums\FontSource;
use App\Enums\Role;
use App\Enums\WidgetTheme;
use App\Filament\App\Pages\Branding;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The branding screen — spec BRD-1 … BRD-7, SEC-3, I18N-1, I18N-3
|--------------------------------------------------------------------------
|
| A Page rather than a Resource: there is one profile per tenant and it always
| exists, so a list with one row, a disabled create button and a refused delete
| button would be three affordances to take away again.
|
| The two assertions that matter most here are not about the form. **Saving is
| never blocked by a contrast failure** (BRD-5), and **`custom_css` never
| reaches a widget-facing payload** (BRD-2) — the second is a fixed requirement
| and the widget runs inside somebody else's page.
|
*/

function brandingPageAs(User $user): Testable
{
    // `Livewire::test()` never reaches the panel middleware, so the tenant is
    // initialised by hand exactly as `ResolveTenant` would. The locale
    // assertions below are HTTP tests for the same reason — `SetLocale` does
    // not run here either, and a component test asserting a Greek label would
    // render in English and pass for the wrong reason.
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    return Livewire::actingAs($user)->test(Branding::class);
}

function brandingProfileOf(User $user): BrandProfile
{
    return BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function brandingFormData(array $overrides = []): array
{
    return array_merge([
        'color_primary' => '#8B1E3F',
        'color_secondary' => '#123C69',
        'color_accent' => '#EDC7B7',
        'color_background' => '#FFFFFF',
        'color_text' => '#1A1A1A',
        'font_family' => 'Lora',
        'font_source' => FontSource::Google->value,
        'button_radius_px' => 16,
        'widget_theme' => WidgetTheme::Light->value,
        'social_links' => [],
        'custom_css' => null,
        'email_footer_text' => ['el' => '', 'en' => ''],
    ], $overrides);
}

it('lets an owner and a manager reach the branding page', function (Role $role): void {
    actingAs(OperatorUser::withRole($role))->get('/app/branding')->assertSuccessful();
})->with([[Role::Owner], [Role::Manager]])->group('fast');

it('refuses crew the branding page', function (): void {
    // TEN-8: branding is owner and manager. `ManageBranding` gates viewing as
    // well as editing — a read-only rendering of a form full of inputs a crew
    // member cannot submit is a support ticket waiting to happen.
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/branding')->assertForbidden();
})->group('fast');

it('keeps the navigation item away from crew', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew));

    expect(Branding::canAccess())->toBeFalse();

    actingAs(OperatorUser::withRole(Role::Owner));

    expect(Branding::canAccess())->toBeTrue();
})->group('fast');

it('saves a brand and records what its colours score', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData())
        ->call('save')
        ->assertHasNoFormErrors();

    $profile = brandingProfileOf($user);

    expect($profile->color_primary)->toBe('#8B1E3F')
        ->and($profile->font_source)->toBe(FontSource::Google)
        ->and($profile->button_radius_px)->toBe(16)
        ->and($profile->widget_theme)->toBe(WidgetTheme::Light)
        // BRD-5: the result is stored on the row so the panel can show the
        // badge without recomputing, and so the number an operator was shown is
        // the number that was true when they last saved.
        ->and($profile->contrast_warnings)->toHaveKey(ContrastChecker::BODY_TEXT)
        ->and($profile->contrast_warnings)->toHaveKey(ContrastChecker::BUTTON_TEXT);
})->group('fast');

it('warns about low contrast without blocking the save', function (): void {
    // BRD-5 is explicit that it warns and does not block. An operator whose
    // colours have been on their boats for fifteen years is not going to be
    // told by a booking system that their brand is wrong — a save that fails on
    // a ratio is a save they work around by not saving.
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData([
            'color_text' => '#9A9A9A',
            'color_background' => '#FFFFFF',
            'color_primary' => '#B8B8B8',
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    $profile = brandingProfileOf($user);

    expect($profile->color_text)->toBe('#9A9A9A')
        ->and($profile->hasContrastWarnings())->toBeTrue()
        ->and($profile->contrast_warnings[ContrastChecker::BODY_TEXT]['passes'])->toBeFalse();
})->group('fast');

it('refuses a colour that is not #RRGGBB, with a localised message', function (string $colour): void {
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData(['color_primary' => $colour]))
        ->call('save')
        ->assertHasFormErrors(['color_primary']);

    // Unchanged: a refused save must not half-write the row.
    expect(brandingProfileOf($user)->color_primary)->toBe('#123A5E');
})->with([
    'three-digit short form' => ['#FFF'],
    'no hash' => ['0F62FE'],
    'eight digits with alpha' => ['#0F62FE99'],
    'a colour name' => ['rebeccapurple'],
    'not a colour at all' => ['red; background: url(x)'],
])->group('fast');

it('states the expected shape in Greek rather than naming the column', function (): void {
    app()->setLocale('el');

    // `__()` returns the dotted key when the line is missing, which is the tell
    // I18N-1 relies on — a missing string has to *look* missing.
    expect((string) __('branding.validation.hex_color'))->toContain('#RRGGBB');
    expect((string) __('branding.validation.hex_color'))->not->toBe('branding.validation.hex_color');

    app()->setLocale('en');

    expect((string) __('branding.validation.hex_color'))->toContain('#RRGGBB');
})->group('fast', 'i18n');

it('sanitises custom CSS on save and shows the operator their own back', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData(['custom_css' => '.kaiki{color:red;background:url(javascript:alert(1))}']))
        ->call('save')
        ->assertHasNoFormErrors();

    $profile = brandingProfileOf($user);

    expect((string) $profile->custom_css)->toContain('color:red');
    expect((string) $profile->custom_css)->not->toContain('javascript');
})->group('fast');

it('never puts custom CSS in a widget-facing payload', function (): void {
    // **BRD-2, and it is FIXED.** The widget runs inside somebody else's page —
    // a WordPress site, a hotel's portal — so injecting an operator's
    // stylesheet into it is a cross-site defacement primitive we would be
    // shipping on purpose. The hosted page, which we render ourselves under the
    // HOS-8 CSP, is the only place it belongs.
    $tenant = Tenant::factory()->create();
    $profile = BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();

    $profile->custom_css = '.kaiki{color:red}';
    $profile->save();

    $widget = BrandPayload::forWidget($profile);
    $hosted = BrandPayload::forHostedPage($profile);

    expect($widget)->not->toHaveKey('custom_css');
    expect((string) json_encode($widget))->not->toContain('color:red');
    expect((string) $hosted['custom_css'])->toContain('color:red');
})->group('fast');

it('never exposes an internal id in either payload', function (): void {
    // CNV-9. There is no uuid here to expose instead, because nothing addresses
    // a brand profile — it is reached through its tenant.
    $tenant = Tenant::factory()->create();
    $profile = BrandProfile::query()->withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail();

    foreach ([BrandPayload::forWidget($profile), BrandPayload::forHostedPage($profile)] as $payload) {
        foreach (['id', 'tenant_id', 'created_at', 'updated_at'] as $internal) {
            expect($payload)->not->toHaveKey($internal);
        }
    }
})->group('fast');

it('refuses a malformed social link beside the box it belongs to', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData(['social_links' => [
            'website' => 'javascript:alert(1)',
            'whatsapp' => '6912345678',
        ]]))
        ->call('save')
        ->assertHasFormErrors(['social_links.website', 'social_links.whatsapp']);
})->group('fast');

it('drops the links an operator left empty rather than storing empty strings', function (): void {
    // "No Facebook page" should be an absent key, not an empty string the
    // hosted-page footer has to test for.
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData(['social_links' => [
            'website' => 'https://aegean-blue.example',
            'instagram' => '',
            'whatsapp' => '+306912345678',
        ]]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(brandingProfileOf($user)->social_links)
        ->toBe(['website' => 'https://aegean-blue.example', 'whatsapp' => '+306912345678']);
})->group('fast');

it('resets to the platform defaults but keeps the links and the footer', function (): void {
    // §2.2: reset overwrites the row rather than deleting it, which is why
    // there are no soft deletes. Links and footer survive because neither is
    // brand *styling* — a mail footer is often a legal address, and the links
    // are the operator's own accounts.
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData([
            'social_links' => ['website' => 'https://aegean-blue.example'],
            'email_footer_text' => ['el' => 'Καλό ταξίδι', 'en' => 'Fair winds'],
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    brandingPageAs($user)->call('resetToDefaults');

    $profile = brandingProfileOf($user);

    expect($profile->color_primary)->toBe('#123A5E')
        ->and($profile->font_family)->toBe('Inter')
        ->and($profile->button_radius_px)->toBe(8)
        ->and($profile->social_links)->toBe(['website' => 'https://aegean-blue.example'])
        ->and($profile->getTranslation('email_footer_text', 'el'))->toBe('Καλό ταξίδι')
        // Recomputed against the defaults rather than emptied: the default
        // palette has a real score and the badge should show it.
        ->and($profile->contrast_warnings)->not->toBe([]);
})->group('fast');

it('renders every label in Greek and in English', function (): void {
    // I18N-1, I18N-3. These are HTTP tests, not component tests: `SetLocale`
    // runs in the panel middleware, which `Livewire::test()` never reaches.
    $user = OperatorUser::withRole(Role::Owner);

    actingAs($user)->get('/app/branding?lang=el')
        ->assertSuccessful()
        ->assertSee(__('branding.title', locale: 'el'))
        ->assertSee(__('branding.sections.colors', locale: 'el'))
        ->assertDontSee('branding.sections.colors');

    actingAs($user)->get('/app/branding?lang=en')
        ->assertSuccessful()
        ->assertSee(__('branding.title', locale: 'en'))
        ->assertSee(__('branding.sections.colors', locale: 'en'));
})->group('fast', 'i18n');

it('shows the contrast badge with its ratio in the text, not only in its colour', function (): void {
    // A warning that can only be read by telling green from amber is the one
    // warning that must not be.
    $user = OperatorUser::withRole(Role::Owner);

    brandingPageAs($user)
        ->fillForm(brandingFormData(['color_text' => '#9A9A9A']))
        ->call('save');

    $rows = brandingPageAs($user)->instance()->contrastResults();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['message'])->toMatch('/\d+\.\d+/');

    // The label is a translated sentence, not the storage key: a badge reading
    // "body_text" is a lang line that was never written.
    expect($rows[0]['label'])->not->toBe(ContrastChecker::BODY_TEXT);
    expect($rows[1]['label'])->not->toBe(ContrastChecker::BUTTON_TEXT);
})->group('fast');

it('shows nothing but an explanation before the first save', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    expect(brandingPageAs($user)->instance()->contrastResults())->toBe([]);

    actingAs($user)->get('/app/branding')->assertSee(__('branding.contrast.unchecked'));
})->group('fast');

it('shows an operator only their own brand', function (): void {
    // The page reads through the tenant scope like everything else. A second
    // operator's palette appearing here would be the leak ADR-0001 accepted the
    // single-database schema on condition of preventing.
    $mine = OperatorUser::withRole(Role::Owner);
    $theirs = OperatorUser::withRole(Role::Owner);

    brandingProfileOf($theirs)->update(['color_primary' => '#ABCDEF']);

    $state = brandingPageAs($mine)->get('data');

    expect($state['color_primary'])->toBe('#123A5E');
})->group('fast');
