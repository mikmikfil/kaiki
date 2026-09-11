<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Platform\Support\Announcements;
use App\Enums\AnnouncementSeverity;
use App\Filament\Admin\Resources\AnnouncementResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Models\PlatformAnnouncement;
use App\Policies\PlatformAnnouncementPolicy;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * The platform's announcement banner, written here (spec SAA-1).
 *
 * One message to every operator at once — a maintenance window, a change they
 * need to know about. It shows at the top of every `/app` page until it ends,
 * is switched off, or each person closes it; see
 * {@see Announcements} for which one is shown.
 *
 * The super-admin only, through {@see PlatformAnnouncementPolicy}. Operators
 * never reach this list — they only ever see the banner.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = PlatformAnnouncement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'announcements';

    public static function getNavigationLabel(): string
    {
        return __('platform.announcements.nav');
    }

    public static function getModelLabel(): string
    {
        return __('platform.announcements.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.announcements.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('platform.announcements.sections.message'))
                ->description(__('platform.announcements.sections.message_help'))
                ->schema([
                    TranslatableInput::textarea(
                        'message',
                        __('platform.announcements.form.message'),
                        __('platform.announcements.form.message_help'),
                        required: true,
                        rows: 3,
                    ),

                    Select::make('severity')
                        ->label(__('platform.announcements.form.severity'))
                        ->options(AnnouncementSeverity::options())
                        ->default(AnnouncementSeverity::Info->value)
                        ->required(),
                ]),

            Section::make(__('platform.announcements.sections.when'))
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('platform.announcements.form.starts_at'))
                        ->helperText(__('platform.announcements.form.starts_at_help'))
                        ->seconds(false)
                        ->native(false),

                    DateTimePicker::make('ends_at')
                        ->label(__('platform.announcements.form.ends_at'))
                        ->helperText(__('platform.announcements.form.ends_at_help'))
                        ->seconds(false)
                        ->native(false)
                        // A closure rather than `afterOrEqual('starts_at')`: that
                        // rule compares against the literal text "starts_at"
                        // when the start is left empty, which is the common case.
                        ->rule(static fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $start = $get('starts_at');

                            if (filled($value) && filled($start) && Carbon::parse((string) $value)->lt(Carbon::parse((string) $start))) {
                                $fail(__('platform.announcements.form.ends_before_start'));
                            }
                        }),

                    Toggle::make('is_active')
                        ->label(__('platform.announcements.form.is_active'))
                        ->helperText(__('platform.announcements.form.is_active_help'))
                        ->default(true),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('message')
                    ->label(__('platform.announcements.table.message'))
                    ->limit(90)
                    ->wrap(),

                TextColumn::make('severity')
                    ->label(__('platform.announcements.table.severity'))
                    ->badge()
                    ->formatStateUsing(fn (AnnouncementSeverity $state): string => $state->label())
                    ->color(fn (AnnouncementSeverity $state): string => $state === AnnouncementSeverity::Warning ? 'warning' : 'info'),

                TextColumn::make('starts_at')
                    ->label(__('platform.announcements.table.starts_at'))
                    ->dateTime()
                    ->placeholder(__('platform.announcements.table.from_now'))
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label(__('platform.announcements.table.ends_at'))
                    ->dateTime()
                    ->placeholder(__('platform.announcements.table.open_ended'))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('platform.announcements.table.is_active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('platform.announcements.table.created_at'))
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('platform.announcements.empty.heading'))
            ->emptyStateDescription(__('platform.announcements.empty.description'))
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
