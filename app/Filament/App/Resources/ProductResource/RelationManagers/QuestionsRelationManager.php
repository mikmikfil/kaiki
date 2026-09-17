<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Domain\Booking\Support\TripQuestionForm;
use App\Enums\TripQuestionScope;
use App\Enums\TripQuestionType;
use App\Filament\Forms\TranslatableInput;
use App\Models\TripQuestion;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * «Ερωτήσεις» on the trip (product owner, 2026-09-17).
 *
 * The operator's own questions, answered at checkout — see
 * {@see TripQuestionForm} for the guest's side. A label in both languages, a
 * type, per person or per booking, required or not.
 *
 * ## Choices as lines
 *
 * A choice's options are typed one per line, in Greek and in English, rather
 * than as a repeater of two-tab inputs: three sizes is three lines, and a form
 * inside a form inside a modal is how an operator loses the thread. The two
 * lists must have the same number of lines, because line 2 in Greek is line 2
 * in English.
 *
 * Removing a question soft-deletes it; the answers already given keep their
 * own copy of it.
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $icon = 'heroicon-o-question-mark-circle';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catalog.question.title');
    }

    public function form(Form $form): Form
    {
        $isChoice = static fn (Get $get): bool => $get('type') === TripQuestionType::Choice->value;

        return $form->schema([
            TranslatableInput::text('label', __('catalog.question.label.label'), __('catalog.question.label.help'), maxLength: 160),

            Radio::make('type')
                ->label(__('catalog.question.type'))
                ->options(TripQuestionType::options())
                ->default(TripQuestionType::YesNo->value)
                ->inline()
                ->required()
                ->live()
                ->columnSpanFull(),

            Textarea::make('options_el')
                ->label(__('catalog.question.options_el'))
                ->helperText(__('catalog.question.options_help'))
                ->rows(4)
                ->required($isChoice)
                ->visible($isChoice),

            Textarea::make('options_en')
                ->label(__('catalog.question.options_en'))
                ->rows(4)
                ->required($isChoice)
                ->visible($isChoice),

            Radio::make('scope')
                ->label(__('catalog.question.scope'))
                ->options(TripQuestionScope::options())
                ->default(TripQuestionScope::PerBooking->value)
                ->inline()
                ->required()
                ->columnSpanFull(),

            Toggle::make('is_required')
                ->label(__('catalog.question.is_required'))
                ->default(false),

            Toggle::make('is_active')
                ->label(__('catalog.question.is_active'))
                ->default(true),

            TextInput::make('sort_order')
                ->label(__('catalog.question.sort_order'))
                ->numeric()
                ->minValue(0)
                ->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('catalog.question.title'))
            ->description(__('catalog.question.help'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->label(__('catalog.question.label.label'))
                    ->formatStateUsing(static fn (TripQuestion $record): string => (string) $record->label),
                TextColumn::make('type')
                    ->label(__('catalog.question.type'))
                    ->badge()
                    ->formatStateUsing(static fn (TripQuestionType $state): string => $state->label()),
                TextColumn::make('scope')
                    ->label(__('catalog.question.scope'))
                    ->formatStateUsing(static fn (TripQuestionScope $state): string => $state->label()),
                IconColumn::make('is_required')->label(__('catalog.question.is_required'))->boolean(),
                IconColumn::make('is_active')->label(__('catalog.question.is_active'))->boolean(),
            ])
            ->emptyStateHeading(__('catalog.question.empty'))
            ->emptyStateDescription(__('catalog.question.help'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('catalog.question.add'))
                    ->modalHeading(__('catalog.question.add'))
                    ->using(fn (array $data): TripQuestion => $this->persist(new TripQuestion, $data)),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(static function (array $data, TripQuestion $record): array {
                        $data['label'] = $record->getTranslations('label');
                        $data['options_el'] = implode("\n", array_column($record->options ?? [], 'el'));
                        $data['options_en'] = implode("\n", array_column($record->options ?? [], 'en'));

                        return $data;
                    })
                    ->using(fn (TripQuestion $record, array $data): TripQuestion => $this->persist($record, $data)),
                DeleteAction::make(),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private function persist(TripQuestion $record, array $data): TripQuestion
    {
        $options = null;

        if (($data['type'] ?? null) === TripQuestionType::Choice->value) {
            $el = self::lines($data['options_el'] ?? '');
            $en = self::lines($data['options_en'] ?? '');

            if ($el === [] || count($el) !== count($en)) {
                throw ValidationException::withMessages([
                    'mountedTableActionsData.0.options_en' => __('catalog.question.options_mismatch'),
                ]);
            }

            $options = array_map(static fn (string $g, string $e): array => ['el' => $g, 'en' => $e], $el, $en);
        }

        unset($data['options_el'], $data['options_en']);

        $record->fill([
            ...$data,
            'options' => $options,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'product_id' => $this->getOwnerRecord()->getKey(),
        ])->save();

        return $record;
    }

    /** @return list<string> */
    private static function lines(mixed $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', is_string($text) ? $text : '') ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
