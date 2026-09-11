<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Filament\App\Pages\HomePage;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\FaqResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\Faq;
use App\Models\Product;
use App\Policies\FaqPolicy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The operator's FAQ on `/app` (#103).
 *
 * ## A resource and not a page, unlike the home page
 *
 * {@see HomePage} is a page because there is one home
 * page. There are many FAQ entries, they are created and deleted one at a time,
 * and an operator thinks of them as a list — so this is the ordinary list,
 * create and edit Filament gives for a table, and it inherits the filters and
 * the policy refusals that come with it.
 *
 * ## Ordering is drag-and-drop, on a plain integer column
 *
 * `sort_order` is what the guest surfaces read, and a number field would make an
 * operator do arithmetic to move the most-asked question to the top. The table's
 * own reordering writes that column directly — which is also why
 * {@see FaqPolicy} has a `reorder` method: Filament asks the policy
 * for it, and a policy without one silently hides the handles rather than
 * failing.
 *
 * ## Nothing here sorts or filters on a JSON path
 *
 * `question` and `answer` are translatable JSON. The column is displayed and
 * never ordered or searched — the table is sorted by the operator's own
 * `sort_order`, the realistic list is eight rows, and `NoJsonPathQueryTest`
 * fails the build for the alternative. {@see Faq} records why the model has no
 * ADR-0008 companion columns at all.
 */
class FaqResource extends Resource
{
    protected static ?string $model = Faq::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 89;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('faq.nav');
    }

    public static function getModelLabel(): string
    {
        return __('faq.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('faq.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('faq.sections.entry'))
                ->schema([
                    TranslatableInput::text(
                        'question',
                        __('faq.form.question.label'),
                        __('faq.form.question.help'),
                        maxLength: 200,
                    ),

                    // Required in both locales, unlike the home page's body: a
                    // question with no answer is not an entry, and an FAQ has no
                    // other content to fall back on.
                    TranslatableInput::textarea(
                        'answer',
                        __('faq.form.answer.label'),
                        __('faq.form.answer.help'),
                        required: true,
                        rows: 6,
                    ),
                ]),

            Section::make(__('faq.sections.where'))
                ->schema([
                    // Null is the default and the common case. The placeholder
                    // says what null means in the operator's own terms, because
                    // "no product" is not a sentence anybody would write.
                    Select::make('product_id')
                        ->label(__('faq.form.product.label'))
                        ->helperText(__('faq.form.product.help'))
                        ->placeholder(__('faq.form.product.all'))
                        ->options(fn (): array => static::productOptions())
                        ->native(false)
                        ->searchable(),

                    Toggle::make('is_published')
                        ->label(__('faq.form.is_published.label'))
                        ->helperText(__('faq.form.is_published.help'))
                        ->default(true),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label(__('faq.table.question'))
                    ->wrap()
                    ->limit(90),

                // The trip an entry is about, or the sentence that says it is
                // about all of them. An empty cell would read as missing data.
                TextColumn::make('product.title')
                    ->label(__('faq.table.product'))
                    ->placeholder(__('faq.form.product.all'))
                    ->limit(40)
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label(__('faq.table.is_published'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            // Drag-and-drop, writing `sort_order`. See the class docblock for
            // why the policy needs `reorder` before these handles appear.
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('product_id')
                    ->label(__('faq.table.product'))
                    ->options(fn (): array => static::productOptions()),

                TernaryFilter::make('is_published')
                    ->label(__('faq.form.is_published.label')),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('faq.empty.heading'))
            ->emptyStateDescription(__('faq.empty.body'));
    }

    /**
     * The operator's trips, by resolved title.
     *
     * `get()` and then `mapWithKeys()` rather than `pluck('title', 'id')`:
     * `title` is a translatable JSON column, and plucking it hands back the raw
     * JSON document as the label — the whole two-locale object, printed in a
     * select. Going through the model runs the accessor and the I18N-5 fallback.
     *
     * @return array<int, string>
     */
    public static function productOptions(): array
    {
        return Product::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
            ->all();
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFaqs::route('/'),
            'create' => Pages\CreateFaq::route('/create'),
            'edit' => Pages\EditFaq::route('/{record}/edit'),
        ];
    }
}
