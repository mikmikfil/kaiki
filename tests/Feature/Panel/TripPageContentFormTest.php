<?php

declare(strict_types=1);

use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Περιεχόμενο σελίδας εκδρομής» in the product form (2026-09-16)
|--------------------------------------------------------------------------
|
| «Τι θα ζήσετε», the programme, and what is and is not included and worth
| bringing. The owner's requirement is in the title of each test below: **all of
| it optional**. A trip saves with none of it; what is left empty is stored as a
| real null, which is what hides the section on the trip page and in the
| WordPress mirror; and a trip that has an itinerary with coordinates from an
| import keeps them when an operator edits the names in the panel.
|
*/

function tripContentTenant(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @param array<string, mixed> $params */
function tripContentPage(User $user, string $page, array $params = []): Testable
{
    tenancy()->initialize(tripContentTenant($user));

    return Livewire::actingAs($user)->test($page, $params);
}

/**
 * A per-seat draft with one adult band — the smallest trip the form saves.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripContentForm(array $overrides = []): array
{
    return array_merge([
        'title' => ['el' => 'Πρωινό κολυμβητικό', 'en' => 'Morning swim'],
        'slug' => 'morning-swim',
        'category' => ProductCategory::SharedHalfDay->value,
        'mode' => BookingMode::PerSeat->value,
        'status' => ProductStatus::Draft->value,
        'duration_minutes' => 240,
        'check_in_offset_minutes' => 30,
        'max_pax' => 12,
        'min_pax' => 0,
        'min_booking_pax' => 1,
        'guest_details_deadline_hours' => 48,
        'sort_order' => 0,
        'age_bands' => [[
            'code' => 'adult',
            'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'],
            'min_age' => 12,
            'max_age' => null,
            'counts_toward_capacity' => true,
            'pricing_mode' => AgeBandPricing::Multiplier->value,
            'price_multiplier_bp' => 10000,
            'is_base' => true,
            'requires_adult' => false,
        ]],
    ], $overrides);
}

it('saves a trip with every trip-page field left empty, and stores them as null', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    tripContentPage($owner, CreateProduct::class)
        ->fillForm(tripContentForm())
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(tripContentTenant($owner), function (): void {
        $product = Product::query()->firstOrFail();
        $raw = Product::query()->toBase()->where('id', $product->getKey())->first();

        // Real nulls, not `{"el": []}` and not `{"el": null}`: null is what
        // hides a section, and a form nobody touched has configured nothing.
        foreach (['highlights', 'includes', 'excludes', 'what_to_bring', 'itinerary_stops'] as $column) {
            expect($raw->{$column})->toBeNull();
        }
    });
})->group('fast');

/**
 * Lines as a simple repeater holds them before it dehydrates. `fillForm()` sets
 * state without hydrating it, so the test hands over the shape the component
 * works with; an operator's browser gets there through the form's own fill.
 *
 * @return list<array{value: string}>
 */
function tripLines(string ...$lines): array
{
    return array_map(static fn (string $line): array => ['value' => $line], $lines);
}

it('saves every trip-page field, both languages, blank lines dropped', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    tripContentPage($owner, CreateProduct::class)
        ->fillForm(tripContentForm([
            'highlights' => [
                'el' => tripLines('Τρεις στάσεις για μπάνιο', '   ', 'Μάσκες για όλους'),
                'en' => tripLines('Three swimming stops', 'Masks for everyone'),
            ],
            'includes' => ['el' => tripLines('Καφές και νερό'), 'en' => tripLines('Coffee and water')],
            'excludes' => ['el' => tripLines('Μεταφορά'), 'en' => []],
            'what_to_bring' => ['el' => [], 'en' => []],
            'itinerary_rows' => [
                ['key' => null, 'time' => '09:00', 'name' => ['el' => 'Επιβίβαση', 'en' => 'Boarding'], 'description' => ['el' => '', 'en' => '']],
                // No English name: the Greek one stands in rather than the save
                // being refused for a stop the operator plainly wrote.
                ['key' => null, 'time' => '', 'name' => ['el' => 'Μπάνιο', 'en' => ''], 'description' => ['el' => 'Όπου θέλετε', 'en' => '']],
                // An empty row an operator added and left: dropped.
                ['key' => null, 'time' => '', 'name' => ['el' => '', 'en' => ''], 'description' => ['el' => '', 'en' => '']],
            ],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(tripContentTenant($owner), function (): void {
        $product = Product::query()->firstOrFail();

        expect($product->getTranslations('highlights'))->toBe([
            'el' => ['Τρεις στάσεις για μπάνιο', 'Μάσκες για όλους'],
            'en' => ['Three swimming stops', 'Masks for everyone'],
        ])
            ->and($product->getTranslation('includes', 'en'))->toBe(['Coffee and water'])
            ->and($product->getTranslations('excludes'))->toBe(['el' => ['Μεταφορά'], 'en' => []])
            ->and($product->what_to_bring)->toBeNull()
            ->and($product->itineraryStopsFor('el'))->toBe([
                ['key' => 's1', 'name' => 'Επιβίβαση', 'description' => null, 'duration_minutes' => null, 'time' => '09:00'],
                ['key' => 's2', 'name' => 'Μπάνιο', 'description' => 'Όπου θέλετε', 'duration_minutes' => null],
            ])
            ->and($product->itineraryStopsFor('en')[1]['name'])->toBe('Μπάνιο');
    });
})->group('fast');

it('refuses a stop time that is not a clock time', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    tripContentPage($owner, CreateProduct::class)
        ->fillForm(tripContentForm([
            'itinerary_rows' => [
                ['key' => null, 'time' => '9 το πρωί', 'name' => ['el' => 'Επιβίβαση', 'en' => 'Boarding'], 'description' => ['el' => '', 'en' => '']],
            ],
        ]))
        ->call('create')
        ->assertHasFormErrors();

    Tenancy::forTenant(tripContentTenant($owner), function (): void {
        expect(Product::query()->count())->toBe(0);
    });
})->group('fast');

it('keeps an imported stop key and its coordinates when the operator edits the programme', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    // Sold per vessel, so the form's age-band rules have nothing to say.
    $product = Tenancy::forTenant(tripContentTenant($owner), fn (): Product => Product::factory()->withItinerary()->create([
        'mode' => BookingMode::PerVessel,
        'status' => ProductStatus::Draft,
    ]));

    $component = tripContentPage($owner, EditProduct::class, ['record' => $product->uuid]);

    // The form opens on one row per stop, both languages side by side.
    $rows = array_values($component->get('data.itinerary_rows'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['key'])->toBe('s1')
        ->and($rows[1]['name'])->toBe(['el' => 'Όρμος Βλυχάδα', 'en' => 'Vlychada Bay']);

    // A new stop first, then the second imported one renamed; the first
    // imported one removed.
    $component
        ->fillForm(['itinerary_rows' => [
            ['key' => null, 'time' => '08:30', 'name' => ['el' => 'Καφές', 'en' => 'Coffee'], 'description' => ['el' => '', 'en' => '']],
            [...$rows[1], 'name' => ['el' => 'Βλυχάδα', 'en' => 'Vlychada']],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::forTenant(tripContentTenant($owner), function () use ($product): void {
        $fresh = $product->fresh();

        // The new stop gets a fresh key — not `s1`, which still names the
        // removed stop's coordinates — and so no coordinates of its own.
        expect(array_column($fresh->itineraryStopsFor('en'), 'key'))->toBe(['s3', 's2'])
            ->and($fresh->itineraryStopsFor('en')[0]['time'])->toBe('08:30')
            ->and($fresh->itineraryStopsFor('en')[1]['name'])->toBe('Vlychada')
            // The renamed stop kept its coordinates; the removed stop's are gone.
            ->and($fresh->itineraryGeo())->toBe(['s2' => ['lat' => 37.6721, 'lng' => 23.441]]);
    });
})->group('fast');
