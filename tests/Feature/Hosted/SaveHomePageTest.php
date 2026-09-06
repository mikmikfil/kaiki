<?php

declare(strict_types=1);

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Hosted\OperatorPage;

/**
 * A fresh operator, with the work run inside their tenancy.
 *
 * Named for this file rather than shared, because it returns the tenant so one
 * test can come back to it — {@see OperatorPage::as()} is the shared form.
 *
 * @param  callable(): void  $work
 */
function savingOperator(callable $work): Tenant
{
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, $work);

    return $tenant;
}

/*
|--------------------------------------------------------------------------
| #102: what the Action does with what the form sends
|--------------------------------------------------------------------------
|
| The editor is one caller. A seeder, an import and a future API are the others,
| and every guarantee this file asserts has to hold for all four — which is why
| the normalisation is in the Action and these tests never touch a form.
|
| The claim that matters most is the one about **replacement**: a save that
| diffed rows against form entries would need an id in the form, and a
| mismatched id writes one block's text over another's. The failure is silent
| and the evidence is the operator's own public page.
|
*/

it('stores the blocks in the order they were submitted', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([
            OperatorPage::input(HomeBlockType::Hero),
            OperatorPage::input(HomeBlockType::Trips),
            OperatorPage::input(HomeBlockType::Contact),
        ]);

        // `sort_order` is assigned from the array position rather than trusted
        // from the input: the form is a reorderable list, and the position in
        // the list *is* the order the operator chose.
        expect(HomePageBlock::query()->orderBy('sort_order')->pluck('type')->all())
            ->toBe([HomeBlockType::Hero, HomeBlockType::Trips, HomeBlockType::Contact]);
    });
});

it('replaces the whole page rather than merging into it', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Hero), OperatorPage::input(HomeBlockType::Story)]);

        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Contact)]);

        // Not three blocks, and not a hero with contact settings grafted on.
        expect(HomePageBlock::query()->count())->toBe(1)
            ->and(HomePageBlock::query()->first()?->type)->toBe(HomeBlockType::Contact);
    });
});

it('drops a block whose type is not one of the five', function (): void {
    savingOperator(function (): void {
        $stored = (new SaveHomePage)([
            OperatorPage::input(HomeBlockType::Hero),
            ['type' => 'html', 'heading' => ['el' => 'Κακό', 'en' => 'Bad']],
            ['type' => '', 'heading' => ['el' => 'Κενό', 'en' => 'Empty']],
        ]);

        // There is no sixth type, and a row that claimed one would break the
        // render of a page a guest is looking at — the template is chosen by
        // this string.
        expect($stored)->toBe(1)
            ->and(HomePageBlock::query()->pluck('type')->all())->toBe([HomeBlockType::Hero]);
    });
});

it('stores a blank heading as null rather than as empty strings', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Gallery, ['heading' => ['el' => '  ', 'en' => '']])]);

        // `{"el":"","en":""}` would make `$block->heading` an empty string, and
        // every `@if ($block->heading)` in the templates would render an empty
        // `<h2>`. Null is the value that means "there is no heading".
        expect(HomePageBlock::query()->first()?->getRawOriginal('heading'))->toBeNull();
    });
});

it('keeps a heading that exists in one locale only', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Story, ['heading' => ['el' => 'Μόνο ελληνικά', 'en' => '']])]);

        // The form's `TranslatableRequired` rule is where an operator is told
        // which tab is empty. The Action keeps what it was given rather than
        // discarding an operator's Greek because their English is not written
        // yet — the I18N-5 fallback chain is what serves the English page.
        expect(HomePageBlock::query()->first()?->getTranslations('heading'))->toBe(['el' => 'Μόνο ελληνικά']);
    });
});

it('does not store prose on a type that does not render it', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Trips, ['body' => ['el' => 'Κείμενο', 'en' => 'Text']])]);

        // A field a type does not read is not stored. Otherwise an operator who
        // changed a story into a trips block would keep invisible text that
        // reappears — with no warning — the day the trips template grows a body.
        expect(HomePageBlock::query()->first()?->body)->toBeNull();
    });
});

it('normalises settings rather than storing what it was handed', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Trips, [
            'settings' => [
                'source' => 'category',
                'category' => 'not-a-category',
                // A form posts strings; a JSON column hands them back as
                // strings; `strict_comparison` then never matches an integer.
                'limit' => '4',
                'colour' => 'chartreuse',
            ],
        ])]);

        $settings = HomePageBlock::query()->first()?->settings() ?? [];

        expect($settings['limit'])->toBe(4)
            // Unknown keys are dropped, so `settings` holds exactly the
            // documented keys for every row — including rows a seeder, an
            // import or a future API wrote.
            ->and($settings)->not->toHaveKey('colour')
            // Individually valid, jointly nonsense: an unrecognised category
            // would render an empty grid on somebody's home page.
            ->and($settings['category'])->toBeNull()
            ->and($settings['source'])->toBe(BlockSettings::SOURCE_ALL);
    });
});

it('keeps a gallery image that has no description yet', function (): void {
    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Gallery, [
            'images' => [
                ['path' => 'home/one.jpg', 'alt' => ['el' => 'Το καΐκι', 'en' => 'The kaiki']],
                ['path' => 'home/two.jpg'],
                ['path' => ''],
            ],
        ])]);

        $images = HomePageBlock::query()->firstOrFail()->images ?? [];

        // The one with no path is dropped; the one with no description is kept.
        // Refusing the save would mean an operator loses eight uploaded
        // photographs because they have not described the ninth yet.
        expect($images)->toHaveCount(2)
            ->and($images[1]['path'])->toBe('home/two.jpg')
            ->and($images[1]['alt'])->toBe([]);
    });
});

it('deletes only the current operator page', function (): void {
    $other = savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Hero)]);
    });

    savingOperator(function (): void {
        (new SaveHomePage)([OperatorPage::input(HomeBlockType::Story)]);
    });

    // The delete is tenant-scoped by `BelongsToTenant`. A save that reached
    // another operator's rows is the worst thing this Action could do, and it
    // is exactly what a `delete()` without the global scope would do.
    Tenancy::forTenant($other, function (): void {
        expect(HomePageBlock::query()->count())->toBe(1)
            ->and(HomePageBlock::query()->first()?->type)->toBe(HomeBlockType::Hero);
    });
});
