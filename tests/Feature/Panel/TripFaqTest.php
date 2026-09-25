<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Resources\ProductResource\Pages\EditProduct;
use App\Filament\App\Resources\ProductResource\RelationManagers\FaqsRelationManager;
use App\Models\Faq;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Συχνές ερωτήσεις» inside the trip (Mike, 2026-09-23)
|--------------------------------------------------------------------------
|
| *«όταν φτιάχνω εκδρομή δεν υπάρχει FAQ»*. The screen and the nullable
| `faqs.product_id` both existed; what was missing was the place an operator
| would look. The decision worth pinning is that an entry written here is
| **this trip's** — the relation supplies `product_id` and no field offers it,
| so it can never accidentally become a business-wide answer.
|
*/

it('writes a question against the trip it was opened on', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenant::query()->findOrFail($owner->tenant_id);
    tenancy()->initialize($tenant);

    $mine = Product::factory()->create();
    $other = Product::factory()->create();

    Livewire::actingAs($owner)
        ->test(FaqsRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => EditProduct::class])
        ->callTableAction('create', data: [
            'question' => ['el' => 'Σταματάει για μπάνιο;', 'en' => 'Does it stop for a swim?'],
            'answer' => ['el' => 'Ναι, σε δύο όρμους.', 'en' => 'Yes, at two bays.'],
            'is_published' => true,
        ])
        ->assertHasNoTableActionErrors();

    $faq = Faq::query()->sole();

    expect($faq->product_id)->toBe($mine->getKey())
        // Not the other trip, and not null — a null one is the business's and
        // would show on every trip, which is the mistake this screen removes.
        ->and($faq->product_id)->not->toBe($other->getKey())
        ->and($faq->getTranslation('question', 'el'))->toBe('Σταματάει για μπάνιο;');
})->group('fast');

it('lists only this trip\'s questions, not the business-wide ones', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    tenancy()->initialize(Tenant::query()->findOrFail($owner->tenant_id));

    $product = Product::factory()->create();

    Tenancy::forTenant($owner->tenant, function () use ($product): void {
        Faq::factory()->forProduct($product)->asking('Σταματάει για μπάνιο;', 'Swim stop?')->create();
        Faq::factory()->asking('Πού συναντιόμαστε;', 'Where do we meet?')->create();
    });

    Livewire::actingAs($owner)
        ->test(FaqsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->assertOk()
        // Asserted in English: a panel test runs in that locale.
        ->assertSee('Swim stop?', escape: false)
        // The business-wide one belongs on the FAQ screen in the menu, where
        // the trip picker is the point rather than a trap.
        ->assertDontSee('Where do we meet?', escape: false);
})->group('fast');
