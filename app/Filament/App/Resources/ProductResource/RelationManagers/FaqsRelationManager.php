<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Filament\Forms\TranslatableInput;
use App\Models\Faq;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Συχνές ερωτήσεις» inside the trip (Mike, 2026-09-23: *«όταν φτιάχνω εκδρομή
 * δεν υπάρχει FAQ»*).
 *
 * The FAQ screen has existed since #103 and `faqs.product_id` has been nullable
 * since the table was made — a null one is asked of the business and shows on
 * every trip, a filled one belongs to a sailing. What was missing is the place
 * an operator would actually look: writing *«Σταματάει για μπάνιο;»* meant
 * leaving the trip, opening a separate screen, and choosing the trip again from
 * a dropdown they had just been inside.
 *
 * ## The trip is not a field here
 *
 * `product_id` comes from the relation, exactly as it does for the schedules
 * and the extras. It cannot be wrong and it cannot be chosen — which is also
 * why an entry made here can never accidentally become a business-wide one.
 * The tenant-wide entries stay on {@see FaqResource}, where the dropdown is the
 * point rather than a trap.
 *
 * ## Order is dragged
 *
 * `sort_order`, the way the standalone screen does it and the way the guest
 * page reads it. An FAQ list is read top to bottom and the operator decides
 * which worry is answered first.
 */
class FaqsRelationManager extends RelationManager
{
    protected static string $relationship = 'faqs';

    protected static ?string $icon = 'heroicon-o-question-mark-circle';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('faq.on_product.title');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TranslatableInput::text(
                'question',
                __('faq.form.question.label'),
                __('faq.form.question.help'),
                maxLength: 200,
            ),

            // Required in both locales, like the standalone screen: a question
            // with no answer is not an entry, and an FAQ has no other content
            // to fall back on.
            TranslatableInput::textarea(
                'answer',
                __('faq.form.answer.label'),
                __('faq.form.answer.help'),
                required: true,
                rows: 6,
            ),

            Toggle::make('is_published')
                ->label(__('faq.form.is_published.label'))
                ->helperText(__('faq.form.is_published.help'))
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('faq.on_product.title'))
            ->description(__('faq.on_product.help'))
            ->columns([
                TextColumn::make('question')
                    ->label(__('faq.form.question.label'))
                    ->formatStateUsing(static fn (Faq $record): string => (string) $record->question)
                    ->wrap(),

                IconColumn::make('is_published')
                    ->label(__('faq.form.is_published.label'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->emptyStateHeading(__('faq.on_product.empty'))
            ->emptyStateDescription(__('faq.on_product.empty_help'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('faq.on_product.add'))
                    ->modalHeading(__('faq.on_product.add')),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
