<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PolicyTemplateResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\PolicyTemplate;
use App\Policies\PolicyTemplatePolicy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The cancellation ladders a new operator chooses between (Mike, 2026-09-23:
 * *«να μπορώ να τις επεξεργαστώ ως admin τις επιλογές που τους δίνω»*).
 *
 * Lives in `/admin` and nowhere else — {@see PolicyTemplatePolicy} refuses an
 * operator even the read, because an operator does not browse this table. They
 * see the cards the setup guide draws from it, and choosing one writes them a
 * cancellation policy of their own.
 *
 * ## What an edit here does, and what it deliberately does not
 *
 * It changes what the **next** operator is offered. It does not reach back into
 * anyone who already chose: their policy is a copy, their terms are already in
 * guests' confirmation emails, and rewriting those retroactively is the one
 * thing this screen must never do. The section description says so, because a
 * super-admin editing «Κανονική» would otherwise reasonably assume it does.
 *
 * ## The order is dragged, and it is the order on screen
 *
 * `sort_order`, reordered in the table the way trips and boats are. A new
 * operator reads these three cards left to right on the most decisive screen of
 * their first afternoon, so which one sits first is a real choice and not a
 * listing preference.
 *
 * ## Retire before delete
 *
 * `is_active` takes a template off the menu while everyone who took it keeps
 * their copy. Delete exists for a row added by mistake — and a shipped code
 * deleted this way comes back on the next `db:seed`, which the seeder explains.
 */
class PolicyTemplateResource extends Resource
{
    protected static ?string $model = PolicyTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('policy_templates.nav');
    }

    public static function getModelLabel(): string
    {
        return __('policy_templates.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('policy_templates.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('policy_templates.sections.identity'))
                // What a super-admin is about to assume, said before they
                // assume it: this is the menu for the next operator, not a
                // lever over the ones already trading.
                ->description(__('policy_templates.sections.identity_help'))
                ->schema([
                    TextInput::make('code')
                        ->label(__('policy_templates.form.code.label'))
                        ->helperText(__('policy_templates.form.code.help'))
                        ->required()
                        ->maxLength(32)
                        ->alphaDash()
                        ->unique(ignoreRecord: true),

                    Toggle::make('is_active')
                        ->label(__('policy_templates.form.is_active.label'))
                        ->helperText(__('policy_templates.form.is_active.help'))
                        ->default(true),

                    TranslatableInput::text(
                        'name',
                        __('policy_templates.form.name.label'),
                        __('policy_templates.form.name.help'),
                        maxLength: 60,
                    ),

                    TranslatableInput::text(
                        'summary',
                        __('policy_templates.form.summary.label'),
                        __('policy_templates.form.summary.help'),
                        maxLength: 120,
                        required: false,
                    ),
                ])
                ->columns(2),

            Section::make(__('policy_templates.sections.ladder'))
                ->description(__('policy_templates.sections.ladder_help'))
                ->schema([
                    TextInput::make('free_cancellation_hours')
                        ->label(__('policy_templates.form.free_cancellation_hours.label'))
                        ->helperText(__('policy_templates.form.free_cancellation_hours.help'))
                        ->suffix(__('policy_templates.form.free_cancellation_hours.suffix'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(8760),

                    Repeater::make('tiers')
                        ->label(__('policy_templates.form.tiers.label'))
                        ->helperText(__('policy_templates.form.tiers.help'))
                        ->addActionLabel(__('policy_templates.form.tiers.add'))
                        ->schema([
                            TextInput::make('days_before')
                                ->label(__('policy_templates.form.tiers.days_before'))
                                ->integer()
                                ->required()
                                ->minValue(0)
                                ->maxValue(3650)
                                // One rule per threshold, matching the unique
                                // index the operator's copy will be written
                                // into — two rows at seven days would be two
                                // answers to one question, and only one of them
                                // would survive the copy.
                                ->distinct(),

                            TextInput::make('refund_percent')
                                ->label(__('policy_templates.form.tiers.refund_percent'))
                                ->suffix('%')
                                ->integer()
                                ->required()
                                ->minValue(0)
                                ->maxValue(100),
                        ])
                        ->itemLabel(static fn (array $state): ?string => isset($state['days_before'], $state['refund_percent'])
                            ? __('policy_templates.form.tiers.item', [
                                'days' => $state['days_before'],
                                'percent' => $state['refund_percent'],
                            ])
                            : null)
                        ->defaultItems(0)
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('policy_templates.table.name'))
                    ->formatStateUsing(static fn (PolicyTemplate $record): string => (string) $record->name),

                TextColumn::make('summary')
                    ->label(__('policy_templates.table.summary'))
                    ->formatStateUsing(static fn (PolicyTemplate $record): string => (string) $record->summary)
                    ->placeholder('—')
                    ->wrap(),

                TextColumn::make('tiers')
                    ->label(__('policy_templates.table.ladder'))
                    // The ladder as the operator will read it, from the same
                    // method the setup card uses — so this column cannot say
                    // something the card does not.
                    ->formatStateUsing(static fn (PolicyTemplate $record): string => collect($record->ladder())
                        ->map(static fn (array $line): string => $line['when'] . ' · ' . $line['refund'])
                        ->implode(' — '))
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label(__('policy_templates.table.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            // Dragged, not typed — the same decision the trip and boat lists
            // took, and here the order is what a new operator reads first.
            ->reorderable('sort_order')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPolicyTemplates::route('/'),
            'create' => Pages\CreatePolicyTemplate::route('/create'),
            'edit' => Pages\EditPolicyTemplate::route('/{record}/edit'),
        ];
    }
}
