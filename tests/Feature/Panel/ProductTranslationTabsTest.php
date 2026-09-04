<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
 * Spec I18N-1, I18N-3, data-model §1.6.
 *
 * Both locales are required on every translatable column, and the panel is
 * where an operator would otherwise discover that by having a save throw at
 * them. `TranslatableInput` attaches the rule per locale so the *empty* tab is
 * the one marked red — marking the Greek box to say the English one is blank is
 * a message an operator cannot act on.
 */

it('refuses a trip saved with only one locale, on the field that is empty', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(CreateProduct::class)
        ->fillForm([
            'title' => ['el' => 'Ημερήσια κρουαζιέρα', 'en' => ''],
            'slug' => 'full-day-cruise',
            'category' => ProductCategory::SharedFullDay->value,
            'mode' => BookingMode::PerSeat->value,
            'status' => ProductStatus::Draft->value,
            'duration_minutes' => 480,
            'max_pax' => 12,
        ])
        ->call('create')
        ->assertHasFormErrors(['title.en']);
})->group('fast');

it('round-trips both locales through the form', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);

    $product = Tenancy::forTenant($tenant, fn (): Product => Product::factory()->create([
        'title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset cruise'],
        'summary' => ['el' => 'Δύο ώρες στο ηλιοβασίλεμα.', 'en' => 'Two hours at sunset.'],
    ]));

    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(EditProduct::class, ['record' => $product->uuid])
        ->assertFormSet([
            'title.el' => 'Ηλιοβασίλεμα',
            'title.en' => 'Sunset cruise',
            'summary.el' => 'Δύο ώρες στο ηλιοβασίλεμα.',
        ]);
})->group('fast');

it('renders product labels from lang files, in the operator language', function (string $locale, string $expected): void {
    // Over **HTTP**, not as a Livewire component test: `SetLocale` runs in the
    // panel middleware, which `Livewire::test()` bypasses entirely — a
    // component test renders in `config('app.locale')` whatever the operator
    // chose, and would pass in English for both cases.
    //
    // A row has to exist too, because Filament renders the empty state instead
    // of the header row, and an assertion on a column label against an empty
    // table is green without ever seeing the label.
    $owner = OperatorUser::withRole(Role::Owner);
    $owner->update(['locale' => $locale]);

    Tenancy::forTenant(Tenant::query()->findOrFail($owner->tenant_id), fn (): Product => Product::factory()->create());

    actingAs($owner)->get('/app/products')
        ->assertSuccessful()
        ->assertSee($expected)
        // I18N-1 relies on a missing string *looking* missing, and a raw dotted
        // key on screen is what an unresolved lang line renders as.
        ->assertDontSee('catalog.product.');
})->with([
    ['el', 'Εκδρομή'],
    ['en', 'Trip'],
])->group('fast', 'i18n');
